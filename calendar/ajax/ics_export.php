<?php
// /calendar/ajax/ics_export.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$calendarId = $_GET['calendar_id'] ?? null;
$rangeStart = $_GET['range_start'] ?? date('Y-m-d', strtotime('-30 days'));
$rangeEnd = $_GET['range_end'] ?? date('Y-m-d', strtotime('+365 days'));

if (!$calendarId) {
    http_response_code(400);
    exit('Calendar ID required');
}

// Verify access
$stmt = $DB->prepare("
    SELECT name FROM calendars 
    WHERE id = ? AND company_id = ? 
    AND (owner_id = ? OR calendar_type IN ('team', 'resource'))
");
$stmt->execute([$calendarId, $companyId, $userId]);
$calendar = $stmt->fetch();

if (!$calendar) {
    http_response_code(403);
    exit('Access denied');
}

// Fetch events
$stmt = $DB->prepare("
    SELECT * FROM calendar_events
    WHERE calendar_id = ? AND company_id = ?
    AND start_datetime >= ? AND end_datetime <= ?
    ORDER BY start_datetime
");
$stmt->execute([$calendarId, $companyId, $rangeStart, $rangeEnd]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate ICS
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="calendar_' . $calendarId . '.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//FlowWorks//Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:" . $calendar['name'] . "\r\n";
echo "X-WR-TIMEZONE:Africa/Johannesburg\r\n";

foreach ($events as $event) {
    $uid = 'event-' . $event['id'] . '@flowworks.local';
    $dtstart = date('Ymd\THis', strtotime($event['start_datetime']));
    $dtend = date('Ymd\THis', strtotime($event['end_datetime']));
    $dtstamp = date('Ymd\THis', strtotime($event['created_at']));
    
    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . $uid . "\r\n";
    echo "DTSTAMP:" . $dtstamp . "\r\n";
    echo "DTSTART:" . $dtstart . "\r\n";
    echo "DTEND:" . $dtend . "\r\n";
    echo "SUMMARY:" . str_replace(["\r", "\n"], ' ', $event['title']) . "\r\n";
    
    if ($event['description']) {
        echo "DESCRIPTION:" . str_replace(["\r", "\n"], ' ', $event['description']) . "\r\n";
    }
    
    if ($event['location']) {
        echo "LOCATION:" . str_replace(["\r", "\n"], ' ', $event['location']) . "\r\n";
    }
    
    if ($event['recurrence']) {
        echo "RRULE:" . $event['recurrence'] . "\r\n";
    }
    
    echo "STATUS:CONFIRMED\r\n";
    echo "TRANSP:OPAQUE\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
exit;