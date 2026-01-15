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

header('Content-Type: application/json');

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Require finance roles
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$creditNoteId    = isset($input['credit_note_id']) ? (int)$input['credit_note_id'] : 0;
$idempotencyKey  = isset($input['idempotency_key']) ? trim((string)$input['idempotency_key']) : '';

if ($creditNoteId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Credit note ID required']);
    exit;
}

try {
    // Fetch credit note details
    $stmt = $DB->prepare(
        "SELECT id, issue_date, status, journal_id FROM credit_notes WHERE id = ? AND company_id = ? LIMIT 1"
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
    echo json_encode(['ok' => true, 'data' => ['journal_id' => $journalId]]);
    exit;
} catch (Exception $e) {
    error_log('AR post credit note error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
