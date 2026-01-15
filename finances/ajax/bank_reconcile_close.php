<?php
// /finances/ajax/bank_reconcile_close.php
// Closes a bank reconciliation period for a specific bank account by
// updating the last_reconciled_date and optionally capturing a closing
// balance. After closing, the endpoint reports how many unmatched
// transactions remain on or before the statement date. Only finance
// administrators (admin/bookkeeper) may perform this action.

// Load init and auth from either /app or project root.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}

header('Content-Type: application/json');

// Require POST method
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

// Decode input JSON
$input = json_decode(file_get_contents('php://input'), true);
$bankAccountId   = isset($input['bank_account_id']) ? (int)$input['bank_account_id'] : 0;
$statementDate   = $input['statement_date'] ?? null;
$closingBalance  = isset($input['closing_balance']) ? (float)$input['closing_balance'] : null;

if (!$bankAccountId || !$statementDate) {
    echo json_encode(['ok' => false, 'error' => 'Missing bank_account_id or statement_date']);
    exit;
}

try {
    $DB->beginTransaction();
    // Verify the bank account exists for this company
    $stmt = $DB->prepare("SELECT last_reconciled_date FROM gl_bank_accounts WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$bankAccountId, $companyId]);
    $acct = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acct) {
        throw new Exception('Bank account not found');
    }
    // Convert statement date to Y-m-d and check not earlier than existing last_reconciled_date
    $newDate = date('Y-m-d', strtotime($statementDate));
    $prevDate = $acct['last_reconciled_date'];
    if ($prevDate && strtotime($newDate) <= strtotime($prevDate)) {
        throw new Exception('Statement date must be after the last reconciled date');
    }
    // Update the bank account with the new reconciled date and optional closing balance
    if ($closingBalance !== null) {
        // Update current_balance_cents to closing balance (converted to cents)
        $balanceCents = (int)round($closingBalance * 100);
        $stmtUpd = $DB->prepare("UPDATE gl_bank_accounts SET last_reconciled_date = ?, current_balance_cents = ? WHERE id = ? AND company_id = ?");
        $stmtUpd->execute([$newDate, $balanceCents, $bankAccountId, $companyId]);
    } else {
        $stmtUpd = $DB->prepare("UPDATE gl_bank_accounts SET last_reconciled_date = ? WHERE id = ? AND company_id = ?");
        $stmtUpd->execute([$newDate, $bankAccountId, $companyId]);
    }
    // Count unmatched transactions up to and including the statement date
    $stmtCount = $DB->prepare(
        "SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND matched = 0 AND tx_date <= ?"
    );
    $stmtCount->execute([$companyId, $bankAccountId, $newDate]);
    $unmatchedCount = (int)$stmtCount->fetchColumn();
    // Insert an audit log entry
    $stmtAudit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'bank_reconcile_close', ?, ?, NOW())"
    );
    $details = [
        'bank_account_id'  => $bankAccountId,
        'statement_date'   => $newDate,
        'closing_balance'  => $closingBalance,
        'unmatched_count'  => $unmatchedCount
    ];
    $stmtAudit->execute([
        $companyId,
        $userId,
        json_encode($details),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true, 'unmatched' => $unmatchedCount]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bank reconcile close error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}