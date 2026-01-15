<?php
// /finances/ajax/ar_invoice_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            i.id,
            i.invoice_number,
            i.customer_id,
            i.issue_date,
            i.due_date,
            i.status,
            i.total,
            i.balance_due,
            i.journal_id,
            c.name as customer_name
        FROM invoices i
        LEFT JOIN crm_accounts c ON i.customer_id = c.id
        WHERE i.company_id = ?
        ORDER BY i.issue_date DESC
        LIMIT 100
    ");
    $stmt->execute([$companyId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $invoices
    ]);

} catch (Exception $e) {
    error_log("AR invoice list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load invoices'
    ]);
}