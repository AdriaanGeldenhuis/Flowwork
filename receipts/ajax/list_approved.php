<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$stmt = $DB->prepare("
    SELECT rf.file_id, rf.uploaded_at,
           i.invoice_number, i.total, i.status, i.issue_date,
           ca.name as vendor_name
    FROM receipt_file rf
    INNER JOIN invoices i ON i.id = rf.invoice_id
    INNER JOIN crm_accounts ca ON ca.id = i.customer_id
    WHERE rf.company_id = ? AND i.status IN ('sent', 'viewed', 'paid')
    ORDER BY rf.uploaded_at DESC
    LIMIT 50
");
$stmt->execute([$companyId]);
$receipts = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'receipts' => $receipts
]);