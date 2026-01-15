<?php
// /calendar/ajax/event_participant_respond.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$eventId = $input['event_id'] ?? null;
$response = $input['response'] ?? null;

if (!$eventId || !in_array($response, ['accepted', 'declined', 'tentative'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    // Verify participant
    $stmt = $DB->prepare("
        SELECT p.id FROM calendar_event_participants p
        JOIN calendar_events e ON p.event_id = e.id
        WHERE p.event_id = ? AND p.user_id = ? AND e.company_id = ?
    ");
    $stmt->execute([$eventId, $userId, $companyId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'You are not a participant']);
        exit;
    }

    $stmt = $DB->prepare("
        UPDATE calendar_event_participants 
        SET response = ?, response_at = NOW()
        WHERE event_id = ? AND user_id = ?
    ");
    $stmt->execute([$response, $eventId, $userId]);

    echo json_encode(['ok' => true, 'message' => 'Response updated']);

} catch (Exception $e) {
    error_log("Participant respond error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to update response']);
}