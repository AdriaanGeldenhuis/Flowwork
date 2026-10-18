<?php
// /projects/guest-file.php?token=…&id=12[&download=1] — a file on the board a
// guest was invited to (live items only), served like api/files.php: nosniff,
// a sandbox CSP, and inline only for images, PDFs and plain text.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';

$g = Guests::current($DB, (string)($_GET['token'] ?? ''));
if (!$g) { http_response_code(401); exit('Sign in to view this file.'); }
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$st = $DB->prepare("SELECT a.file_name, a.file_path, a.mime_type FROM board_item_attachments a
                      JOIN board_items i ON i.id = a.item_id
                     WHERE a.id = ? AND i.board_id = ? AND i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL)");
$st->execute([(int)($_GET['id'] ?? 0), (int)$g['board_id'], (int)$g['company_id']]);
$f = $st->fetch(PDO::FETCH_ASSOC);
$path = $f ? ProjectPaths::resolveStored((string)$f['file_path']) : null;
if (!$f || $path === null) { http_response_code(404); exit('File not found.'); }

$mime = (string)($f['mime_type'] ?: 'application/octet-stream');
$inline = empty($_GET['download']) && in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'], true);
$name = str_replace(['"', "\r", "\n", '\\'], '', (string)$f['file_name']);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
