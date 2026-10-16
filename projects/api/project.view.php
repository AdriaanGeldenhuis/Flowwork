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
    
    // Upcoming calendar events linked to this project (or its tasks) that this
    // user may see. (This used to return the company's five oldest events of
    // any calendar, private ones included.)
    $events = [];
    try {
        require_once __DIR__ . '/../../calendar/lib/autoload.php';
        $tl = CalendarLink::projectTimeline($DB, (int)$COMPANY_ID, (int)$USER_ID, (string)$USER_ROLE, (int)$projectId,
                                            date('Y-m-d'), date('Y-m-d', strtotime('+180 days')));
        foreach (array_slice($tl['events'], 0, 5) as $e) {
            $events[] = ['id' => $e['id'], 'title' => $e['title'], 'starts_at' => $e['start'], 'ends_at' => $e['end'],
                         'all_day' => $e['all_day'] ? 1 : 0, 'url' => '/calendar/?event=' . $e['id'] . '&date=' . substr($e['start'], 0, 10)];
        }
    } catch (Throwable $e) {
        error_log('Project view events: ' . $e->getMessage());
    }
    
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
    respond_error('Failed to load project', 500);
}