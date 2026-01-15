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
    
    if (!$itemId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Item ID required']));
    }
    
    // Verify item exists
    $stmt = $DB->prepare("
        SELECT bi.id 
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Item not found']));
    }
    
    $DB->beginTransaction();
    
    try {
        // Delete values first
        $stmt = $DB->prepare("DELETE FROM board_item_values WHERE item_id = ?");
        $stmt->execute([$itemId]);
        
        // Delete item
        $stmt = $DB->prepare("DELETE FROM board_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        $DB->commit();
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Item deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Delete Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;