<?php
// /calendar/ajax/event_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$calendarId = $input['calendar_id'] ?? null;
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$location = trim($input['location'] ?? '');
$startDatetime = $input['start_datetime'] ?? null;
$endDatetime = $input['end_datetime'] ?? null;
$allDay = $input['all_day'] ?? false;
$color = $input['color'] ?? null;
$recurrence = $input['recurrence'] ?? null;
$visibility = $input['visibility'] ?? 'default';
$participants = $input['participants'] ?? [];
$reminders = $input['reminders'] ?? [];

// Validation
if (!$calendarId || empty($title) || !$startDatetime || !$endDatetime) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Verify calendar access
$stmt = $DB->prepare("
    SELECT id FROM calendars 
    WHERE id = ? AND company_id = ? 
    AND (owner_id = ? OR calendar_type IN ('team', 'resource'))
");
$stmt->execute([$calendarId, $companyId, $userId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Calendar not found or access denied']);
    exit;
}

try {
    $DB->beginTransaction();

    // Create event
    $stmt = $DB->prepare("
        INSERT INTO calendar_events 
        (company_id, calendar_id, title, description, location, color, 
         start_datetime, end_datetime, all_day, recurrence, visibility, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $calendarId,
        $title,
        $description,
        $location,
        $color,
        $startDatetime,
        $endDatetime,
        $allDay ? 1 : 0,
        $recurrence,
        $visibility,
        $userId
    ]);

    $eventId = $DB->lastInsertId();

    // Add organizer as participant
    $stmt = $DB->prepare("
        INSERT INTO calendar_event_participants (event_id, user_id, role, response, response_at)
        VALUES (?, ?, 'organizer', 'accepted', NOW())
    ");
    $stmt->execute([$eventId, $userId]);

    // Add other participants
    foreach ($participants as $participant) {
        $pUserId = $participant['user_id'] ?? null;
        $pRole = $participant['role'] ?? 'required';
        
        if ($pUserId && $pUserId != $userId) {
            $stmt = $DB->prepare("
                INSERT INTO calendar_event_participants (event_id, user_id, role, response)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$eventId, $pUserId, $pRole]);
        }
    }

    // Add reminders
    foreach ($reminders as $reminder) {
        $minutes = $reminder['minutes_before'] ?? 15;
        $channel = $reminder['channel'] ?? 'in_app';
        
        $stmt = $DB->prepare("
            INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$eventId, $userId, $minutes, $channel]);
    }

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'event_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['event_id' => $eventId, 'title' => $title, 'calendar_id' => $calendarId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'message' => 'Event created successfully'
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Event create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create event']);
}