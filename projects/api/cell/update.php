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
    $itemId = (int)($_POST['item_id'] ?? 0);
    $columnId = (int)($_POST['column_id'] ?? 0);
    $value = isset($_POST['value']) ? $_POST['value'] : null;
    
    // Validate input
    if ($itemId <= 0) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Invalid item_id']));
    }
    
    if ($columnId <= 0) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Invalid column_id']));
    }
    
    // Verify item and column exist and belong to company
    $stmt = $DB->prepare("
        SELECT 
            bi.id,
            bi.board_id,
            bc.type,
            bc.name
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        JOIN board_columns bc ON bc.column_id = ? AND bc.board_id = bi.board_id
        WHERE bi.id = ? 
            AND pb.company_id = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . implode(' ', $DB->errorInfo()));
    }
    
    $stmt->execute([$columnId, $itemId, $COMPANY_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        http_response_code(404);
        die(json_encode([
            'ok' => false, 
            'error' => 'Item or column not found',
            'debug' => [
                'item_id' => $itemId,
                'column_id' => $columnId,
                'company_id' => $COMPANY_ID
            ]
        ]));
    }
    
    $columnType = $row['type'];
    $boardId = (int)$row['board_id'];
    
    // Start transaction
    $DB->beginTransaction();
    
    try {
        // Check if value exists
        $stmt = $DB->prepare("
            SELECT id 
            FROM board_item_values 
            WHERE item_id = ? AND column_id = ?
        ");
        $stmt->execute([$itemId, $columnId]);
        $existingId = $stmt->fetchColumn();
        
        if ($existingId) {
            // Update or delete
            if ($value === null || $value === '') {
                // Delete empty value
                $stmt = $DB->prepare("
                    DELETE FROM board_item_values 
                    WHERE item_id = ? AND column_id = ?
                ");
                $stmt->execute([$itemId, $columnId]);
            } else {
                // Update existing
                $stmt = $DB->prepare("
                    UPDATE board_item_values 
                    SET value = ? 
                    WHERE item_id = ? AND column_id = ?
                ");
                $stmt->execute([$value, $itemId, $columnId]);
            }
        } else {
            // Insert new (only if not empty)
            if ($value !== null && $value !== '') {
                $stmt = $DB->prepare("
                    INSERT INTO board_item_values (item_id, column_id, value) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$itemId, $columnId, $value]);
            }
        }
        
        // Update item timestamp
        $stmt = $DB->prepare("
            UPDATE board_items 
            SET updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$itemId]);
        
        // Commit transaction
        $DB->commit();
        
        // Success!
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Cell updated successfully',
            'data' => [
                'item_id' => $itemId,
                'column_id' => $columnId,
                'value' => $value,
                'column_type' => $columnType
            ]
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    // Database error
    while (ob_get_level() > 0) ob_end_clean();
    
    error_log("Cell Update DB Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    // General error
    while (ob_get_level() > 0) ob_end_clean();
    
    error_log("Cell Update Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

exit;