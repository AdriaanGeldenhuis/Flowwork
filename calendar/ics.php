<?php
// /calendar/ics.php?token=… — private subscription link for one calendar, for
// Google Calendar, Apple Calendar or Outlook ("subscribe from URL"). Read-only;
// the link is the credential (owners and admins can reset it in Calendar
// settings). Private events are never published; "busy" events show as Busy.
// No session is used, so it works from calendar apps on any device.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/lib/reminders.php'; // fw_calendar_reminder_host()

header('X-Content-Type-Options: nosniff');
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    exit('Not found');
}
$wait = CalendarRepo::rateLimit('ics:' . $token, 120, 3600);
if ($wait > 0) {
    http_response_code(429);
    header('Retry-After: ' . $wait);
    exit('Too many requests');
}

CalendarSchema::ensure($DB);
$st = $DB->prepare("SELECT * FROM calendars WHERE ics_token = ? AND (is_active = 1 OR is_active IS NULL) LIMIT 1");
$st->execute([$token]);
$cal = $st->fetch(PDO::FETCH_ASSOC);
if (!$cal) {
    http_response_code(404);
    exit('Not found');
}

$host = fw_calendar_reminder_host();
$st = $DB->prepare("SELECT e.*, p.ics_uid AS parent_ics_uid FROM calendar_events e
                 LEFT JOIN calendar_events p ON p.id = e.series_id
                     WHERE e.company_id = ? AND e.calendar_id = ? AND e.visibility <> 'private'
                       AND (e.end_datetime >= DATE_SUB(NOW(), INTERVAL 1 YEAR) OR (e.recurrence IS NOT NULL AND e.recurrence <> ''))
                  ORDER BY e.start_datetime LIMIT 10000");
$st->execute([(int)$cal['company_id'], (int)$cal['id']]);
$events = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $ev['busy'] = $ev['visibility'] === 'busy';
    if ($ev['series_id']) $ev['parent_uid'] = $ev['parent_ics_uid'] ?: 'fw-cal-' . (int)$ev['series_id'] . '@' . $host;
    $events[] = $ev;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendar.ics"');
header('Cache-Control: private, max-age=900');
echo Ics::build((string)$cal['name'], $events, CalendarRepo::companyTz($DB, (int)$cal['company_id']), $host);
