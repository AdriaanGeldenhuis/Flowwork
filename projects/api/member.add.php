<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$role = $_POST['role'] ?? 'viewer';

if (!$projectId) respond_error('Project ID required');
if (!$userId) respond_error('User ID required');

require_project_role($projectId, 'manager');

// FIXED: Use YOUR DB enum values
$allowedRoles = ['owner', 'manager', 'member', 'viewer'];
if (!in_array($role, $allowedRoles)) respond_error('Invalid role');

try {
    // Check if already member
    $stmt = $DB->prepare("SELECT member_id FROM project_members WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $userId]);
    if ($stmt->fetch()) respond_error('User is already a member');
    
    // Add member
    $stmt = $DB->prepare("
        INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $canEdit = in_array($role, ['owner', 'manager', 'member']) ? 1 : 0;
    $stmt->execute([$COMPANY_ID, $projectId, $userId, $role, $canEdit]);
    
    respond_ok(['member_id' => $DB->lastInsertId()]);
    
} catch (Exception $e) {
    error_log("Add member error: " . $e->getMessage());
    respond_error('Failed to add member', 500);
}