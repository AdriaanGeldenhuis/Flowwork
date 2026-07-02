<?php
// /qi/ajax/upload_logo.php - WebP Logo Upload with proper structure
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

if (!isset($_FILES['logo'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['logo'];

// Validate file
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload error: ' . $file['error']]);
    exit;
}

// Check file size (max 2MB)
if ($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max 2MB)']);
    exit;
}

// Check file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type. Only JPG/PNG allowed.']);
    exit;
}

// Reject absurd pixel dimensions before decoding: a small compressed file
// can expand to a multi-hundred-MB bitmap in GD (pixel flood).
$dims = @getimagesize($file['tmp_name']);
if ($dims === false || $dims[0] < 1 || $dims[1] < 1 || $dims[0] > 4000 || $dims[1] > 4000 || ($dims[0] * $dims[1]) > 4000000) {
    echo json_encode(['ok' => false, 'error' => 'Image dimensions too large (max 4000px per side, 4 megapixels).']);
    exit;
}

try {
    // Create directory structure: /uploads/{company_id}/logo/
    $uploadDir = __DIR__ . '/../../uploads/' . $companyId . '/logo';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Load image based on type
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $image = imagecreatefrompng($file['tmp_name']);
            break;
        default:
            throw new Exception('Unsupported image type');
    }

    if (!$image) {
        throw new Exception('Failed to load image');
    }

    // Get original dimensions
    $originalWidth = imagesx($image);
    $originalHeight = imagesy($image);

    // Resize if needed (max 800px on either side, maintain aspect ratio)
    $maxWidth = 800;
    $maxHeight = 800;
    if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = max(1, (int)round($originalWidth * $ratio));
        $newHeight = max(1, (int)round($originalHeight * $ratio));
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        imagedestroy($image);
        $image = $resized;
    }

    // Generate filename: logo.webp
    $filename = 'logo.webp';
    $filepath = $uploadDir . '/' . $filename;

    // Convert to WebP
    if (!imagewebp($image, $filepath, 90)) {
        throw new Exception('Failed to convert to WebP');
    }

    imagedestroy($image);

    // Update database with relative URL
    $logoUrl = '/uploads/' . $companyId . '/logo/' . $filename;
    
    $stmt = $DB->prepare("UPDATE companies SET logo_url = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$logoUrl, $companyId]);

    echo json_encode([
        'ok' => true,
        'logo_url' => $logoUrl,
        'message' => 'Logo uploaded successfully'
    ]);

} catch (Exception $e) {
    error_log("Logo upload error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}