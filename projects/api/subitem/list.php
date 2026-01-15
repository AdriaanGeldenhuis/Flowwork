<?php
/**
 * List Subitems API
 * GET /projects/api/subitem/list.php?item_id=123
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    // Fix: Go up THREE directories to reach root
    // /projects/api/subitem/list.php -> /projects/api/ -> /projects/ -> / (root)
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
    $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

    if ($itemId <= 0) {
        http_response_code(400);
        throw new Exception('Valid item ID is required');
    }

    // Verify item exists and user has access
    $stmt = $DB->prepare("
        SELECT bi.id, pb.company_id
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        throw new Exception('Item not found or access denied');
    }

    // Get subitems
    $stmt = $DB->prepare("
        SELECT 
            s.*,
            u.first_name,
            u.last_name,
            u.email
        FROM board_subitems s
        LEFT JOIN users u ON s.assigned_to = u.id
        WHERE s.parent_item_id = ? AND s.company_id = ?
        ORDER BY s.position ASC, s.id ASC
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $subitems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'subitems' => $subitems
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    error_log("Subitems list DB error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Subitems list error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

exit;