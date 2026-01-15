<?php
/**
 * Create Item API - CORRECTED PATHS
 */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // ✅ CORRECT PATH
    require_once dirname(dirname(dirname(__FILE__))) . '/init.php';
    require_once dirname(dirname(dirname(__FILE__))) . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Authentication required']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $groupId = (int)($_POST['group_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    if (!$groupId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Group ID required']));
    }
    
    if (empty($title)) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Title required']));
    }
    
    // Verify board
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards 
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Verify group
    $stmt = $DB->prepare("
        SELECT id FROM board_groups 
        WHERE id = ? AND board_id = ?
    ");
    $stmt->execute([$groupId, $boardId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Group not found']));
    }
    
    // Get position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 
        FROM board_items 
        WHERE group_id = ? AND archived = 0
    ");
    $stmt->execute([$groupId]);
    $nextPos = (int)$stmt->fetchColumn();
    
    // Insert
    $stmt = $DB->prepare("
        INSERT INTO board_items (
            board_id, company_id, group_id, title, 
            position, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $boardId, 
        $COMPANY_ID, 
        $groupId, 
        $title, 
        $nextPos, 
        $USER_ID
    ]);
    
    $itemId = (int)$DB->lastInsertId();
    
    // Fetch item
    $stmt = $DB->prepare("
        SELECT 
            bi.*,
            bg.name AS group_name,
            u.first_name,
            u.last_name
        FROM board_items bi
        LEFT JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(500);
        die(json_encode(['ok' => false, 'error' => 'Failed to retrieve item']));
    }
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Item created successfully',
        'data' => [
            'item_id' => $itemId,
            'item' => $item
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Create Item Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
exit;