<?php
// /finances/ajax/journal_save.php — Create or update a draft journal entry
// SARS-compliant: audit trail, balance validation (cents-precise), account restrictions
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);

$isUpdate   = !empty($input['journal_id']);
$journalId  = $isUpdate ? (int)$input['journal_id'] : null;
$entryDate  = trim($input['entry_date'] ?? '');
$reference  = trim($input['reference'] ?? '');
$memo       = trim($input['memo'] ?? '');
$lines      = $input['lines'] ?? [];

// --- Validation ---
if (!$entryDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
    json_error('Valid entry date is required');
}

// Verify the date is a real calendar date
$dateObj = DateTime::createFromFormat('Y-m-d', $entryDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $entryDate) {
    json_error('Invalid calendar date: ' . $entryDate);
}

if (!is_array($lines) || count($lines) < 2) {
    json_error('Journal must have at least 2 lines');
}

$totalDebitCents  = 0;
$totalCreditCents = 0;
foreach ($lines as $i => $line) {
    $code = trim($line['account_code'] ?? '');
    if (!$code) {
        json_error('Line ' . ($i + 1) . ': account code is required');
    }
    $debit  = round((float)($line['debit'] ?? 0), 2);
    $credit = round((float)($line['credit'] ?? 0), 2);
    if ($debit < 0 || $credit < 0) {
        json_error('Line ' . ($i + 1) . ': amounts cannot be negative');
    }
    if ($debit == 0 && $credit == 0) {
        json_error('Line ' . ($i + 1) . ': debit or credit is required');
    }
    if ($debit > 0 && $credit > 0) {
        json_error('Line ' . ($i + 1) . ': a line cannot have both debit and credit');
    }
    // Use integer cents for precise comparison (SARS requirement)
    $totalDebitCents  += (int)round($debit * 100);
    $totalCreditCents += (int)round($credit * 100);
}

if ($totalDebitCents !== $totalCreditCents) {
    json_error('Journal is not balanced: debits (' . number_format($totalDebitCents / 100, 2) . ') != credits (' . number_format($totalCreditCents / 100, 2) . ')');
}

// Cap field lengths
if (mb_strlen($reference) > 100) $reference = mb_substr($reference, 0, 100);
if (mb_strlen($memo) > 255) $memo = mb_substr($memo, 0, 255);

// Validate account codes exist and allow manual journals
$accountCodes = array_unique(array_map(fn($l) => trim($l['account_code'] ?? ''), $lines));
$placeholders = implode(',', array_fill(0, count($accountCodes), '?'));
$stmt = $DB->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_code IN ($placeholders) AND is_active = 1 AND allow_manual_journal = 1");
$stmt->execute(array_merge([$companyId], array_values($accountCodes)));
$validCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
$invalidCodes = array_diff($accountCodes, $validCodes);
if (!empty($invalidCodes)) {
    json_error('Invalid or restricted account code(s): ' . implode(', ', $invalidCodes));
}

try {
    $DB->beginTransaction();

    if ($isUpdate && $journalId) {
        // --- UPDATE existing draft ---
        $stmt = $DB->prepare("SELECT status, reference, description, entry_date FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $companyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { throw new Exception('Journal not found'); }
        if ($existing['status'] !== 'draft') {
            throw new Exception('Only draft journals can be edited');
        }

        $stmt = $DB->prepare("
            UPDATE journal_entries
            SET entry_date = ?, reference = ?, description = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$entryDate, $reference, $memo, $journalId, $companyId]);

        // Delete old lines and re-insert
        $stmt = $DB->prepare("DELETE FROM journal_lines WHERE journal_id = ?");
        $stmt->execute([$journalId]);

    } else {
        // --- INSERT new journal ---
        // Auto-generate reference if not provided (SARS sequential numbering).
        // Uses the FOR UPDATE-locked Sequence — the previous MAX()+1 query
        // could hand two concurrent saves the same number.
        if (!$reference) {
            require_once __DIR__ . '/../lib/Sequence.php';
            $seq = new Sequence($DB, $companyId);
            $reference = $seq->issue('JNL', ['prefix' => 'JNL-', 'pad' => 4, 'period_key' => 'ALL']);
        }

        $stmt = $DB->prepare("
            INSERT INTO journal_entries
                (company_id, entry_date, reference, description, module, source_type, status, created_by, created_at)
            VALUES (?, ?, ?, ?, 'manual', 'manual', 'draft', ?, NOW())
        ");
        $stmt->execute([$companyId, $entryDate, $reference, $memo, $userId]);
        $journalId = (int)$DB->lastInsertId();
    }

    // --- Insert lines ---
    $lineStmt = $DB->prepare("
        INSERT INTO journal_lines
            (journal_id, account_code, description, debit, credit)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($lines as $line) {
        $lineStmt->execute([
            $journalId,
            trim($line['account_code']),
            trim($line['description'] ?? ''),
            round((float)($line['debit'] ?? 0), 2),
            round((float)($line['credit'] ?? 0), 2)
        ]);
    }

    // --- Audit (SARS: full trail with amounts and accounts) ---
    require_once __DIR__ . '/../lib/Audit.php';
    $auditDetails = [
        'journal_id'   => $journalId,
        'entry_date'   => $entryDate,
        'reference'    => $reference,
        'total_amount' => number_format($totalDebitCents / 100, 2),
        'line_count'   => count($lines),
        'accounts'     => array_values($accountCodes)
    ];
    if ($isUpdate) {
        $auditDetails['changes'] = [
            'entry_date' => [$existing['entry_date'], $entryDate],
            'reference'  => [$existing['reference'], $reference],
            'memo'       => [$existing['description'], $memo]
        ];
    }
    Audit::log($isUpdate ? 'journal_updated' : 'journal_created', $auditDetails);

    $DB->commit();

    echo json_encode(['ok' => true, 'data' => ['journal_id' => $journalId, 'reference' => $reference]]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Journal save error: " . $e->getMessage());
    json_exception($e);
}
