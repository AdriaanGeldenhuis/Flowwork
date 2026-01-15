<?php
// /finances/ajax/bank_match_transaction.php
// Matches an individual bank transaction to a GL account by creating a journal entry.
// Only finance roles (admin/bookkeeper) may perform this operation. The endpoint
// prevents matching a transaction that has already been matched or that falls
// into a locked accounting period.

// Dynamically include init and auth. Use /app directory if it exists, otherwise root-level files.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}
require_once __DIR__ . '/../lib/PeriodService.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Check user role
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Decode input
$input = json_decode(file_get_contents('php://input'), true);
$bankTxId = $input['bank_tx_id'] ?? null;
$accountCodeInput = isset($input['account_code']) ? trim($input['account_code']) : '';

if (!$bankTxId || !$accountCodeInput) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $DB->beginTransaction();
    // Retrieve the bank transaction along with the bank GL account id
    $stmt = $DB->prepare(
        "SELECT bt.*, ba.gl_account_id AS bank_gl_account_id
         FROM gl_bank_transactions bt
         JOIN gl_bank_accounts ba ON bt.bank_account_id = ba.id
         WHERE bt.bank_tx_id = ? AND bt.company_id = ?"
    );
    $stmt->execute([$bankTxId, $companyId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new Exception('Transaction not found');
    }
    // Check for locked period
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($tx['tx_date'])) {
        throw new Exception('Cannot match transaction to locked period (' . $tx['tx_date'] . ')');
    }
    // Ensure not already matched
    if (!empty($tx['matched']) && intval($tx['matched']) === 1) {
        throw new Exception('Transaction already matched');
    }
    // Validate the other side account code exists
    $stmt = $DB->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_code = ?");
    $stmt->execute([$companyId, $accountCodeInput]);
    $validatedAccountCode = $stmt->fetchColumn();
    if (!$validatedAccountCode) {
        throw new Exception('Account code not found');
    }
    // Create a journal entry for this match
    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description, module, ref_type, ref_id,
            source_type, source_id, created_by, created_at
         ) VALUES (?, ?, ?, ?, 'fin', 'bank_tx', ?, 'bank_tx', ?, ?, NOW())"
    );
    $reference = 'BANKTX' . $bankTxId;
    $description = $tx['description'] ?: 'Bank transaction';
    $stmt->execute([
        $companyId,
        $tx['tx_date'],
        $reference,
        $description,
        $bankTxId,
        $bankTxId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Determine the transaction amount as a positive decimal
    $amount = abs(intval($tx['amount_cents'])) / 100;
    // Determine the GL account code for the bank account if available
    $bankAccountCode = null;
    if (!empty($tx['bank_gl_account_id'])) {
        $lookup = $DB->prepare("SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        $lookup->execute([$tx['bank_gl_account_id'], $companyId]);
        $bankAccountCode = $lookup->fetchColumn() ?: null;
    }
    // Insert journal lines (debits/credits)
    $lineStmt = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, supplier_id, customer_id, reference) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)"
    );
    if (intval($tx['amount_cents']) > 0) {
        // Money IN: Dr bank, Cr other
        if ($bankAccountCode) {
            $lineStmt->execute([$journalId, $bankAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, $reference]);
        }
        $lineStmt->execute([$journalId, $validatedAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), $reference]);
    } else {
        // Money OUT: Dr other, Cr bank
        $lineStmt->execute([$journalId, $validatedAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, $reference]);
        if ($bankAccountCode) {
            $lineStmt->execute([$journalId, $bankAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), $reference]);
        }
    }
    // Mark the bank transaction as matched and store journal id
    $stmt = $DB->prepare(
        "UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ?"
    );
    $stmt->execute([$journalId, $bankTxId, $companyId]);
    // Audit log
    $audit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'bank_tx_matched', ?, ?, NOW())"
    );
    $audit->execute([
        $companyId,
        $userId,
        json_encode(['bank_tx_id' => $bankTxId, 'journal_id' => $journalId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true, 'journal_id' => $journalId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bank match error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}