<?php
// /mail/ajax/download_attachment.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$attachmentId) {
    http_response_code(400);
    exit('Missing attachment ID');
}

try {
    // Get attachment (tenant check via email -> account)
    $stmt = $DB->prepare("
        SELECT a.file_name, a.file_path, a.mime_type
        FROM email_attachments a
        JOIN emails e ON a.email_id = e.email_id
        JOIN email_accounts acc ON e.account_id = acc.account_id
        WHERE a.attachment_id = ? 
          AND acc.company_id = ? 
          AND acc.user_id = ?
    ");
    $stmt->execute([$attachmentId, $companyId, $userId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found');
    }

    $filePath = __DIR__ . '/../../' . $attachment['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        exit('File not found on disk');
    }

    // Serve file
    header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($attachment['file_name']) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;

} catch (Exception $e) {
    error_log("Attachment download error: " . $e->getMessage());
    http_response_code(500);
    exit('Download failed');
}