<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';

$boardId = (int)($_GET['board_id'] ?? 0);
if (!$boardId) {
    http_response_code(400);
    die(json_encode(['ok' => false, 'error' => 'Board ID required']));
}

require_board_role($boardId, 'viewer');

try {
    // Get board
    $stmt = $DB->prepare("SELECT * FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Get columns
    $stmt = $DB->prepare("SELECT column_id, name, type, width FROM board_columns WHERE board_id = ? ORDER BY position");
    $stmt->execute([$boardId]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get groups with items
    $stmt = $DB->prepare("SELECT * FROM board_groups WHERE board_id = ? ORDER BY position");
    $stmt->execute([$boardId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($groups as &$group) {
        $stmt = $DB->prepare("
            SELECT id, title, description, status_label, priority
            FROM board_items 
            WHERE board_id = ? AND group_id = ? AND archived = 0
            ORDER BY position
        ");
        $stmt->execute([$boardId, $group['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get values for each item
        foreach ($items as &$item) {
            $stmt = $DB->prepare("
                SELECT biv.column_id, bc.name as column_name, biv.value
                FROM board_item_values biv
                JOIN board_columns bc ON biv.column_id = bc.column_id
                WHERE biv.item_id = ?
            ");
            $stmt->execute([$item['id']]);
            $values = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values[$row['column_name']] = $row['value'];
            }
            $item['values'] = $values;
            unset($item['id']); // Remove DB ID for template
        }
        
        $group['items'] = $items;
        unset($group['id']); // Remove DB ID
    }
    
    $template = [
        'name' => $board['title'],
        'description' => 'Exported template from board ' . $boardId,
        'version' => '1.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'boards' => [
            [
                'title' => $board['title'],
                'type' => $board['board_type'],
                'default_view' => $board['default_view'],
                'columns' => array_map(function($col) {
                    return [
                        'id' => $col['name'],
                        'name' => $col['name'],
                        'type' => $col['type'],
                        'width' => $col['width']
                    ];
                }, $columns),
                'groups' => $groups
            ]
        ]
    ];
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="board-' . $boardId . '-template.json"');
    echo json_encode($template, JSON_PRETTY_PRINT);
    exit;
    
} catch (Exception $e) {
    error_log("Template export error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'Export failed']));
}