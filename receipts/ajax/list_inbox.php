<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$search = $_GET['search'] ?? '';
$supplier = (int)($_GET['supplier'] ?? 0);

$sql = "
    SELECT rf.file_id, rf.uploaded_at, rf.ocr_status,
           ro.vendor_name, ro.invoice_number, ro.total, ro.confidence_score
    FROM receipt_file rf
    LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
    WHERE rf.company_id = ? AND rf.invoice_id IS NULL
";

$params = [$companyId];

if ($search) {
    $sql .= " AND (ro.vendor_name LIKE ? OR ro.invoice_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY rf.uploaded_at DESC LIMIT 50";

$stmt = $DB->prepare($sql);
$stmt->execute($params);
$receipts = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'receipts' => $receipts
]);