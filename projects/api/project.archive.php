<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'owner');

try {
    // Verify project belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    if (!$stmt->fetch()) respond_error('Project not found', 404);
    
    // Archive project (set archived flag)
    $stmt = $DB->prepare("UPDATE projects SET archived = 1, updated_at = NOW() WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    
    respond_ok();
    
} catch (Exception $e) {
    error_log("Project archive error: " . $e->getMessage());
    respond_error('Failed to archive project: ' . $e->getMessage(), 500);
}