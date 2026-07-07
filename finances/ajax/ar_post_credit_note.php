<?php
// /finances/ajax/ar_post_credit_note.php
//
// Endpoint to post an approved credit note to the general ledger. It
// validates user roles, checks period locks, delegates posting to
// ArService/PostingService and records the resulting journal id back to
// the credit_notes table. Duplicate postings are prevented.

// Dynamically require init, auth, and permissions. Determine the root directory
// two levels up and attempt to load from /app if it exists; otherwise fall
// back to root-level files.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/ArService.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';

require_method('POST');
Csrf::validate();

header('Content-Type: application/json');

// Require finance roles
requireRoles(['admin', 'bookkeeper']);

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$creditNoteId    = isset($input['credit_note_id']) ? (int)$input['credit_note_id'] : 0;
$idempotencyKey  = isset($input['idempotency_key']) ? trim((string)$input['idempotency_key']) : '';
// Callers may classify at post time; matches the credit_notes.reason_code ENUM
$reasonCodeIn    = isset($input['reason_code']) ? strtolower(trim((string)$input['reason_code'])) : '';
$validReasonCodes = ['return', 'discount', 'correction', 'damaged', 'cancellation', 'vat_adjustment', 'other'];

if ($creditNoteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Credit note ID required']);
    exit;
}

try {
    // Fetch credit note details
    $stmt = $DB->prepare(
        "SELECT id, issue_date, status, journal_id, reason_code, invoice_id FROM credit_notes WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$creditNoteId, $companyId]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cn) {
        echo json_encode(['ok' => false, 'error' => 'Credit note not found']);
        exit;
    }
    // Ensure credit note is approved or applied
    $status = strtolower((string)$cn['status']);
    if (!in_array($status, ['approved', 'applied'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Credit note not approved or applied']);
        exit;
    }
    // SARS s21 compliance: a credit note may not post without a classified
    // reason code. The code may already be stored on the record, or supplied
    // in this request (the QI capture flows predate the reason_code column,
    // so posting must be able to classify in-flight and persist it).
    if (empty($cn['reason_code'])) {
        if ($reasonCodeIn === '' || !in_array($reasonCodeIn, $validReasonCodes, true)) {
            echo json_encode([
                'ok' => false,
                'error' => 'Credit note reason code is required before posting (SARS s21). Supply reason_code as one of: ' . implode(', ', $validReasonCodes),
            ]);
            exit;
        }
        $stmtRc = $DB->prepare("UPDATE credit_notes SET reason_code = ? WHERE id = ? AND company_id = ?");
        $stmtRc->execute([$reasonCodeIn, $creditNoteId, $companyId]);
        $cn['reason_code'] = $reasonCodeIn;
    }
    // A link to the original tax invoice is required where the note corrects
    // a specific invoice; standalone notes are permitted but flagged so the
    // bookkeeper can attach the reference (SARS s21(3)).
    $s21Warning = empty($cn['invoice_id'])
        ? 'Credit note is not linked to an original invoice — attach the reference if it corrects a specific tax invoice (SARS s21)'
        : null;
    // Duplicate check: if journal_id already set, treat as duplicate
    if (!empty($cn['journal_id'])) {
        echo json_encode([
            'ok' => false,
            'duplicate' => true,
            'error' => 'Credit note already posted'
        ]);
        exit;
    }
    // Period lock check
    $issueDate = $cn['issue_date'] ?: date('Y-m-d');
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($issueDate)) {
        echo json_encode(['ok' => false, 'error' => 'Cannot post credit note to locked period (' . $issueDate . ')']);
        exit;
    }
    // Idempotency: optionally treat idempotency key as unique reference in journal reference
    // Not persisted here; rely on journal_id duplicate check
    // Post credit note via ArService
    $arService = new ArService($DB, $companyId, $userId);
    $arService->postCreditNote($creditNoteId);
    // Fetch new journal id: look up latest journal entry for this credit note
    $stmt = $DB->prepare(
        "SELECT id FROM journal_entries WHERE company_id = ? AND ref_type = 'credit_note' AND ref_id = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$companyId, $creditNoteId]);
    $journalId = (int)$stmt->fetchColumn();
    if ($journalId) {
        // Update credit note record with journal id
        $upd = $DB->prepare("UPDATE credit_notes SET journal_id = ? WHERE id = ? AND company_id = ?");
        $upd->execute([$journalId, $creditNoteId, $companyId]);
    }
    // Audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'ar_credit_note_posted', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['credit_note_id' => $creditNoteId, 'journal_id' => $journalId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $resp = ['ok' => true, 'data' => ['journal_id' => $journalId]];
    if ($s21Warning !== null) {
        $resp['warning'] = $s21Warning;
    }
    echo json_encode($resp);
    exit;
} catch (Exception $e) {
    error_log('AR post credit note error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to post credit note']);
    exit;
}
