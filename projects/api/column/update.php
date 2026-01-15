<?php
/**
 * API: Update column settings
 * POST /projects/api/column/update.php
 * 
 * Body:
 *   column_id: int
 *   name: string (optional)
 *   width: int (optional)
 *   config: json (optional)
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // ✅ USE CORRECT PATH METHOD
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    // Clear output buffer
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    
    // CSRF validation
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['company_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $columnId = (int)($_POST['column_id'] ?? 0);
    
    // Log received data for debugging
    error_log("Column Update - Column ID: $columnId, POST data: " . json_encode($_POST));
    
    // Validation
    if (!$columnId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Column ID required']));
    }
    
    // Verify column belongs to company
    $stmt = $DB->prepare("
        SELECT bc.column_id, bc.board_id, bc.type, bc.name, bc.width, bc.config
        FROM board_columns bc
        JOIN project_boards pb ON bc.board_id = pb.board_id
        WHERE bc.column_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$columnId, $COMPANY_ID]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$column) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Column not found']));
    }
    
    // Build update query dynamically
    $updates = [];
    $params = [];
    
    // Update name
    if (isset($_POST['name'])) {
        $name = trim($_POST['name']);
        if (empty($name)) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'Column name cannot be empty']));
        }
        $updates[] = "name = ?";
        $params[] = $name;
        error_log("Updating name to: $name");
    }
    
    // Update width
    if (isset($_POST['width'])) {
        $width = (int)$_POST['width'];
        if ($width < 80 || $width > 500) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'Width must be between 80 and 500']));
        }
        $updates[] = "width = ?";
        $params[] = $width;
        error_log("Updating width to: $width");
    }
    
    // Update config
    if (isset($_POST['config'])) {
        $config = trim($_POST['config']);
        
        // Validate JSON
        if ($config !== '' && $config !== null) {
            $decoded = json_decode($config);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                die(json_encode(['ok' => false, 'error' => 'Invalid JSON config: ' . json_last_error_msg()]));
            }
        }
        
        $updates[] = "config = ?";
        $params[] = ($config === '' ? null : $config);
        error_log("Updating config to: $config");
    }
    
    // Check if there's anything to update
    if (empty($updates)) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'No fields to update']));
    }
    
    // Add column_id to params
    $params[] = $columnId;
    
    // Execute update
    $sql = "UPDATE board_columns SET " . implode(', ', $updates) . " WHERE column_id = ?";
    error_log("Executing SQL: $sql with params: " . json_encode($params));
    
    $stmt = $DB->prepare($sql);
    $result = $stmt->execute($params);
    
    if (!$result) {
        throw new Exception('Failed to update column: ' . implode(', ', $stmt->errorInfo()));
    }
    
    error_log("Column updated successfully: ID $columnId");
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Column updated successfully',
        'data' => [
            'column_id' => $columnId,
            'updated_fields' => array_keys($_POST)
        ]
    ]);
    
} catch (PDOException $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Column Update DB Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Column Update Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to update column: ' . $e->getMessage()
    ]);
}
exit;