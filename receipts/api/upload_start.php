<?php
// API endpoint to start a receipt upload. Validates and saves the file, creates a receipt_file row.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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

// Fetch settings for file size
$stmt = $DB->prepare("SELECT max_file_size_mb FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$maxSizeMB = $settings ? $settings['max_file_size_mb'] : 15;
$maxSizeBytes = $maxSizeMB * 1024 * 1024;

// Allowed MIME types
$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp'
];

// Handle upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload error code ' . $file['error']]);
    exit;
}

// Check size
if ($file['size'] > $maxSizeBytes) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max ' . $maxSizeMB . 'MB)']);
    exit;
}

// Determine MIME type using finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
    exit;
}

// Determine upload directory
$uploadDir = __DIR__ . '/../../uploads/receipts/' . $companyId . '/' . date('Ym');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$safeName = uniqid('receipt_', true) . '.' . $ext;
$fullPath = $uploadDir . '/' . $safeName;
$relativePath = 'uploads/receipts/' . $companyId . '/' . date('Ym') . '/' . $safeName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Insert into receipt_file
try {
    $stmt = $DB->prepare("INSERT INTO receipt_file (company_id, path, mime, size_bytes, uploaded_by, ocr_status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([
        $companyId,
        $relativePath,
        $mimeType,
        $file['size'],
        $userId
    ]);
    $fileId = (int)$DB->lastInsertId();

    // Audit log (optional): omitted for brevity

    echo json_encode([
        'ok' => true,
        'file_id' => $fileId
    ]);
} catch (Exception $e) {
    error_log('upload_start DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}