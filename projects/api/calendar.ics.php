<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) {
    http_response_code(400);
    die('Project ID required');
}

require_project_role($projectId, 'viewer');

// Get project info
$stmt = $DB->prepare("SELECT name FROM projects WHERE project_id = ? AND company_id = ?");
$stmt->execute([$projectId, $COMPANY_ID]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    die('Project not found');
}

// Get events
$stmt = $DB->prepare("SELECT * FROM calendar_events WHERE project_id = ? AND company_id = ? ORDER BY start_datetime");
$stmt->execute([$projectId, $COMPANY_ID]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate ICS
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="project-' . $projectId . '.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Flowwork//Projects//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "X-WR-CALNAME:" . $project['name'] . "\r\n";
echo "X-WR-TIMEZONE:Africa/Johannesburg\r\n";

foreach ($events as $event) {
    $uid = 'event-' . $event['id'] . '@flowwork.app';
    $dtstart = date('Ymd\THis', strtotime($event['start_datetime']));
    $dtend = $event['end_datetime'] ? date('Ymd\THis', strtotime($event['end_datetime'])) : $dtstart;
    
    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . $uid . "\r\n";
    echo "DTSTAMP:" . date('Ymd\THis') . "\r\n";
    echo "DTSTART:" . $dtstart . "\r\n";
    echo "DTEND:" . $dtend . "\r\n";
    echo "SUMMARY:" . str_replace(["\r", "\n"], ' ', $event['title']) . "\r\n";
    echo "STATUS:CONFIRMED\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
exit;