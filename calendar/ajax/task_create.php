<?php
// /calendar/ajax/task_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$calendarId = $input['calendar_id'] ?? null;
$title = trim($input['title'] ?? '');
$dueDatetime = $input['due_datetime'] ?? null;
$priority = $input['priority'] ?? 'medium';

if (!$calendarId || empty($title)) {
    echo json_encode(['ok' => false, 'error' => 'Calendar ID and title required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        INSERT INTO calendar_tasks 
        (company_id, calendar_id, user_id, title, due_datetime, priority, position, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$companyId, $calendarId, $userId, $title, $dueDatetime, $priority]);

    $taskId = $DB->lastInsertId();

    echo json_encode([
        'ok' => true,
        'task_id' => $taskId,
        'message' => 'Task created successfully'
    ]);

} catch (Exception $e) {
    error_log("Task create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create task']);
}