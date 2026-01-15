<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'viewer');

try {
    // Get project
    $stmt = $DB->prepare("
        SELECT p.*, 
               u.first_name AS manager_first, u.last_name AS manager_last,
               u2.first_name AS owner_first, u2.last_name AS owner_last
        FROM projects p
        LEFT JOIN users u ON p.project_manager_id = u.id
        LEFT JOIN users u2 ON p.owner_id = u2.id
        WHERE p.project_id = ? AND p.company_id = ?
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) respond_error('Project not found', 404);
    
    // Get boards - FIXED: join met board_items vir count
    $stmt = $DB->prepare("
        SELECT pb.board_id, pb.title, pb.board_type, pb.default_view, pb.archived, pb.created_at,
               (SELECT COUNT(*) FROM board_items bi WHERE bi.board_id = pb.board_id) AS item_count
        FROM project_boards pb
        WHERE pb.project_id = ? AND pb.company_id = ?
        ORDER BY pb.created_at ASC
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming events
    $stmt = $DB->prepare("
        SELECT id, title, start_datetime AS starts_at, end_datetime AS ends_at, all_day
        FROM calendar_events
        WHERE company_id = ?
        ORDER BY start_datetime ASC
        LIMIT 5
    ");
    $stmt->execute([$COMPANY_ID]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get members - role is 'owner', 'manager', 'member', 'viewer' in YOUR DB
    $stmt = $DB->prepare("
        SELECT pm.*, u.first_name, u.last_name, u.email
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND pm.company_id = ?
        ORDER BY 
            CASE pm.role
                WHEN 'owner' THEN 1
                WHEN 'manager' THEN 2
                WHEN 'member' THEN 3
                WHEN 'viewer' THEN 4
            END,
            u.first_name
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok([
        'project' => $project,
        'boards' => $boards,
        'events' => $events,
        'members' => $members
    ]);
    
} catch (Exception $e) {
    error_log("Project view error: " . $e->getMessage());
    respond_error('Failed to load project: ' . $e->getMessage(), 500);
}