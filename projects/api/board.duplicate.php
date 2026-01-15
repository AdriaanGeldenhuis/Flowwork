<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'editor');

try {
    $DB->beginTransaction();
    
    // Get original board
    $stmt = $DB->prepare("SELECT * FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) respond_error('Board not found', 404);
    
    // Create duplicate board
    $newTitle = $board['title'] . ' (Copy)';
    $stmt = $DB->prepare("INSERT INTO project_boards (company_id, project_id, board_type, title, default_view, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$COMPANY_ID, $board['project_id'], $board['board_type'], $newTitle, $board['default_view'], $board['description'], $board['sort_order']]);
    $newBoardId = $DB->lastInsertId();
    
    // Copy columns
    $stmt = $DB->prepare("SELECT * FROM board_columns WHERE board_id = ? ORDER BY position");
    $stmt->execute([$boardId]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnMap = [];
    
    foreach ($columns as $col) {
        $stmt = $DB->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, required, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$newBoardId, $COMPANY_ID, $col['name'], $col['type'], $col['config'], $col['position'], $col['visible'], $col['width'], $col['required']]);
        $columnMap[$col['column_id']] = $DB->lastInsertId();
    }
    
    // Copy groups
    $stmt = $DB->prepare("SELECT * FROM board_groups WHERE board_id = ? ORDER BY position");
    $stmt->execute([$boardId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $groupMap = [];
    
    foreach ($groups as $grp) {
        $stmt = $DB->prepare("INSERT INTO board_groups (board_id, name, position, color, collapsed, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$newBoardId, $grp['name'], $grp['position'], $grp['color'], $grp['collapsed']]);
        $groupMap[$grp['id']] = $DB->lastInsertId();
    }
    
    // Copy items and values
    $stmt = $DB->prepare("SELECT * FROM board_items WHERE board_id = ? ORDER BY position");
    $stmt->execute([$boardId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        $newGroupId = $groupMap[$item['group_id']] ?? null;
        if (!$newGroupId) continue;
        
        $stmt = $DB->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, description, position, status_label, assigned_to, priority, progress, due_date, start_date, end_date, tags, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$newBoardId, $COMPANY_ID, $newGroupId, $item['title'], $item['description'], $item['position'], $item['status_label'], $item['assigned_to'], $item['priority'], $item['progress'], $item['due_date'], $item['start_date'], $item['end_date'], $item['tags'], $USER_ID]);
        $newItemId = $DB->lastInsertId();
        
        // Copy item values
        $stmt = $DB->prepare("SELECT * FROM board_item_values WHERE item_id = ?");
        $stmt->execute([$item['id']]);
        $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($values as $val) {
            $newColId = $columnMap[$val['column_id']] ?? null;
            if (!$newColId) continue;
            
            $stmt = $DB->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
            $stmt->execute([$newItemId, $newColId, $val['value']]);
        }
    }
    
    $DB->commit();
    respond_ok(['board_id' => $newBoardId, 'title' => $newTitle]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Board duplicate error: " . $e->getMessage());
    respond_error('Failed to duplicate board', 500);
}