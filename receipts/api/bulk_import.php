<?php
// API endpoint for bulk importing receipts from a ZIP file.
// Accepts a ZIP archive containing PDF/JPG/PNG/WEBP files.
// For each file, it creates a receipt_file entry and runs OCR immediately.

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
$userId    = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Basic validation: expect zip
// Accept both .zip extension and application/zip MIME
$zipMimeTypes = [
    'application/zip',
    'application/x-zip-compressed',
    'multipart/x-zip'
];

// Determine MIME type using finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($mimeType, $zipMimeTypes) && $ext !== 'zip') {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type (expect ZIP)']);
    exit;
}

// Fetch settings for file size
$stmt = $DB->prepare("SELECT max_file_size_mb FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$maxSizeMB = $settings ? $settings['max_file_size_mb'] : 15;
$maxSizeBytes = $maxSizeMB * 1024 * 1024;

// Limit zip size to a multiple of max single file size (e.g. 10x)
$zipMaxBytes = $maxSizeBytes * 10;
if ($file['size'] > $zipMaxBytes) {
    echo json_encode(['ok' => false, 'error' => 'ZIP file too large (max ' . ($zipMaxBytes / 1024 / 1024) . 'MB)']);
    exit;
}

// Move uploaded zip to temp location
$tempZipPath = tempnam(sys_get_temp_dir(), 'receipt_zip_');
if (!move_uploaded_file($file['tmp_name'], $tempZipPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save ZIP file']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tempZipPath) !== true) {
    unlink($tempZipPath);
    echo json_encode(['ok' => false, 'error' => 'Failed to open ZIP archive']);
    exit;
}

// Allowed file extensions for receipts
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

// Prepare results
$results = [];

// OCR provider
$stmt = $DB->prepare("SELECT ocr_provider FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$provider = $settings['ocr_provider'] ?? 'tesseract';
// Initialise OCR engine once
$ocrEngine = new OCREngine($provider);

try {
    // Loop through ZIP entries
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        // Skip directories
        if (substr($name, -1) === '/') {
            continue;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExts)) {
            // Skip unsupported
            continue;
        }
        // Extract to temporary file
        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            continue;
        }
        // Validate size of each extracted file
        if (strlen($contents) > $maxSizeBytes) {
            // Too large, skip
            continue;
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'receipt_ex_');
        file_put_contents($tmpPath, $contents);
        // Determine MIME type for extracted file
        $finfo2 = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo2, $tmpPath);
        finfo_close($finfo2);
        // Save to final path similar to upload_start
        $uploadDir = __DIR__ . '/../../uploads/receipts/' . $companyId . '/' . date('Ym');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $safeName = uniqid('receipt_', true) . '.' . $extension;
        $fullPath = $uploadDir . '/' . $safeName;
        $relativePath = 'uploads/receipts/' . $companyId . '/' . date('Ym') . '/' . $safeName;
        // Move
        if (!rename($tmpPath, $fullPath)) {
            unlink($tmpPath);
            continue;
        }
        $sizeBytes = strlen($contents);
        // Insert into receipt_file
        $stmt = $DB->prepare("INSERT INTO receipt_file (company_id, path, mime, size_bytes, uploaded_by, ocr_status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$companyId, $relativePath, $mime, $sizeBytes, $userId]);
        $fileId = (int)$DB->lastInsertId();
        // Perform OCR synchronously similar to ocr_trigger.php
        try {
            $result = $ocrEngine->parseReceipt(__DIR__ . '/../../' . $relativePath, $mime);
            // Save to receipt_ocr
            $stmt2 = $DB->prepare("INSERT INTO receipt_ocr (
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
                confidence_score = VALUES(confidence_score)");
            $stmt2->execute([
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
            // Update receipt_file status
            $stmt3 = $DB->prepare("UPDATE receipt_file SET ocr_status = 'parsed' WHERE file_id = ?");
            $stmt3->execute([$fileId]);
        } catch (Exception $e) {
            error_log('Bulk OCR error for file ' . $name . ': ' . $e->getMessage());
        }
        $results[] = [
            'name' => $name,
            'file_id' => $fileId
        ];
    }
    $zip->close();
    unlink($tempZipPath);
    echo json_encode(['ok' => true, 'results' => $results]);
} catch (Exception $e) {
    $zip->close();
    unlink($tempZipPath);
    error_log('Bulk import error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Bulk import failed']);
}
