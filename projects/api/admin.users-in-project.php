<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

// Admin or project manager only
if ($USER_ROLE !== 'admin') {
    require_project_role($projectId, 'manager');
}

try {
    // Get project members with roles
    $stmt = $DB->prepare("
        SELECT 
            pm.member_id, pm.role, pm.can_edit, pm.added_at,
            u.id as user_id, u.first_name, u.last_name, u.email, u.role as global_role, u.is_seat
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND pm.company_id = ?
        ORDER BY pm.role, u.first_name
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available users to add
    $stmt = $DB->prepare("
        SELECT id, first_name, last_name, email, role
        FROM users
        WHERE company_id = ? AND status = 'active'
        AND id NOT IN (
            SELECT user_id FROM project_members WHERE project_id = ?
        )
        ORDER BY first_name
    ");
    $stmt->execute([$COMPANY_ID, $projectId]);
    $availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok([
        'members' => $members,
        'available_users' => $availableUsers
    ]);
    
} catch (Exception $e) {
    error_log("Admin users in project error: " . $e->getMessage());
    respond_error('Failed to load project members', 500);
}