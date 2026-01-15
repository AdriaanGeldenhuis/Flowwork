<?php
// /calendar/ajax/event_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$eventId = $_GET['id'] ?? null;

if (!$eventId) {
    echo json_encode(['ok' => false, 'error' => 'Event ID required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT 
            e.*,
            c.name as calendar_name,
            c.color as calendar_color,
            u.first_name, u.last_name
        FROM calendar_events e
        JOIN calendars c ON e.calendar_id = c.id
        JOIN users u ON e.created_by = u.id
        WHERE e.id = ? AND e.company_id = ?
    ");
    $stmt->execute([$eventId, $companyId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }

    // Get participants
    $stmt = $DB->prepare("
        SELECT 
            p.user_id, p.role, p.response, p.response_at,
            u.first_name, u.last_name, u.email
        FROM calendar_event_participants p
        JOIN users u ON p.user_id = u.id
        WHERE p.event_id = ?
    ");
    $stmt->execute([$eventId]);
    $event['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get reminders
    $stmt = $DB->prepare("
        SELECT minutes_before, channel, sent_at, snoozed_until
        FROM calendar_event_reminders
        WHERE event_id = ? AND user_id = ?
    ");
    $stmt->execute([$eventId, $userId]);
    $event['reminders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get attachments
    $stmt = $DB->prepare("
        SELECT id, file_name, file_size, mime_type, uploaded_at
        FROM calendar_event_attachments
        WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
    $event['attachments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get links
    $stmt = $DB->prepare("
        SELECT linked_type, linked_id
        FROM calendar_event_links
        WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
    $event['links'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON
    $event['exdates'] = json_decode($event['exdates_json'] ?? '[]', true);
    unset($event['exdates_json']);

    echo json_encode(['ok' => true, 'event' => $event]);

} catch (Exception $e) {
    error_log("Event get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load event']);
}