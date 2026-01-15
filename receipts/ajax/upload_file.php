<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch settings
$stmt = $DB->prepare("SELECT max_file_size_mb FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$maxSizeMB = $settings ? $settings['max_file_size_mb'] : 15;
$maxSizeBytes = $maxSizeMB * 1024 * 1024;

$allowedMimes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Validation
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload error code ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxSizeBytes) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max ' . $maxSizeMB . 'MB)']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
    exit;
}

// Generate safe path
$uploadDir = __DIR__ . '/../../uploads/receipts/' . $companyId . '/' . date('Ym');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$safeName = uniqid('receipt_', true) . '.' . $ext;
$fullPath = $uploadDir . '/' . $safeName;
$relativePath = 'uploads/receipts/' . $companyId . '/' . date('Ym') . '/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Insert into DB
try {
    $stmt = $DB->prepare("
        INSERT INTO receipt_file (company_id, path, mime, size_bytes, uploaded_by, ocr_status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $companyId,
        $relativePath,
        $mimeType,
        $file['size'],
        $userId
    ]);
    $fileId = $DB->lastInsertId();

    // Log audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'receipt_uploaded', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['file_id' => $fileId, 'filename' => $file['name']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode([
        'ok' => true,
        'file_id' => $fileId,
        'path' => $relativePath
    ]);

} catch (Exception $e) {
    error_log('Receipt upload DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}