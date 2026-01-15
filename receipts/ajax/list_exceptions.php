<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$stmt = $DB->prepare("
    SELECT DISTINCT rf.file_id, rf.uploaded_at,
           ro.vendor_name, ro.invoice_number, ro.total,
           GROUP_CONCAT(rpl.message SEPARATOR '; ') as issues
    FROM receipt_file rf
    LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
    INNER JOIN receipt_policy_log rpl ON rpl.file_id = rf.file_id AND rpl.company_id = rf.company_id
    WHERE rf.company_id = ? AND rpl.severity = 'error' AND rpl.override_by IS NULL
    GROUP BY rf.file_id
    ORDER BY rf.uploaded_at DESC
    LIMIT 50
");
$stmt->execute([$companyId]);
$receipts = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'receipts' => $receipts
]);