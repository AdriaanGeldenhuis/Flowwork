<?php
// POST /calendar/api/import.php  multipart: file (.ics, max 2 MB), calendar_id
// Adds the file's events to a calendar the user can add to. Importing the same
// file again updates the events instead of duplicating them.
require_once __DIR__ . '/_bootstrap.php';

cal_require_post();
cal_rate_limit('cal-import', 10, 600);

$f = $_FILES['file'] ?? null;
if (!$f || !is_array($f) || is_array($f['error'])) cal_error('Choose an .ics file to import.');
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > 2 * 1024 * 1024) {
    cal_error('That file is larger than 2 MB.', 413);
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) cal_error('The upload did not complete — please try again.');
$text = (string)file_get_contents($f['tmp_name']);
if (!preg_match('/BEGIN:VCALENDAR/i', substr($text, 0, 4096))) cal_error('That is not an iCalendar (.ics) file.');
if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');

$calendarId = (int)($_POST['calendar_id'] ?? 0) ?: $CAL->defaultCalendarId();
$parsed = Ics::parse($text, CalendarRepo::companyTz($DB, $CAL_COMPANY_ID));
if (!$parsed['events']) cal_error('No events were found in that file.');

$result = $CAL->importIcs($calendarId, $parsed['events']);
cal_json($result + ['skipped' => $result['skipped'] + $parsed['skipped'], 'simplified' => $parsed['simplified']]);
