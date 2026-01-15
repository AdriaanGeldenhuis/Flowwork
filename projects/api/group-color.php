<?php
/**
 * Update Group Color API
 * POST /projects/api/group-color.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON header FIRST
header('Content-Type: application/json; charset=utf-8');

// Start output buffering
ob_start();

try {
    // Fix: Go up TWO directories to reach root
    // /projects/api/group-color.php -> /projects/ -> / (root)
    $initPath = __DIR__ . '/../../init.php';
    $authPath = __DIR__ . '/../../auth_gate.php';
    
    if (!file_exists($initPath)) {
        throw new Exception("init.php not found at: $initPath");
    }
    
    require_once $initPath;
    
    if (!file_exists($authPath)) {
        throw new Exception("auth_gate.php not found at: $authPath");
    }
    
    require_once $authPath;

    // Check database connection
    if (!isset($DB) || !($DB instanceof PDO)) {
        throw new Exception('Database connection not available');
    }

    // Check authentication
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
        http_response_code(401);
        throw new Exception('Not authenticated');
    }

    $COMPANY_ID = (int)$_SESSION['company_id'];
    $USER_ID = (int)$_SESSION['user_id'];

    // Validate CSRF token
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($token) || empty($sessionToken)) {
        http_response_code(403);
        throw new Exception('CSRF token missing');
    }
    
    if (!hash_equals($sessionToken, $token)) {
        http_response_code(403);
        throw new Exception('Invalid CSRF token');
    }

    // Get and validate input
    $groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
    $color = isset($_POST['color']) ? trim($_POST['color']) : '';

    if ($groupId <= 0) {
        http_response_code(400);
        throw new Exception('Valid group ID is required');
    }

    if (empty($color)) {
        http_response_code(400);
        throw new Exception('Color is required');
    }

    // Validate hex color format
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        http_response_code(400);
        throw new Exception('Invalid color format. Use hex format like #FF0000');
    }

    // Check if group exists and user has permission
    $stmt = $DB->prepare("
        SELECT bg.id, pb.company_id
        FROM board_groups bg
        JOIN project_boards pb ON bg.board_id = pb.board_id
        WHERE bg.id = ? AND pb.company_id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$groupId, $COMPANY_ID]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        http_response_code(404);
        throw new Exception('Group not found or no permission');
    }

    // Update the group color
    $stmt = $DB->prepare("
        UPDATE board_groups 
        SET color = ?
        WHERE id = ?
    ");
    
    $success = $stmt->execute([$color, $groupId]);

    if (!$success) {
        throw new Exception('Failed to update color in database');
    }

    // Clear output buffer and send success response
    ob_end_clean();
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Color updated successfully',
        'data' => [
            'group_id' => $groupId,
            'color' => $color
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    error_log("Group color DB error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => 'DB_ERROR'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Group color error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => 'ERROR'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

exit;