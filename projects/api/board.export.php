<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$format = $_GET['format'] ?? 'csv';

if (!$boardId) {
    http_response_code(400);
    die('Board ID required');
}

require_board_role($boardId, 'viewer');

try {
    // Get board
    $stmt = $DB->prepare("SELECT title FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch();
    
    if (!$board) {
        http_response_code(404);
        die('Board not found');
    }
    
    // Get columns
    $stmt = $DB->prepare("SELECT column_id, name, type FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get items
    $stmt = $DB->prepare("
        SELECT 
            bi.id, bi.title, bi.status_label, bi.priority, bi.due_date,
            bg.name as group_name,
            u.first_name, u.last_name
        FROM board_items bi
        JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
        ORDER BY bg.position, bi.position
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get values for each item
    foreach ($items as &$item) {
        $stmt = $DB->prepare("
            SELECT biv.column_id, biv.value
            FROM board_item_values biv
            WHERE biv.item_id = ?
        ");
        $stmt->execute([$item['id']]);
        $values = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values[$row['column_id']] = $row['value'];
        }
        $item['values'] = $values;
    }
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="board-' . $boardId . '-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header row
        $headers = ['Group', 'Title', 'Status', 'Assigned To', 'Priority', 'Due Date'];
        foreach ($columns as $col) {
            $headers[] = $col['name'];
        }
        fputcsv($output, $headers);
        
        // Data rows
        foreach ($items as $item) {
            $assignee = ($item['first_name'] && $item['last_name']) 
                ? $item['first_name'] . ' ' . $item['last_name'] 
                : 'Unassigned';
            
            $row = [
                $item['group_name'],
                $item['title'],
                $item['status_label'] ?: '',
                $assignee,
                $item['priority'] ?: '',
                $item['due_date'] ?: ''
            ];
            
            foreach ($columns as $col) {
                $row[] = $item['values'][$col['column_id']] ?? '';
            }
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
} catch (Exception $e) {
    error_log("Board export error: " . $e->getMessage());
    http_response_code(500);
    die('Export failed');
}