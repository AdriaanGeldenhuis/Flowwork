<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$defaultView = $_POST['default_view'] ?? 'table';

if (!$projectId) respond_error('Project ID required');
if (!$title) respond_error('Board title required');

require_project_role($projectId, 'manager');

try {
    $DB->beginTransaction();
    
    // Default behaviour: create a basic work board with standard columns and a single group
    $stmt = $DB->prepare("
        INSERT INTO project_boards (company_id, project_id, title, board_type, default_view, is_active, created_at)
        VALUES (?, ?, ?, 'work', ?, 1, NOW())
    ");
    $stmt->execute([$COMPANY_ID, $projectId, $title, $defaultView]);
    $boardId = $DB->lastInsertId();
    
    // Create default columns
    $defaultCols = [
        ['Status', 'status', 1],
        ['Assignee', 'people', 2],
        ['Due Date', 'date', 3],
        ['Priority', 'priority', 4]
    ];
    
    $stmtColIns = $DB->prepare("
        INSERT INTO board_columns (board_id, company_id, name, type, position, visible)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    
    foreach ($defaultCols as $col) {
        $stmtColIns->execute([$boardId, $COMPANY_ID, $col[0], $col[1], $col[2]]);
    }
    
    // Create default group
    $stmtGrpIns = $DB->prepare("
        INSERT INTO board_groups (board_id, name, position, created_at)
        VALUES (?, 'Tasks', 0, NOW())
    ");
    $stmtGrpIns->execute([$boardId]);
    
    $DB->commit();
    respond_ok(['board_id' => $boardId]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Board create error: " . $e->getMessage());
    respond_error('Failed to create board', 500);
}