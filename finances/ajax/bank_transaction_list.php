<?php
// /finances/ajax/bank_transaction_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            bt.*,
            ba.name as bank_account_name
        FROM gl_bank_transactions bt
        JOIN gl_bank_accounts ba ON bt.bank_account_id = ba.id
        WHERE bt.company_id = ?
        ORDER BY bt.tx_date DESC, bt.bank_tx_id DESC
        LIMIT 200
    ");
    $stmt->execute([$companyId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $transactions
    ]);

} catch (Exception $e) {
    error_log("Bank transaction list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load transactions'
    ]);
}