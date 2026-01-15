<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    
    $columnId = (int)($_POST['column_id'] ?? 0);
    $targetColumnId = (int)($_POST['target_column_id'] ?? 0);
    $insertBefore = (int)($_POST['insert_before'] ?? 0);
    
    if (!$columnId || !$targetColumnId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Column IDs required']));
    }
    
    $DB->beginTransaction();
    
    try {
        // Get current positions
        $stmt = $DB->prepare("
            SELECT column_id, position, board_id
            FROM board_columns
            WHERE column_id IN (?, ?) AND company_id = ?
        ");
        $stmt->execute([$columnId, $targetColumnId, $COMPANY_ID]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($columns) !== 2) {
            throw new Exception('Columns not found');
        }
        
        $draggedCol = null;
        $targetCol = null;
        
        foreach ($columns as $col) {
            if ($col['column_id'] == $columnId) {
                $draggedCol = $col;
            } else {
                $targetCol = $col;
            }
        }
        
        $boardId = $draggedCol['board_id'];
        
        // Get all columns for this board
        $stmt = $DB->prepare("
            SELECT column_id, position
            FROM board_columns
            WHERE board_id = ? AND company_id = ?
            ORDER BY position
        ");
        $stmt->execute([$boardId, $COMPANY_ID]);
        $allColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate new positions
        $newPositions = [];
        $newPos = 0;
        
        foreach ($allColumns as $col) {
            $cid = (int)$col['column_id'];
            
            // Skip dragged column
            if ($cid === $columnId) continue;
            
            // Insert dragged column before target
            if ($insertBefore && $cid === $targetColumnId) {
                $newPositions[$columnId] = $newPos++;
            }
            
            $newPositions[$cid] = $newPos++;
            
            // Insert dragged column after target
            if (!$insertBefore && $cid === $targetColumnId) {
                $newPositions[$columnId] = $newPos++;
            }
        }
        
        // Update positions
        $stmt = $DB->prepare("
            UPDATE board_columns 
            SET position = ? 
            WHERE column_id = ?
        ");
        
        foreach ($newPositions as $cid => $pos) {
            $stmt->execute([$pos, $cid]);
        }
        
        $DB->commit();
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Column reordered',
            'data' => [
                'column_id' => $columnId,
                'new_position' => $newPositions[$columnId]
            ]
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Column Reorder Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;