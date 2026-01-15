<?php
// /finances/ajax/bank_undo_match.php
// Reverses a previously matched bank transaction by deleting its journal entry
// and resetting the matched flag. This endpoint checks period locks to prevent
// modifications to locked periods. Only admin or bookkeeper roles may undo.

// Load init and auth flexibly based on project structure.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/ReversalService.php';

header('Content-Type: application/json');

// Require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Authorise user
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Decode input
$input    = json_decode(file_get_contents('php://input'), true);
$bankTxId = isset($input['bank_tx_id']) ? $input['bank_tx_id'] : null;

if (!$bankTxId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bank_tx_id']);
    exit;
}

try {
    $DB->beginTransaction();
    // Fetch the bank transaction
    $stmt = $DB->prepare(
        "SELECT * FROM gl_bank_transactions WHERE bank_tx_id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$bankTxId, $companyId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new Exception('Transaction not found');
    }
    if (empty($tx['matched']) || intval($tx['matched']) === 0 || empty($tx['journal_id'])) {
        throw new Exception('Transaction is not matched');
    }
    $periodService = new PeriodService($DB, $companyId);
    // Do not allow undo in locked period
    if ($periodService->isLocked($tx['tx_date'])) {
        throw new Exception('Cannot undo transaction in locked period (' . $tx['tx_date'] . ')');
    }
    $journalId = (int)$tx['journal_id'];
    // Fetch journal entry date to double-check lock
    $stmt = $DB->prepare(
        "SELECT entry_date FROM journal_entries WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$journalId, $companyId]);
    $entryDate = $stmt->fetchColumn();
    if ($entryDate && $periodService->isLocked($entryDate)) {
        throw new Exception('Cannot undo journal in locked period (' . $entryDate . ')');
    }
    // Reverse journal instead of deleting lines
    $rev = new ReversalService($DB, $companyId);
    $rev->reverseJournal((int)$journalId, 'Bank undo match');
    // Reset the bank transaction matched flag and journal_id
    $stmt = $DB->prepare(
        "UPDATE gl_bank_transactions SET matched = 0, journal_id = NULL WHERE bank_tx_id = ? AND company_id = ?"
    );
    $stmt->execute([$bankTxId, $companyId]);
    // Audit log
    $audit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'bank_tx_unmatched', ?, ?, NOW())"
    );
    $audit->execute([
        $companyId,
        $userId,
        json_encode(['bank_tx_id' => $bankTxId, 'journal_id' => $journalId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bank undo match error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}