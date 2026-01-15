<?php
// /calendar/ajax/calendar_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$name = trim($input['name'] ?? '');
$type = $input['type'] ?? 'personal';
$color = $input['color'] ?? '#06b6d4';
$description = trim($input['description'] ?? '');
$projectId = $input['project_id'] ?? null;

if (empty($name)) {
    echo json_encode(['ok' => false, 'error' => 'Calendar name is required']);
    exit;
}

if (!in_array($type, ['personal', 'team', 'project', 'resource'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid calendar type']);
    exit;
}

try {
    // Generate ICS token
    $icsToken = bin2hex(random_bytes(32));

    $stmt = $DB->prepare("
        INSERT INTO calendars 
        (company_id, calendar_type, name, description, color, owner_id, project_id, ics_token, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $type,
        $name,
        $description,
        $color,
        $userId,
        $projectId,
        $icsToken
    ]);

    $calendarId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'calendar_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['calendar_id' => $calendarId, 'name' => $name, 'type' => $type]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode([
        'ok' => true,
        'calendar_id' => $calendarId,
        'message' => 'Calendar created successfully'
    ]);

} catch (Exception $e) {
    error_log("Calendar create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create calendar']);
}