<?php
// POST /projects/api/calendar.add.php
//   project_id, title, starts_at, ends_at (optional), all_day (0|1), item_id (optional)
// Adds an event to the user's own calendar, linked to the project (or to one of
// its board items), so it shows on the project's calendar and in the Calendar.
// → { ok: true, data: { event_id, url } }
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$startsAt = trim((string)($_POST['starts_at'] ?? ''));
$endsAt = trim((string)($_POST['ends_at'] ?? ''));
$allDay = !empty($_POST['all_day']);
$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId && ($_POST['source'] ?? '') === 'item') $itemId = (int)($_POST['source_id'] ?? 0);

if (!$projectId) respond_error('Project ID required');
if ($title === '') respond_error('Title required');
if ($startsAt === '') respond_error('Start date required');

require_project_role($projectId, 'contributor');

$link = ['type' => 'project', 'id' => $projectId];
if ($itemId > 0) {
    $st = $DB->prepare("SELECT i.id FROM board_items i JOIN project_boards b ON b.board_id = i.board_id
                         WHERE i.id = ? AND b.project_id = ? AND i.company_id = ?");
    $st->execute([$itemId, $projectId, $COMPANY_ID]);
    if (!$st->fetchColumn()) respond_error('Item not found in this project', 404);
    $link = ['type' => 'item', 'id' => $itemId];
}

try {
    CalendarSchema::ensure($DB);
    $cal = new CalendarRepo($DB, (int)$COMPANY_ID, (int)$USER_ID, (string)$USER_ROLE);
    $start = CalendarRepo::parseWhen($startsAt, $allDay, false);
    if ($start === null) respond_error('Invalid start date');
    $eventId = $cal->create([
        'calendar_id' => $cal->defaultCalendarId(),
        'title'       => $title,
        'all_day'     => $allDay,
        'start'       => $startsAt,
        'end'         => $endsAt !== '' ? $endsAt : ($allDay ? $startsAt : date('Y-m-d H:i:s', strtotime($start) + 3600)),
        'links'       => [$link],
    ]);
    respond_ok(['event_id' => $eventId, 'url' => '/calendar/?event=' . $eventId . '&date=' . substr($start, 0, 10)]);
} catch (CalendarUserError $e) {
    respond_error($e->getMessage());
} catch (Throwable $e) {
    error_log("Calendar add error: " . $e->getMessage());
    respond_error('Failed to create event', 500);
}
