<?php
/**
 * Create Subitem API
 * POST /projects/api/subitem/create.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    // Fix: THREE levels up
    $initPath = __DIR__ . '/../../../init.php';
    $authPath = __DIR__ . '/../../../auth_gate.php';
    
    if (!file_exists($initPath)) {
        throw new Exception("init.php not found at: $initPath");
    }
    
    require_once $initPath;
    
    if (!file_exists($authPath)) {
        throw new Exception("auth_gate.php not found at: $authPath");
    }
    
    require_once $authPath;

    if (!isset($DB) || !($DB instanceof PDO)) {
        throw new Exception('Database connection not available');
    }

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
        http_response_code(401);
        throw new Exception('Not authenticated');
    }

    $COMPANY_ID = (int)$_SESSION['company_id'];
    $USER_ID = (int)$_SESSION['user_id'];

    // Validate CSRF
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        throw new Exception('Invalid CSRF token');
    }

    $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';

    if ($itemId <= 0) {
        http_response_code(400);
        throw new Exception('Valid item ID is required');
    }

    if (empty($title)) {
        http_response_code(400);
        throw new Exception('Subitem title is required');
    }

    // Get parent item and board info
    $stmt = $DB->prepare("
        SELECT bi.board_id, pb.company_id
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$parent) {
        http_response_code(404);
        throw new Exception('Parent item not found or access denied');
    }

    // Get next position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 AS next_pos
        FROM board_subitems
        WHERE parent_item_id = ?
    ");
    $stmt->execute([$itemId]);
    $position = (int)$stmt->fetchColumn();

    // Create subitem
    $stmt = $DB->prepare("
        INSERT INTO board_subitems (
            parent_item_id, board_id, company_id, title, 
            position, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $itemId,
        $parent['board_id'],
        $COMPANY_ID,
        $title,
        $position,
        $USER_ID
    ]);

    $subitemId = (int)$DB->lastInsertId();

    // Update parent item subitem count (if column exists)
    try {
        $stmt = $DB->prepare("
            UPDATE board_items
            SET subitem_count = (
                SELECT COUNT(*) FROM board_subitems WHERE parent_item_id = ?
            )
            WHERE id = ?
        ");
        $stmt->execute([$itemId, $itemId]);
    } catch (PDOException $e) {
        error_log("Subitem count update failed: " . $e->getMessage());
    }

    ob_end_clean();
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'subitem_id' => $subitemId,
        'message' => 'Subitem created'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    error_log("Create subitem DB error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Create subitem error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

exit;