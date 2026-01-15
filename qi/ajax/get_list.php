<?php
// /qi/ajax/get_list.php - COMPLETE WORKING
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$type = $_GET['type'] ?? 'quotes';

try {
    if ($type === 'quotes') {
        $stmt = $DB->prepare("
            SELECT q.id, q.quote_number, q.issue_date, q.expiry_date, q.total, q.status, q.created_at,
                   ca.name AS customer_name
            FROM quotes q
            LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
            WHERE q.company_id = ?
            ORDER BY q.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'invoices') {
        $stmt = $DB->prepare("
            SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.total, i.status, i.created_at,
                   ca.name AS customer_name
            FROM invoices i
            LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
            WHERE i.company_id = ?
            ORDER BY i.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $items = [];
    }

    echo json_encode(['ok' => true, 'items' => $items]);

} catch (Exception $e) {
    error_log("QI get_list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}