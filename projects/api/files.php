<?php
// /projects/api/files.php
//   GET  ?id=12[&download=1]          the file (images and PDFs open inline unless download=1)
//   GET  ?project_id=5                every file on the project's boards
//   POST multipart {action:'upload', item_id, column_id?, file}
//   POST {action:'delete', id}
// Files live under storage/projects/ (denied over HTTP) with random names and
// are only ever served from here, after an access check, with nosniff and a
// sandbox CSP.
require_once __DIR__ . '/_bootstrap.php';

$items = new ItemRepo($PR);

if (!pr_is_post()) {
    if (!empty($_GET['project_id'])) pr_json(['files' => $items->projectFiles((int)$_GET['project_id'])]);
    $f = $items->fileRow((int)($_GET['id'] ?? 0), 1);
    $path = ProjectPaths::resolveStored((string)$f['file_path']);
    if ($path === null) pr_error('That file is no longer on the server.', 404);
    $mime = (string)($f['mime_type'] ?: 'application/octet-stream');
    $inlineTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];
    $inline = empty($_GET['download']) && in_array($mime, $inlineTypes, true);
    $name = str_replace(['"', "\r", "\n", '\\'], '', (string)$f['file_name']);
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

$in = pr_input();
$action = pr_str($in, 'action', 20);

switch ($action) {
    case 'upload':
        pr_rate_limit('pr-upload', 120, 600);
        if (empty($_FILES['file'])) pr_error('Choose a file to upload.');
        $file = $items->addFile(pr_int($in, 'item_id'), $_FILES['file'], pr_int($in, 'column_id') ?: null);
        pr_json(['file' => $file]);

    case 'delete':
        $path = $items->deleteFile(pr_int($in, 'id'));
        if ($path) pr_unlink_files([$path]);
        pr_json();
}
pr_error('Unknown action');
