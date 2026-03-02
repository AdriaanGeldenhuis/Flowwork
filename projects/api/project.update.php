<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$startDate = $_POST['start_date'] ?? null;
$endDate = $_POST['end_date'] ?? null;
$budget = $_POST['budget'] ?? null;
$managerId = $_POST['manager_user_id'] ?? null;

if (!$projectId || !$name) respond_error('Project ID and name required');

require_project_role($projectId, 'manager');

try {
    // Verify project belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    if (!$stmt->fetch()) respond_error('Project not found', 404);

    // Update project
    $stmt = $DB->prepare("
        UPDATE projects
        SET name = ?, start_date = ?, end_date = ?, budget = ?, project_manager_id = ?
        WHERE project_id = ? AND company_id = ?
    ");
    $stmt->execute([
        $name,
        $startDate ?: null,
        $endDate ?: null,
        $budget ?: null,
        $managerId ?: null,
        $projectId,
        $COMPANY_ID
    ]);

    respond_ok();

} catch (Exception $e) {
    error_log("Project update error: " . $e->getMessage());
    respond_error('Failed to update project', 500);
}