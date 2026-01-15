<?php
/**
 * Delete Subitem API
 * POST /projects/api/subitem/delete.php
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

    // Validate CSRF
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        throw new Exception('Invalid CSRF token');
    }

    $subitemId = isset($_POST['subitem_id']) ? (int)$_POST['subitem_id'] : 0;

    if ($subitemId <= 0) {
        http_response_code(400);
        throw new Exception('Valid subitem ID is required');
    }

    // Get parent item ID before deletion
    $stmt = $DB->prepare("SELECT parent_item_id FROM board_subitems WHERE id = ? AND company_id = ?");
    $stmt->execute([$subitemId, $COMPANY_ID]);
    $parentId = $stmt->fetchColumn();

    if (!$parentId) {
        http_response_code(404);
        throw new Exception('Subitem not found or access denied');
    }

    // Delete subitem
    $stmt = $DB->prepare("DELETE FROM board_subitems WHERE id = ? AND company_id = ?");
    $stmt->execute([$subitemId, $COMPANY_ID]);

    // Update parent count (if column exists)
    try {
        $stmt = $DB->prepare("
            UPDATE board_items
            SET subitem_count = (
                SELECT COUNT(*) FROM board_subitems WHERE parent_item_id = ?
            )
            WHERE id = ?
        ");
        $stmt->execute([$parentId, $parentId]);
    } catch (PDOException $e) {
        error_log("Subitem count update failed: " . $e->getMessage());
    }

    ob_end_clean();
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Subitem deleted'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    error_log("Delete subitem DB error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Delete subitem error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

exit;