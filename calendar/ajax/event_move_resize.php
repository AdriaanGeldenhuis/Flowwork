<?php
// /calendar/ajax/event_move_resize.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$eventId = $input['event_id'] ?? null;
$newStart = $input['start_datetime'] ?? null;
$newEnd = $input['end_datetime'] ?? null;

if (!$eventId || !$newStart || !$newEnd) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    // Verify access
    $stmt = $DB->prepare("
        SELECT e.id FROM calendar_events e
        JOIN calendars c ON e.calendar_id = c.id
        WHERE e.id = ? AND e.company_id = ? 
        AND (c.owner_id = ? OR ? IN ('admin'))
    ");
    $stmt->execute([$eventId, $companyId, $userId, $_SESSION['role']]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Event not found or access denied']);
        exit;
    }

    $stmt = $DB->prepare("
        UPDATE calendar_events 
        SET start_datetime = ?, end_datetime = ?, updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$newStart, $newEnd, $eventId, $companyId]);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'event_moved', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['event_id' => $eventId, 'start' => $newStart, 'end' => $newEnd]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode(['ok' => true, 'message' => 'Event moved successfully']);

} catch (Exception $e) {
    error_log("Event move error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to move event']);
}