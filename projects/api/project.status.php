<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$projectId) respond_error('Project ID required');

$allowedStatuses = ['draft', 'active', 'on_hold', 'completed', 'archived', 'cancelled'];
if (!in_array($status, $allowedStatuses)) {
    respond_error('Invalid status');
}

require_project_role($projectId, 'manager');

try {
    // Verify project belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    if (!$stmt->fetch()) respond_error('Project not found', 404);

    // Update status
    $stmt = $DB->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$status, $projectId, $COMPANY_ID]);

    respond_ok();

} catch (Exception $e) {
    error_log("Project status error: " . $e->getMessage());
    respond_error('Failed to update project status', 500);
}
