<?php
// API endpoint to poll the OCR status of a receipt file.
// Returns the current OCR status and, if parsed, a summary of OCR data.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Expect GET parameter file_id
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'Missing file_id']);
    exit;
}

// Fetch file record
$stmt = $DB->prepare("SELECT ocr_status FROM receipt_file WHERE file_id = ? AND company_id = ?");
$stmt->execute([$fileId, $companyId]);
$file = $stmt->fetch();

if (!$file) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

$status = $file['ocr_status'] ?? 'pending';

// If parsed, fetch summary information from receipt_ocr
if ($status === 'parsed') {
    $stmt = $DB->prepare("\n        SELECT vendor_name, invoice_number, invoice_date, total, subtotal, tax, confidence_score\n        FROM receipt_ocr\n        WHERE file_id = ?\n    ");
    $stmt->execute([$fileId]);
    $ocr = $stmt->fetch();
    if (!$ocr) {
        $ocr = null;
    }
    echo json_encode([
        'ok' => true,
        'status' => 'parsed',
        'ocr' => $ocr
    ]);
    exit;
}

// If failed status exists, report
if ($status === 'failed') {
    echo json_encode(['ok' => true, 'status' => 'failed']);
    exit;
}

// Otherwise pending/processing
echo json_encode(['ok' => true, 'status' => 'pending']);
