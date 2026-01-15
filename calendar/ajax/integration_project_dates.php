<?php
// /calendar/ajax/integration_project_dates.php
// Sync project dates to calendar events
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Check user setting for project date integration
    $stmt = $DB->prepare("SELECT enable_project_dates FROM calendar_settings WHERE company_id = ? AND user_id = ?");
    $stmt->execute([$companyId, $userId]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($setting && (int)$setting['enable_project_dates'] === 0) {
        echo json_encode(['ok' => true, 'synced' => 0, 'disabled' => true]);
        return;
    }
    // Get project calendar (or create)
    $stmt = $DB->prepare("
        SELECT id FROM calendars 
        WHERE company_id = ? AND calendar_type = 'project' AND name = 'Projects'
    ");
    $stmt->execute([$companyId]);
    $cal = $stmt->fetch();

    if (!$cal) {
        $stmt = $DB->prepare("
            INSERT INTO calendars (company_id, calendar_type, name, color, owner_id, ics_token, created_at)
            VALUES (?, 'project', 'Projects', '#8b5cf6', ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, bin2hex(random_bytes(32))]);
        $calendarId = $DB->lastInsertId();
    } else {
        $calendarId = $cal['id'];
    }

    // Fetch projects with dates
    $stmt = $DB->prepare("
        SELECT project_id, name, start_date, end_date
        FROM projects
        WHERE company_id = ? AND status IN ('active', 'on_hold')
        AND start_date IS NOT NULL AND end_date IS NOT NULL
    ");
    $stmt->execute([$companyId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $synced = 0;

    foreach ($projects as $proj) {
        // Check if event exists
        $stmt = $DB->prepare("
            SELECT id FROM calendar_events e
            JOIN calendar_event_links l ON e.id = l.event_id
            WHERE l.linked_type = 'project' AND l.linked_id = ? AND e.company_id = ?
        ");
        $stmt->execute([$proj['project_id'], $companyId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update
            $stmt = $DB->prepare("
                UPDATE calendar_events
                SET title = ?, start_datetime = ?, end_datetime = ?, all_day = 1, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                'Project: ' . $proj['name'],
                $proj['start_date'] . ' 00:00:00',
                $proj['end_date'] . ' 23:59:59',
                $existing['id'],
                $companyId
            ]);
        } else {
            // Create
            $stmt = $DB->prepare("
                INSERT INTO calendar_events
                (company_id, calendar_id, title, start_datetime, end_datetime, all_day, color, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, 1, '#8b5cf6', ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $calendarId,
                'Project: ' . $proj['name'],
                $proj['start_date'] . ' 00:00:00',
                $proj['end_date'] . ' 23:59:59',
                $userId
            ]);

            $eventId = $DB->lastInsertId();

            // Link
            $stmt = $DB->prepare("
                INSERT INTO calendar_event_links (event_id, linked_type, linked_id)
                VALUES (?, 'project', ?)
            ");
            $stmt->execute([$eventId, $proj['project_id']]);
        }

        $synced++;
    }

    echo json_encode(['ok' => true, 'synced' => $synced]);

} catch (Exception $e) {
    error_log("Project sync error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Sync failed']);
}