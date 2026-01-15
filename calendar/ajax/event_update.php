<?php
// /calendar/ajax/event_update.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$eventId = $input['event_id'] ?? null;
$updates = $input['updates'] ?? [];

if (!$eventId || empty($updates)) {
    echo json_encode(['ok' => false, 'error' => 'Event ID and updates required']);
    exit;
}

// Verify access
$stmt = $DB->prepare("
    SELECT e.id, e.calendar_id, c.owner_id
    FROM calendar_events e
    JOIN calendars c ON e.calendar_id = c.id
    WHERE e.id = ? AND e.company_id = ?
");
$stmt->execute([$eventId, $companyId]);
$event = $stmt->fetch();

if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

// Check permissions
$isOwner = ($event['owner_id'] == $userId);
$isAdmin = in_array($_SESSION['role'], ['admin']);

if (!$isOwner && !$isAdmin) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    $allowedFields = ['title', 'description', 'location', 'color', 'start_datetime', 'end_datetime', 'all_day', 'visibility'];
    $setClauses = [];
    $params = [];

    foreach ($updates as $field => $value) {
        if (in_array($field, $allowedFields)) {
            $setClauses[] = "$field = ?";
            $params[] = $value;
        }
    }

    if (empty($setClauses)) {
        echo json_encode(['ok' => false, 'error' => 'No valid updates']);
        exit;
    }

    $setClauses[] = "updated_at = NOW()";
    $params[] = $eventId;
    $params[] = $companyId;

    $sql = "UPDATE calendar_events SET " . implode(', ', $setClauses) . " WHERE id = ? AND company_id = ?";
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'event_updated', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['event_id' => $eventId, 'updates' => array_keys($updates)]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode(['ok' => true, 'message' => 'Event updated successfully']);

} catch (Exception $e) {
    error_log("Event update error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to update event']);
}