<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/ocr_engine.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$fileId = (int)($_POST['file_id'] ?? 0);

if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'Missing file_id']);
    exit;
}

// Fetch file
$stmt = $DB->prepare("SELECT * FROM receipt_file WHERE file_id = ? AND company_id = ?");
$stmt->execute([$fileId, $companyId]);
$file = $stmt->fetch();

if (!$file) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Fetch OCR settings
$stmt = $DB->prepare("SELECT ocr_provider FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$provider = $settings['ocr_provider'] ?? 'tesseract';

// Run OCR
$ocr = new OCREngine($provider);
$fullPath = __DIR__ . '/../../' . $file['path'];

if (!file_exists($fullPath)) {
    echo json_encode(['ok' => false, 'error' => 'File not found on disk']);
    exit;
}

$result = $ocr->parseReceipt($fullPath, $file['mime']);

// Save to DB
try {
    $stmt = $DB->prepare("
        INSERT INTO receipt_ocr (
            file_id, raw_json, vendor_name, vendor_vat, invoice_number, invoice_date,
            currency, subtotal, tax, total, line_items_json, confidence_score
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            vendor_name = VALUES(vendor_name),
            vendor_vat = VALUES(vendor_vat),
            invoice_number = VALUES(invoice_number),
            invoice_date = VALUES(invoice_date),
            currency = VALUES(currency),
            subtotal = VALUES(subtotal),
            tax = VALUES(tax),
            total = VALUES(total),
            line_items_json = VALUES(line_items_json),
            confidence_score = VALUES(confidence_score)
    ");
    
    $stmt->execute([
        $fileId,
        json_encode($result),
        $result['vendor_name'],
        $result['vendor_vat'],
        $result['invoice_number'],
        $result['invoice_date'],
        $result['currency'],
        $result['subtotal'],
        $result['tax'],
        $result['total'],
        json_encode($result['lines']),
        $result['confidence']
    ]);

    $stmt = $DB->prepare("UPDATE receipt_file SET ocr_status = 'parsed' WHERE file_id = ?");
    $stmt->execute([$fileId]);

    echo json_encode(['ok' => true, 'data' => $result]);

} catch (Exception $e) {
    error_log('OCR save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}