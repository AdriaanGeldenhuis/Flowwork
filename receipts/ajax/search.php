<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$search = $_GET['search'] ?? '';

$stmt = $DB->prepare("
    SELECT rf.file_id, rf.uploaded_at, rf.ocr_status,
           ro.vendor_name, ro.invoice_number, ro.total,
           i.status as invoice_status
    FROM receipt_file rf
    LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
    LEFT JOIN invoices i ON i.id = rf.invoice_id
    WHERE rf.company_id = ?
      AND (ro.vendor_name LIKE ? OR ro.invoice_number LIKE ? OR i.invoice_number LIKE ?)
    ORDER BY rf.uploaded_at DESC
    LIMIT 100
");
$stmt->execute([$companyId, "%$search%", "%$search%", "%$search%"]);
$receipts = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'receipts' => $receipts
]);