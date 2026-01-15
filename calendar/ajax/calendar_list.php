<?php
// /calendar/ajax/calendar_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Get user's own calendars
    $stmt = $DB->prepare("
        SELECT 
            c.id,
            c.calendar_type,
            c.name,
            c.description,
            c.color,
            c.owner_id,
            c.project_id,
            c.is_active,
            (SELECT COUNT(*) FROM calendar_events WHERE calendar_id = c.id) as event_count
        FROM calendars c
        WHERE c.company_id = ? 
        AND (c.owner_id = ? OR c.calendar_type IN ('team', 'resource'))
        ORDER BY c.calendar_type, c.name
    ");
    $stmt->execute([$companyId, $userId]);
    $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse sharing JSON
    foreach ($calendars as &$cal) {
        $cal['sharing'] = json_decode($cal['sharing_json'] ?? '[]', true);
        unset($cal['sharing_json']);
        $cal['can_edit'] = ($cal['owner_id'] == $userId || in_array($_SESSION['role'], ['admin']));
    }

    echo json_encode(['ok' => true, 'calendars' => $calendars]);

} catch (Exception $e) {
    error_log("Calendar list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load calendars']);
}