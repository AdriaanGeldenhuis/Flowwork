<?php
// /calendar/api/attachment.php
//   GET  ?id=5[&inline=1]               download a file (anyone who can see the event)
//   POST multipart: event_id, file       attach a file (people who can edit the event)
//   POST {action:'delete', id}           remove a file
// Files live under storage/calendar/<company>/<event>/ (never served directly).
require_once __DIR__ . '/_bootstrap.php';

const CAL_MAX_FILE = 15 * 1024 * 1024;
const CAL_MAX_FILES = 20;

$fetch = function (int $id) use ($DB, $CAL_COMPANY_ID): ?array {
    $st = $DB->prepare("SELECT a.* FROM calendar_event_attachments a JOIN calendar_events e ON e.id = a.event_id
                         WHERE a.id = ? AND e.company_id = ?");
    $st->execute([$id, $CAL_COMPANY_ID]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $att = $fetch((int)($_GET['id'] ?? 0));
    $ev = $att ? $CAL->eventRow((int)$att['event_id']) : null;
    if (!$att || !$ev || $CAL->eventAccess($ev)['see'] !== 'full') cal_error('File not found', 404);
    $path = CalendarPaths::resolveStored((string)$att['file_path']);
    if (!$path) cal_error('File not found', 404);
    $mime = (string)($att['mime_type'] ?: 'application/octet-stream');
    $inline = !empty($_GET['inline']) && preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf)$#', $mime);
    $name = str_replace(['"', "\r", "\n"], '', (string)$att['file_name']);
    header_remove('Content-Type');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: " . ($inline ? 'inline' : 'attachment') . "; filename=\"" . preg_replace('/[^\x20-\x7e]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

cal_require_post();

if (($_POST['action'] ?? cal_input()['action'] ?? '') === 'delete') {
    $in = cal_input();
    $att = $fetch((int)($in['id'] ?? 0));
    $ev = $att ? $CAL->eventRow((int)$att['event_id']) : null;
    if (!$att || !$ev) cal_error('File not found', 404);
    if (!$CAL->eventAccess($ev)['edit']) throw new CalendarUserError('You cannot change this event.');
    $DB->prepare("DELETE FROM calendar_event_attachments WHERE id = ?")->execute([(int)$att['id']]);
    if ($p = CalendarPaths::resolveStored((string)$att['file_path'])) @unlink($p);
    $CAL->audit('event_file_removed', ['event_id' => (int)$att['event_id'], 'file' => $att['file_name']]);
    cal_json();
}

cal_rate_limit('cal-upload', 60, 600);
$eventId = (int)($_POST['event_id'] ?? 0);
$ev = $CAL->eventRow($eventId);
if (!$ev) cal_error('Event not found', 404);
if (!$CAL->eventAccess($ev)['edit']) throw new CalendarUserError('You cannot change this event.');

$f = $_FILES['file'] ?? null;
if (!$f || !is_array($f) || is_array($f['error'])) cal_error('Choose a file to attach.');
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > CAL_MAX_FILE) {
    cal_error('That file is larger than 15 MB.', 413);
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) cal_error('The upload did not complete — please try again.');

$st = $DB->prepare("SELECT COUNT(*) FROM calendar_event_attachments WHERE event_id = ?");
$st->execute([$eventId]);
if ((int)$st->fetchColumn() >= CAL_MAX_FILES) throw new CalendarUserError('An event can hold at most ' . CAL_MAX_FILES . ' files.');

$name = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '_', (string)$f['name']), ". \t") ?: 'file';
$name = mb_substr($name, 0, 200);
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($fi->file($f['tmp_name']) ?: $mime);
}

$base = CalendarPaths::storageDir();
if ($base === null) cal_error('File storage is not available on the server.', 500);
$dir = $base . '/' . $CAL_COMPANY_ID . '/' . $eventId;
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) cal_error('File storage is not available on the server.', 500);
$file = bin2hex(random_bytes(16)) . '.bin';
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) cal_error('Could not save the file.', 500);
$rel = 'storage/calendar/' . $CAL_COMPANY_ID . '/' . $eventId . '/' . $file;

$DB->prepare("INSERT INTO calendar_event_attachments (event_id, file_name, file_path, file_size, mime_type, uploaded_by, uploaded_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())")
   ->execute([$eventId, $name, $rel, (int)$f['size'], mb_substr($mime, 0, 100), $CAL_USER_ID]);
$id = (int)$DB->lastInsertId();
$CAL->audit('event_file_added', ['event_id' => $eventId, 'file' => $name]);
cal_json(['attachment' => ['id' => $id, 'name' => $name, 'size' => (int)$f['size'], 'mime' => $mime,
                           'url' => '/calendar/api/attachment.php?id=' . $id]]);
