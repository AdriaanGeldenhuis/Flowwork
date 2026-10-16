<?php
// GET /calendar/api/export.php?calendar_id=3  download a calendar as .ics
// Includes what the user may see on that calendar: private events of others are
// left out and "busy" events appear as Busy.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/reminders.php'; // fw_calendar_reminder_host()

$cal = $CAL->calendar((int)($_GET['calendar_id'] ?? 0));
if (!$cal) cal_error('Calendar not found', 404);

$st = $DB->prepare("SELECT e.*, p.ics_uid AS parent_ics_uid FROM calendar_events e
                 LEFT JOIN calendar_events p ON p.id = e.series_id
                     WHERE e.company_id = ? AND e.calendar_id = ?
                  ORDER BY e.start_datetime LIMIT 20000");
$st->execute([$CAL_COMPANY_ID, (int)$cal['id']]);
$host = fw_calendar_reminder_host();
$events = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $acc = $CAL->eventAccess($ev);
    if ($acc['see'] === 'none') continue;
    $ev['busy'] = $acc['see'] === 'busy';
    if ($ev['series_id']) $ev['parent_uid'] = $ev['parent_ics_uid'] ?: 'fw-cal-' . (int)$ev['series_id'] . '@' . $host;
    $events[] = $ev;
}

$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $cal['name']) ?: 'calendar';
header_remove('Content-Type');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . trim($filename, '-') . '.ics"');
echo Ics::build($cal['name'], $events, CalendarRepo::companyTz($DB, $CAL_COMPANY_ID), $host);
