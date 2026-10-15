<?php
// GET /mail/api/attachment.php?id=123[&inline=1]
// Streams an attachment the current user owns. Only images and PDFs are ever
// served inline, under a sandboxing CSP; everything else downloads.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
session_write_close();

$id = (int)($_GET['id'] ?? 0);
$stmt = $DB->prepare("SELECT ea.file_name, ea.file_path, ea.mime_type
                        FROM email_attachments ea
                        JOIN emails e ON e.email_id = ea.email_id
                        JOIN email_accounts a ON a.account_id = e.account_id
                       WHERE ea.attachment_id = ? AND a.company_id = ? AND a.user_id = ?");
$stmt->execute([$id, $companyId, $userId]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);
$path = $att ? MailPaths::resolveStored((string)$att['file_path']) : null;
if (!$att || $path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Attachment not found');
}

$mime = strtolower((string)$att['mime_type']) ?: 'application/octet-stream';
$safeInline = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'], true);
$inline = !empty($_GET['inline']) && $safeInline;
$name = MimeParser::safeFilename((string)$att['file_name']);
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
$ascii = str_replace(['"', '\\'], '_', $ascii);

$risky = preg_match('#(html|svg|xml|javascript|ecmascript)#', $mime);
header('Content-Type: ' . ($risky ? 'application/octet-stream' : $mime));
header('X-Content-Type-Options: nosniff');
// Browsers' built-in PDF viewers do not run under a restrictive/sandboxing CSP;
// everything else (images, downloads) gets one.
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
}
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
