<?php
// GET /projects/api/calendar.ics.php?project_id=5 — the project's calendar as .ics:
// linked events the user may see, the project's start and due dates, task due dates.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';
require_once __DIR__ . '/../../calendar/lib/reminders.php'; // fw_calendar_reminder_host()

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    http_response_code(400);
    die('Project ID required');
}
require_project_role($projectId, 'viewer');

$stmt = $DB->prepare("SELECT name FROM projects WHERE project_id = ? AND company_id = ?");
$stmt->execute([$projectId, $COMPANY_ID]);
$project = $stmt->fetch();
if (!$project) {
    http_response_code(404);
    die('Project not found');
}

$tl = CalendarLink::projectTimeline($DB, (int)$COMPANY_ID, (int)$USER_ID, (string)$USER_ROLE, $projectId,
                                    date('Y-m-d', strtotime('-1 year')), date('Y-m-d', strtotime('+2 years')));
$host = fw_calendar_reminder_host();
$rows = [];
$seen = [];
foreach ($tl['events'] as $e) {
    if (isset($seen[$e['id']])) continue; // a repeating event is exported once, as its series
    $seen[$e['id']] = true;
    $st = $DB->prepare("SELECT * FROM calendar_events WHERE id = ? AND company_id = ?");
    $st->execute([$e['id'], $COMPANY_ID]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $row['busy'] = $e['busy'];
        $rows[] = $row;
    }
}
foreach ($tl['items'] as $it) {
    $rows[] = ['id' => 0, 'ics_uid' => 'fw-' . str_replace(':', '-', $it['key']) . '@' . $host, 'title' => $it['title'],
               'description' => $it['sub'] ?? '', 'location' => '', 'start_datetime' => $it['start'] . ' 00:00:00',
               'end_datetime' => $it['end'] . ' 23:59:59', 'all_day' => 1, 'recurrence' => null, 'exdates_json' => null,
               'created_at' => date('Y-m-d H:i:s'), 'updated_at' => null, 'url' => 'https://' . $host . $it['url']];
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="project-' . $projectId . '.ics"');
echo Ics::build((string)$project['name'], $rows, CalendarRepo::companyTz($DB, (int)$COMPANY_ID), $host);
exit;
