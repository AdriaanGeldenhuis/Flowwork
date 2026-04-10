<?php
// /finances/ajax/bank_transaction_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Role check
$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper', 'viewer'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Filter params
$bankAccountId = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : 0;
$matched = isset($_GET['matched']) && $_GET['matched'] !== '' ? (int)$_GET['matched'] : null;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
$offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;

try {
    $where = ['bt.company_id = ?'];
    $params = [$companyId];

    if ($bankAccountId > 0) {
        $where[] = 'bt.bank_account_id = ?';
        $params[] = $bankAccountId;
    }
    if ($matched !== null) {
        $where[] = 'bt.matched = ?';
        $params[] = $matched;
    }
    if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'bt.tx_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'bt.tx_date <= ?';
        $params[] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    // Total count
    $countStmt = $DB->prepare("
        SELECT COUNT(*) FROM gl_bank_transactions bt WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch page
    $dataParams = array_merge($params, [$limit, $offset]);
    $stmt = $DB->prepare("
        SELECT
            bt.*,
            ba.name as bank_account_name
        FROM gl_bank_transactions bt
        JOIN gl_bank_accounts ba ON bt.bank_account_id = ba.id
        WHERE {$whereSql}
        ORDER BY bt.tx_date DESC, bt.bank_tx_id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($dataParams);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $transactions,
        'total' => $total
    ]);

} catch (Exception $e) {
    error_log("Bank transaction list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load transactions'
    ]);
}
