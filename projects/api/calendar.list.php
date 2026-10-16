<?php
// GET /projects/api/calendar.list.php?project_id=5[&start=2026-10-01&end=2026-10-31]
// A project's calendar: events linked to the project or its tasks (only those
// the user may see), the project's start and due dates, and task due dates.
// → { ok: true, data: { events: [...], items: [...] } }
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');
require_project_role($projectId, 'viewer');

$valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : null;
$start = $valid($_GET['start'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
$end = $valid($_GET['end'] ?? null) ?? date('Y-m-d', strtotime('+180 days'));
if ($end < $start || strtotime($end) - strtotime($start) > 400 * 86400) respond_error('Invalid date range');

try {
    respond_ok(CalendarLink::projectTimeline($DB, (int)$COMPANY_ID, (int)$USER_ID, (string)$USER_ROLE, $projectId, $start, $end));
} catch (Throwable $e) {
    error_log('Project calendar list error: ' . $e->getMessage());
    respond_error('Failed to load the calendar', 500);
}
