<?php
// /calendar/ajax/task_complete_toggle.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$taskId = $input['task_id'] ?? null;

if (!$taskId) {
    echo json_encode(['ok' => false, 'error' => 'Task ID required']);
    exit;
}

try {
    // Get current state
    $stmt = $DB->prepare("
        SELECT completed FROM calendar_tasks 
        WHERE id = ? AND company_id = ? AND user_id = ?
    ");
    $stmt->execute([$taskId, $companyId, $userId]);
    $task = $stmt->fetch();

    if (!$task) {
        echo json_encode(['ok' => false, 'error' => 'Task not found']);
        exit;
    }

    $newCompleted = $task['completed'] ? 0 : 1;
    $completedAt = $newCompleted ? date('Y-m-d H:i:s') : null;

    $stmt = $DB->prepare("
        UPDATE calendar_tasks 
        SET completed = ?, completed_at = ?, updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$newCompleted, $completedAt, $taskId, $companyId]);

    echo json_encode([
        'ok' => true,
        'completed' => (bool)$newCompleted,
        'message' => 'Task updated successfully'
    ]);

} catch (Exception $e) {
    error_log("Task toggle error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to update task']);
}