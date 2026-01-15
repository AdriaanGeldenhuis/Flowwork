<?php
/**
 * Update Subitem API
 * POST /projects/api/subitem/update.php
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

    // Build update query dynamically
    $updates = [];
    $params = [];

    if (isset($_POST['title'])) {
        $updates[] = 'title = ?';
        $params[] = trim($_POST['title']);
    }

    if (isset($_POST['status_label'])) {
        $updates[] = 'status_label = ?';
        $params[] = trim($_POST['status_label']);
    }

    if (isset($_POST['assigned_to'])) {
        $updates[] = 'assigned_to = ?';
        $params[] = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
    }

    if (isset($_POST['due_date'])) {
        $updates[] = 'due_date = ?';
        $params[] = $_POST['due_date'] ?: null;
    }

    if (isset($_POST['priority'])) {
        $updates[] = 'priority = ?';
        $params[] = trim($_POST['priority']);
    }

    if (isset($_POST['completed'])) {
        $updates[] = 'completed = ?';
        $params[] = (int)$_POST['completed'];
    }

    if (empty($updates)) {
        http_response_code(400);
        throw new Exception('No fields to update');
    }

    $updates[] = 'updated_at = NOW()';
    $params[] = $subitemId;
    $params[] = $COMPANY_ID;

    $sql = "UPDATE board_subitems SET " . implode(', ', $updates) . " WHERE id = ? AND company_id = ?";
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    ob_end_clean();
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Subitem updated'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    error_log("Update subitem DB error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Update subitem error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

exit;