<?php
/**
 * Update Item API - FIXED VERSION
 */

// Start output buffering
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Load dependencies
    require_once dirname(dirname(__DIR__)) . '/init.php';
    require_once dirname(dirname(__DIR__)) . '/auth_gate.php';
    
    // Clear output buffer
    while (ob_get_level()) ob_end_clean();
    
    // Set headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    // Validate auth
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Authentication required']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $itemId = (int)($_POST['item_id'] ?? 0);
    
    if (!$itemId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Item ID required']));
    }
    
    // Verify item belongs to company
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
    
    // Build update query
    $updates = [];
    $params = [];
    
    // Map POST fields to database columns
    if (isset($_POST['title'])) {
        $updates[] = "title = ?";
        $params[] = trim($_POST['title']);
    }
    
    if (isset($_POST['description'])) {
        $updates[] = "description = ?";
        $params[] = trim($_POST['description']);
    }
    
    if (isset($_POST['group_id'])) {
        $updates[] = "group_id = ?";
        $params[] = (int)$_POST['group_id'];
    }
    
    if (isset($_POST['position'])) {
        $updates[] = "position = ?";
        $params[] = (int)$_POST['position'];
    }
    
    if (isset($_POST['status_label'])) {
        $updates[] = "status_label = ?";
        $params[] = $_POST['status_label'] ?: null;
    }
    
    if (isset($_POST['assigned_to'])) {
        $updates[] = "assigned_to = ?";
        $params[] = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
    }
    
    if (isset($_POST['priority'])) {
        $updates[] = "priority = ?";
        $params[] = $_POST['priority'] ?: null;
    }
    
    if (isset($_POST['progress'])) {
        $updates[] = "progress = ?";
        $params[] = (int)$_POST['progress'];
    }
    
    if (isset($_POST['due_date'])) {
        $updates[] = "due_date = ?";
        $params[] = $_POST['due_date'] ?: null;
    }
    
    if (isset($_POST['start_date'])) {
        $updates[] = "start_date = ?";
        $params[] = $_POST['start_date'] ?: null;
    }
    
    if (isset($_POST['end_date'])) {
        $updates[] = "end_date = ?";
        $params[] = $_POST['end_date'] ?: null;
    }
    
    if (isset($_POST['tags'])) {
        $updates[] = "tags = ?";
        $params[] = $_POST['tags'];
    }
    
    if (isset($_POST['archived'])) {
        $updates[] = "archived = ?";
        $params[] = (int)$_POST['archived'];
    }
    
    // Always update timestamp
    $updates[] = "updated_at = NOW()";
    
    if (empty($updates)) {
        http_response_code(200);
        die(json_encode([
            'ok' => true,
            'message' => 'No updates provided'
        ]));
    }
    
    // Execute update
    $params[] = $itemId;
    $sql = "UPDATE board_items SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Item updated successfully',
        'data' => [
            'item_id' => $itemId,
            'updated_fields' => count($updates) - 1 // -1 for updated_at
        ]
    ]);
    
} catch (Exception $e) {
    // Log error
    error_log("Item Update Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Clear output
    while (ob_get_level()) ob_end_clean();
    
    // Return error
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
exit;