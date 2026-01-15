<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    if (empty($_SESSION['company_id'])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    
    $boardId = (int)($_GET['board_id'] ?? 0);
    
    if (!$boardId) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    // Check access
    $stmt = $DB->prepare("
        SELECT pb.*, p.name AS project_name
        FROM project_boards pb
        JOIN projects p ON pb.project_id = p.project_id
        WHERE pb.board_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Load columns
    $stmt = $DB->prepare("
        SELECT * FROM board_columns
        WHERE board_id = ? AND visible = 1
        ORDER BY position
    ");
    $stmt->execute([$boardId]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Load items
    $stmt = $DB->prepare("
        SELECT 
            bi.*,
            bg.name AS group_name,
            u.first_name,
            u.last_name
        FROM board_items bi
        LEFT JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
        ORDER BY bg.position, bi.position
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Load values
    $itemIds = array_column($items, 'id');
    $valuesMap = [];
    
    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $DB->prepare("
            SELECT item_id, column_id, value
            FROM board_item_values
            WHERE item_id IN ($placeholders)
        ");
        $stmt->execute($itemIds);
        
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $valuesMap[$row['item_id']][$row['column_id']] = $row['value'];
        }
    }
    
    // Build CSV
    $csvData = [];
    
    // Header
    $header = ['Group', 'Item'];
    foreach ($columns as $col) {
        $header[] = $col['name'];
    }
    $header[] = 'Created';
    $csvData[] = $header;
    
    // Rows
    foreach ($items as $item) {
        $row = [
            $item['group_name'] ?? '',
            $item['title']
        ];
        
        foreach ($columns as $col) {
            $value = $valuesMap[$item['id']][$col['column_id']] ?? '';
            
            if ($col['type'] === 'people' && $value) {
                $stmt = $DB->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $stmt->execute([$value]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $value = $user ? "{$user['first_name']} {$user['last_name']}" : '';
            } elseif ($col['type'] === 'date' && $value) {
                $value = date('Y-m-d', strtotime($value));
            }
            
            $row[] = $value;
        }
        
        $row[] = date('Y-m-d H:i', strtotime($item['created_at']));
        $csvData[] = $row;
    }
    
    // Output CSV
    $filename = 'board_' . $boardId . '_' . date('Y-m-d_His') . '.csv';
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    foreach ($csvData as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Export Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;