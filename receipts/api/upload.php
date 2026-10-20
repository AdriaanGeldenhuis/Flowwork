<?php
// /receipts/api/upload.php — POST one file (multipart "file", optional "note"):
// a PDF or photo, or a ZIP of them. Each slip is checked by its content and
// stored; one already sent in is not stored twice. Reading happens next, one
// slip at a time, through read.php (so a big batch never times out).
//   → files: [{file_id, name, duplicate, receipt?}], skipped: [{name, reason}]
require_once __DIR__ . '/_bootstrap.php';

if (!rc_is_post()) rc_error('POST only.', 405);
if (!RcKit::canUpload($RC_ROLE)) rc_error('Viewers cannot send in receipts.', 403);
rc_rate_limit('rc-upload', 200, 600);

$f = $_FILES['file'] ?? null;
if (!$f || is_array($f['name'] ?? null)) rc_error('Choose a file to send in.', 422);
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $big = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    rc_error($big ? 'That file is larger than the server accepts.' : 'The file did not arrive. Please try again.', $big ? 413 : 422);
}
$tmp = (string)$f['tmp_name'];
if (!is_uploaded_file($tmp)) rc_error('The file did not arrive. Please try again.', 422);
$settings = RcKit::settings($DB, $RC_COMPANY_ID);
$max = (int)$settings['max_file_size_mb'] * 1048576;
$note = (string)(rc_input()['note'] ?? '');
$name = RcFiles::cleanName((string)$f['name']);
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);

$files = [];
$skipped = [];
$keep = function (string $path, string $label) use ($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $max, $note, &$files, &$skipped) {
    try {
        $r = RcFiles::store($DB, $RC_COMPANY_ID, $RC_USER_ID, $path, $label, $max, $note);
        $row = RcBills::row($DB, $RC_COMPANY_ID, $r['file_id']);
        $files[] = $r + ['receipt' => $row && RcBills::canSee($row, $RC_ROLE, $RC_USER_ID) ? RcBills::shapeRow($DB, $row) : null];
    } catch (RcProblem $e) {
        $skipped[] = ['name' => $label, 'reason' => $e->getMessage()];
    }
};

$isZip = in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
if (!$isZip) {
    $keep($tmp, $name);
} else {
    if (!class_exists('ZipArchive')) rc_error('This server cannot open ZIP files — send the slips one by one.', 415);
    if (filesize($tmp) > 150 * 1048576) rc_error('That ZIP file is too large (150 MB at most).', 413);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) rc_error('That ZIP file could not be opened.', 422);
    if ($zip->numFiles > 1000) { $zip->close(); rc_error('That ZIP holds too many files (1 000 at most) — send the slips in smaller ZIPs.', 413); }
    $budget = 400 * 1048576; // unpacked bytes, against zip bombs: the size each entry claims, then what really came out
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $entry = (string)($stat['name'] ?? '');
        if ($entry === '' || str_ends_with($entry, '/') || str_starts_with(basename($entry), '.') || str_contains($entry, '__MACOSX')) continue;
        $label = RcFiles::cleanName($entry);
        if (!in_array(strtolower(pathinfo($label, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) { $skipped[] = ['name' => $label, 'reason' => 'Not a PDF or a photo.']; continue; }
        if (++$count > 200) { $skipped[] = ['name' => $label, 'reason' => 'Only the first 200 slips of a ZIP are taken.']; continue; }
        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > $max) { $skipped[] = ['name' => $label, 'reason' => $size <= 0 ? 'Empty.' : 'Too large (' . (int)$settings['max_file_size_mb'] . ' MB at most).']; continue; }
        if (($budget -= $size) < 0) { $skipped[] = ['name' => $label, 'reason' => 'The ZIP holds too much — send the rest in another one.']; continue; }
        $out = tempnam(sys_get_temp_dir(), 'rczip');
        $src = $zip->getStream($stat['name']);
        $dst = $src ? fopen($out, 'wb') : false;
        $copied = ($src && $dst) ? stream_copy_to_stream($src, $dst, $max + 1) : false;
        if ($src) fclose($src);
        if ($dst) fclose($dst);
        if ($copied === false || $copied > $max) { $skipped[] = ['name' => $label, 'reason' => 'Could not be unpacked.']; @unlink($out); continue; }
        if (($budget -= max(0, $copied - $size)) < 0) { $skipped[] = ['name' => $label, 'reason' => 'The ZIP holds too much — send the rest in another one.']; @unlink($out); continue; }
        $keep($out, $label);
        @unlink($out);
    }
    $zip->close();
}
if (!$files && $skipped && !$isZip) rc_error($skipped[0]['reason'], 422);
rc_json(['files' => $files, 'skipped' => $skipped]);
