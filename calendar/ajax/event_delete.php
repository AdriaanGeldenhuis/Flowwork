<?php
// /calendar/ajax/event_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$eventId = $input['event_id'] ?? null;

if (!$eventId) {
    echo json_encode(['ok' => false, 'error' => 'Event ID required']);
    exit;
}

try {
    // Verify ownership
    $stmt = $DB->prepare("
        SELECT e.id, e.title FROM calendar_events e
        JOIN calendars c ON e.calendar_id = c.id
        WHERE e.id = ? AND e.company_id = ? 
        AND (c.owner_id = ? OR e.created_by = ? OR ? IN ('admin'))
    ");
    $stmt->execute([$eventId, $companyId, $userId, $userId, $_SESSION['role']]);
    $event = $stmt->fetch();

    if (!$event) {
        echo json_encode(['ok' => false, 'error' => 'Event not found or permission denied']);
        exit;
    }

    $DB->beginTransaction();

    // Delete related records (cascade will handle most)
    $stmt = $DB->prepare("DELETE FROM calendar_events WHERE id = ? AND company_id = ?");
    $stmt->execute([$eventId, $companyId]);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'event_deleted', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['event_id' => $eventId, 'title' => $event['title']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'message' => 'Event deleted successfully']);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Event delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete event']);
}