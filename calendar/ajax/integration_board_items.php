<?php
// /calendar/ajax/integration_board_items.php
// Sync board items with due dates to calendar
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Get tasks calendar
    $stmt = $DB->prepare("
        SELECT id FROM calendars 
        WHERE company_id = ? AND calendar_type = 'team' AND name = 'Tasks'
    ");
    $stmt->execute([$companyId]);
    $cal = $stmt->fetch();

    if (!$cal) {
        $stmt = $DB->prepare("
            INSERT INTO calendars (company_id, calendar_type, name, color, owner_id, ics_token, created_at)
            VALUES (?, 'team', 'Tasks', '#f59e0b', ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, bin2hex(random_bytes(32))]);
        $calendarId = $DB->lastInsertId();
    } else {
        $calendarId = $cal['id'];
    }

    // Fetch board items with due dates
    $stmt = $DB->prepare("
        SELECT 
            i.id, i.title, i.due_date, i.start_date, i.end_date,
            b.title as board_title, p.name as project_name
        FROM board_items i
        JOIN project_boards b ON i.board_id = b.board_id
        JOIN projects p ON b.project_id = p.project_id
        WHERE i.company_id = ? 
        AND i.archived = 0
        AND (i.due_date IS NOT NULL OR (i.start_date IS NOT NULL AND i.end_date IS NOT NULL))
        AND i.due_date >= CURDATE()
    ");
    $stmt->execute([$companyId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $synced = 0;

    foreach ($items as $item) {
        // Check if exists
        $stmt = $DB->prepare("
            SELECT id FROM calendar_events e
            JOIN calendar_event_links l ON e.id = l.event_id
            WHERE l.linked_type = 'item' AND l.linked_id = ? AND e.company_id = ?
        ");
        $stmt->execute([$item['id'], $companyId]);
        $existing = $stmt->fetch();

        if (!$existing && $item['due_date']) {
            // Create
            $stmt = $DB->prepare("
                INSERT INTO calendar_events
                (company_id, calendar_id, title, description, start_datetime, end_datetime, all_day, color, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, '#f59e0b', ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $calendarId,
                '✓ ' . $item['title'],
                'Project: ' . $item['project_name'] . "\nBoard: " . $item['board_title'],
                $item['due_date'] . ' 00:00:00',
                $item['due_date'] . ' 23:59:59',
                $userId
            ]);

            $eventId = $DB->lastInsertId();

            // Link
            $stmt = $DB->prepare("
                INSERT INTO calendar_event_links (event_id, linked_type, linked_id)
                VALUES (?, 'item', ?)
            ");
            $stmt->execute([$eventId, $item['id']]);

            $synced++;
        }
    }

    echo json_encode(['ok' => true, 'synced' => $synced]);

} catch (Exception $e) {
    error_log("Board items sync error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Sync failed']);
}