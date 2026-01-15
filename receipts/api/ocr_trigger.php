<?php
// API endpoint to trigger OCR processing for a uploaded receipt file.
// It runs the OCR synchronously for now and updates the receipt_file status to 'parsed'.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/ocr_engine.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Expect JSON POST body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$fileId = isset($input['file_id']) ? (int)$input['file_id'] : 0;

if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'Missing file_id']);
    exit;
}

// Fetch file record
$stmt = $DB->prepare("SELECT * FROM receipt_file WHERE file_id = ? AND company_id = ?");
$stmt->execute([$fileId, $companyId]);
$file = $stmt->fetch();

if (!$file) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Only trigger OCR if not already parsed
if ($file['ocr_status'] === 'parsed') {
    echo json_encode(['ok' => true, 'message' => 'Already parsed']);
    exit;
}

// Mark as processing (not persisted, but can be extended)

// Fetch OCR provider from settings; default to tesseract
$stmt = $DB->prepare("SELECT ocr_provider FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$provider = $settings['ocr_provider'] ?? 'tesseract';

// Resolve file path
$fullPath = __DIR__ . '/../../' . $file['path'];
if (!file_exists($fullPath)) {
    echo json_encode(['ok' => false, 'error' => 'File not found on disk']);
    exit;
}

// Run OCR synchronously
try {
    $ocr = new OCREngine($provider);
    $result = $ocr->parseReceipt($fullPath, $file['mime']);

    // Insert or update OCR data in receipt_ocr table
    $stmt = $DB->prepare("\n        INSERT INTO receipt_ocr (\n            file_id, raw_json, vendor_name, vendor_vat, invoice_number, invoice_date,\n            currency, subtotal, tax, total, line_items_json, confidence_score\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            vendor_name = VALUES(vendor_name),\n            vendor_vat = VALUES(vendor_vat),\n            invoice_number = VALUES(invoice_number),\n            invoice_date = VALUES(invoice_date),\n            currency = VALUES(currency),\n            subtotal = VALUES(subtotal),\n            tax = VALUES(tax),\n            total = VALUES(total),\n            line_items_json = VALUES(line_items_json),\n            confidence_score = VALUES(confidence_score)\n    ");
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

    // Update receipt_file status to parsed
    $stmt = $DB->prepare("UPDATE receipt_file SET ocr_status = 'parsed' WHERE file_id = ?");
    $stmt->execute([$fileId]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('OCR trigger error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'OCR processing failed']);
}
