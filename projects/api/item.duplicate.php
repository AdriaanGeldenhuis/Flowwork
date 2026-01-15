<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $itemId = (int)($_POST['item_id'] ?? 0);
    
    if (!$itemId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Item ID required']));
    }
    
    // Get item
    $stmt = $DB->prepare("
        SELECT bi.* 
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Item not found']));
    }
    
    // Get position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 
        FROM board_items 
        WHERE group_id = ?
    ");
    $stmt->execute([$item['group_id']]);
    $nextPos = $stmt->fetchColumn();
    
    $DB->beginTransaction();
    
    try {
        // Insert duplicate
        $stmt = $DB->prepare("
            INSERT INTO board_items (
                board_id, company_id, group_id, title, description,
                position, status_label, assigned_to, priority, progress,
                due_date, start_date, end_date, tags, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $item['board_id'], 
            $COMPANY_ID, 
            $item['group_id'],
            $item['title'] . ' (Copy)', 
            $item['description'],
            $nextPos, 
            $item['status_label'], 
            $item['assigned_to'],
            $item['priority'], 
            $item['progress'], 
            $item['due_date'],
            $item['start_date'], 
            $item['end_date'], 
            $item['tags'], 
            $USER_ID
        ]);
        
        $newItemId = (int)$DB->lastInsertId();
        
        // Copy values
        $stmt = $DB->prepare("
            INSERT INTO board_item_values (item_id, column_id, value)
            SELECT ?, column_id, value 
            FROM board_item_values 
            WHERE item_id = ?
        ");
        $stmt->execute([$newItemId, $itemId]);
        
        $DB->commit();
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Item duplicated successfully',
            'data' => ['item_id' => $newItemId]
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Duplicate Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;