<?php
// /projects/api/calendar.php — a project's calendar: events linked to the project
// or its items (only those the user may see) plus project and item dates.
//   GET ?project_id=5&from=2026-10-01&to=2026-12-31
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';

$pid = (int)($_GET['project_id'] ?? 0);
$PR->requireProject($pid, 1);
$from = Columns::date($_GET['from'] ?? null) ?? date('Y-m-01');
$to = Columns::date($_GET['to'] ?? null) ?? date('Y-m-t', strtotime($from . ' +2 months'));
if ($to < $from || (strtotime($to) - strtotime($from)) > 400 * 86400) pr_error('Choose a range of up to a year.');
pr_json(CalendarLink::projectTimeline($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE, $pid, $from, $to));
