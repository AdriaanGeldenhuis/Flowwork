<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'member');

try {
    $DB->beginTransaction();
    
    // Get original project
    $stmt = $DB->prepare("SELECT * FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) respond_error('Project not found', 404);
    
    // Create duplicate project
    $newName = $project['name'] . ' (Copy)';
    $stmt = $DB->prepare("INSERT INTO projects (company_id, name, description, client, client_id, site_address, status, start_date, end_date, budget, project_manager_id, owner_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$COMPANY_ID, $newName, $project['description'], $project['client'], $project['client_id'], $project['site_address'], 'draft', $project['start_date'], $project['end_date'], $project['budget'], $project['project_manager_id'], $USER_ID, $USER_ID]);
    $newProjectId = $DB->lastInsertId();
    
    // Copy project members
    $stmt = $DB->prepare("INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at) SELECT company_id, ?, user_id, role, can_edit, NOW() FROM project_members WHERE project_id = ?");
    $stmt->execute([$newProjectId, $projectId]);
    
    // Copy boards
    $stmt = $DB->prepare("SELECT * FROM project_boards WHERE project_id = ? AND company_id = ? ORDER BY sort_order");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($boards as $board) {
        $stmt = $DB->prepare("INSERT INTO project_boards (company_id, project_id, board_type, title, default_view, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$COMPANY_ID, $newProjectId, $board['board_type'], $board['title'], $board['default_view'], $board['description'], $board['sort_order']]);
        $newBoardId = $DB->lastInsertId();
        
        // Copy board columns
        $stmt = $DB->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, required, created_at) SELECT ?, company_id, name, type, config, position, visible, width, required, NOW() FROM board_columns WHERE board_id = ?");
        $stmt->execute([$newBoardId, $board['board_id']]);
        
        // Copy board groups
        $stmt = $DB->prepare("INSERT INTO board_groups (board_id, name, position, color, collapsed, created_at) SELECT ?, name, position, color, collapsed, NOW() FROM board_groups WHERE board_id = ?");
        $stmt->execute([$newBoardId, $board['board_id']]);
    }
    
    $DB->commit();
    respond_ok(['project_id' => $newProjectId, 'name' => $newName]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Project duplicate error: " . $e->getMessage());
    respond_error('Failed to duplicate project', 500);
}