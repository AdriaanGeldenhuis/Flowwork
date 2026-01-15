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
    
    if (empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    
    $itemId = (int)($_POST['item_id'] ?? 0);
    $newGroupId = (int)($_POST['group_id'] ?? 0);
    $newPosition = isset($_POST['position']) ? (int)$_POST['position'] : null;
    
    if (!$itemId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Item ID required']));
    }
    
    if (!$newGroupId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Group ID required']));
    }
    
    // Verify item
    $stmt = $DB->prepare("
        SELECT board_id 
        FROM board_items 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Item not found']));
    }
    
    $DB->beginTransaction();
    
    try {
        if ($newPosition === null) {
            $stmt = $DB->prepare("
                SELECT COALESCE(MAX(position), 0) 
                FROM board_items 
                WHERE board_id = ? AND company_id = ? AND group_id = ?
            ");
            $stmt->execute([$item['board_id'], $COMPANY_ID, $newGroupId]);
            $newPosition = (int)$stmt->fetchColumn() + 1;
        }
        
        $stmt = $DB->prepare("
            UPDATE board_items 
            SET group_id = ?, position = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$newGroupId, $newPosition, $itemId, $COMPANY_ID]);
        
        $DB->commit();
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Item moved',
            'data' => [
                'moved' => true, 
                'position' => $newPosition
            ]
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
    // If beforeItemId provided, insert before that item
	if (isset($_POST['before_item_id']) && $_POST['before_item_id']) {
    	   $beforeItemId = (int)$_POST['before_item_id'];
    
    // Get position of target item
    		$stmt = $DB->prepare("
       		 SELECT position 
        		FROM board_items 
        		WHERE id = ? AND group_id = ?
    ");
    		$stmt->execute([$beforeItemId, $newGroupId]);
    		$targetPosition = (int)$stmt->fetchColumn();
    
    // Shift items down
    		$stmt = $DB->prepare("
        		UPDATE board_items 
        		SET position = position + 1 
        		WHERE group_id = ? AND position >= ?
    		");
    		$stmt->execute([$newGroupId, $targetPosition]);
    
   		$newPosition = $targetPosition;
	}

} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Item Move Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;