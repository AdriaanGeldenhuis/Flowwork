<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$startsAt = $_POST['starts_at'] ?? null;
$endsAt = $_POST['ends_at'] ?? null;
$allDay = (int)($_POST['all_day'] ?? 0);
$source = $_POST['source'] ?? 'manual'; // manual, item, milestone
$sourceId = isset($_POST['source_id']) ? (int)$_POST['source_id'] : null;

if (!$projectId) respond_error('Project ID required');
if (!$title) respond_error('Title required');
if (!$startsAt) respond_error('Start date required');

require_project_role($projectId, 'contributor');

try {
    $stmt = $DB->prepare("
        INSERT INTO calendar_events (
            company_id, project_id, title, start_datetime, end_datetime, 
            all_day, source, source_id, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $COMPANY_ID, $projectId, $title, $startsAt, $endsAt, 
        $allDay, $source, $sourceId, $USER_ID
    ]);
    
    $eventId = $DB->lastInsertId();
    
    respond_ok(['event_id' => $eventId]);
    
} catch (Exception $e) {
    error_log("Calendar add error: " . $e->getMessage());
    respond_error('Failed to create event', 500);
}