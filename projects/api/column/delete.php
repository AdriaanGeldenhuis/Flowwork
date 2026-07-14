<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Alternative path method
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    $initPath = $basePath . '/init.php';
    $authPath = $basePath . '/auth_gate.php';
    
    if (!file_exists($initPath)) {
        throw new Exception("init.php not found at: $initPath");
    }
    
    require_once $initPath;
    require_once $authPath;
    
    // Clear ALL output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers FIRST
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Check if DB exists
    if (!isset($DB)) {
        throw new Exception('Database connection not available');
    }
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($csrfToken) || empty($sessionToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'CSRF token missing']));
    }
    
    if (!hash_equals($sessionToken, $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    // Validate auth
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = (int)$_SESSION['company_id'];
    $USER_ID = (int)$_SESSION['user_id'];
    
    // Get POST data
    $columnId = (int)($_POST['column_id'] ?? 0);
    
    if (!$columnId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Column ID required']));
    }
    
    // Verify column exists and belongs to company
    $stmt = $DB->prepare("
        SELECT bc.column_id, bc.board_id, bc.name, bc.type
        FROM board_columns bc
        JOIN project_boards pb ON bc.board_id = pb.board_id
        WHERE bc.column_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$columnId, $COMPANY_ID]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$column) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Column not found or access denied']));
    }

    // Authorization: caller must be able to edit this board before destroying a column.
    $USER_ROLE = $_SESSION['role'] ?? 'viewer';
    require_once __DIR__ . '/../_guard.php';
    require_board_role((int)$column['board_id'], 'member');

    // Start transaction
    $DB->beginTransaction();
    
    try {
        // Delete column values first (foreign key constraint)
        $stmt = $DB->prepare("
            DELETE biv 
            FROM board_item_values biv
            INNER JOIN board_items bi ON biv.item_id = bi.id
            WHERE biv.column_id = ? AND bi.company_id = ?
        ");
        $stmt->execute([$columnId, $COMPANY_ID]);
        $deletedValues = $stmt->rowCount();
        
        // Delete the column itself
        $stmt = $DB->prepare("DELETE FROM board_columns WHERE column_id = ?");
        $stmt->execute([$columnId]);
        $deletedColumn = $stmt->rowCount();
        
        if ($deletedColumn === 0) {
            throw new Exception("Column could not be deleted");
        }
        
        // Commit transaction
        $DB->commit();
        
        // Success response
        echo json_encode([
            'ok' => true,
            'message' => 'Column deleted successfully',
            'deleted_values' => $deletedValues,
            'column_name' => $column['name']
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw new Exception("Transaction failed: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    // Clear any partial output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Log the error
    error_log("Column delete error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Return a generic error — details are logged server-side, not exposed.
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to delete column'
    ]);
}