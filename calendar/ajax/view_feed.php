<?php
// /calendar/ajax/view_feed.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$view = $_GET['view'] ?? 'week';
$rangeStart = $_GET['range_start'] ?? date('Y-m-d H:i:s', strtotime('-7 days'));
$rangeEnd = $_GET['range_end'] ?? date('Y-m-d H:i:s', strtotime('+30 days'));
$calendarIds = $_GET['calendar_ids'] ?? [];

if (is_string($calendarIds)) {
    $calendarIds = explode(',', $calendarIds);
}

try {
    // Get events
    $where = ["e.company_id = ?", "e.start_datetime <= ?", "e.end_datetime >= ?"];
    $params = [$companyId, $rangeEnd, $rangeStart];

    if (!empty($calendarIds)) {
        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $where[] = "e.calendar_id IN ($placeholders)";
        $params = array_merge($params, $calendarIds);
    } else {
        // Get user's accessible calendars
        $where[] = "(c.owner_id = ? OR c.calendar_type IN ('team', 'resource'))";
        $params[] = $userId;
    }

    $sql = "
        SELECT 
            e.id, e.calendar_id, e.title, e.description, e.location,
            e.color, e.start_datetime, e.end_datetime, e.all_day,
            e.recurrence, e.visibility, e.created_by,
            c.name as calendar_name, c.color as calendar_color,
            (SELECT COUNT(*) FROM calendar_event_participants WHERE event_id = e.id) as participant_count
        FROM calendar_events e
        JOIN calendars c ON e.calendar_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.start_datetime ASC
    ";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Expand recurring events (simple implementation)
    $expandedEvents = [];
    foreach ($events as $event) {
        if ($event['recurrence']) {
            // TODO: Full RRULE expansion
            $expandedEvents[] = $event;
        } else {
            $expandedEvents[] = $event;
        }
    }

    // Get notes for date range
    $stmt = $DB->prepare("
        SELECT n.*, c.name as calendar_name, c.color as calendar_color
        FROM calendar_notes n
        JOIN calendars c ON n.calendar_id = c.id
        WHERE n.company_id = ? AND n.note_date BETWEEN DATE(?) AND DATE(?)
        ORDER BY n.note_date, n.pinned DESC
    ");
    $stmt->execute([$companyId, $rangeStart, $rangeEnd]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tasks (incomplete or due in range)
    $stmt = $DB->prepare("
        SELECT t.*, c.name as calendar_name, c.color as calendar_color
        FROM calendar_tasks t
        JOIN calendars c ON t.calendar_id = c.id
        WHERE t.company_id = ? AND t.user_id = ?
        AND (t.completed = 0 OR t.due_datetime BETWEEN ? AND ?)
        ORDER BY t.due_datetime ASC, t.priority DESC
    ");
    $stmt->execute([$companyId, $userId, $rangeStart, $rangeEnd]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'events' => $expandedEvents,
        'notes' => $notes,
        'tasks' => $tasks,
        'range' => ['start' => $rangeStart, 'end' => $rangeEnd]
    ]);

} catch (Exception $e) {
    error_log("View feed error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load calendar data']);
}