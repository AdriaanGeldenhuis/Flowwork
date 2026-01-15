<?php
/**
 * API: Calculate formulas for board or specific column
 * POST /projects/api/formula/calculate.php
 * 
 * Body:
 *   board_id: int
 *   column_id: int (optional - if provided, only recalculate this column)
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
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $columnId = isset($_POST['column_id']) ? (int)$_POST['column_id'] : null;
    
    error_log("Formula Calculate - Board ID: $boardId, Column ID: " . ($columnId ?: 'ALL'));
    
    // Validation
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    // Verify board access
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards 
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Get formula columns (either specific or all)
    if ($columnId) {
        $stmt = $DB->prepare("
            SELECT column_id, name, config 
            FROM board_columns 
            WHERE column_id = ? AND board_id = ? AND type = 'formula'
        ");
        $stmt->execute([$columnId, $boardId]);
    } else {
        $stmt = $DB->prepare("
            SELECT column_id, name, config 
            FROM board_columns 
            WHERE board_id = ? AND type = 'formula'
            ORDER BY position ASC
        ");
        $stmt->execute([$boardId]);
    }
    
    $formulaColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($formulaColumns)) {
        echo json_encode([
            'ok' => true,
            'updated' => 0,
            'message' => 'No formula columns found'
        ]);
        exit;
    }
    
    // Get all columns for name mapping
    $stmt = $DB->prepare("
        SELECT column_id, name, type 
        FROM board_columns 
        WHERE board_id = ?
    ");
    $stmt->execute([$boardId]);
    $allColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $colNameMap = [];
    foreach ($allColumns as $col) {
        $colNameMap[$col['name']] = $col['column_id'];
    }
    
    // Get items to calculate
    $stmt = $DB->prepare("
        SELECT id 
        FROM board_items 
        WHERE board_id = ? AND archived = 0
    ");
    $stmt->execute([$boardId]);
    $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($itemIds)) {
        echo json_encode([
            'ok' => true,
            'updated' => 0,
            'message' => 'No items to calculate'
        ]);
        exit;
    }
    
    // Get all values for these items
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $DB->prepare("
        SELECT item_id, column_id, value 
        FROM board_item_values 
        WHERE item_id IN ($placeholders)
    ");
    $stmt->execute($itemIds);
    $allValues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build values map
    $valuesMap = [];
    foreach ($allValues as $row) {
        $iid = (int)$row['item_id'];
        $cid = (int)$row['column_id'];
        $val = $row['value'];
        
        if (!isset($valuesMap[$iid])) $valuesMap[$iid] = [];
        $valuesMap[$iid][$cid] = is_numeric($val) ? (float)$val : 0.0;
    }
    
    $DB->beginTransaction();
    $updated = 0;
    
    try {
        foreach ($formulaColumns as $formulaCol) {
            $colId = (int)$formulaCol['column_id'];
            $config = json_decode($formulaCol['config'], true) ?: [];
            $formulaStr = trim($config['formula'] ?? '');
            $precision = isset($config['precision']) ? (int)$config['precision'] : 2;
            
            if (empty($formulaStr)) {
                error_log("Formula column {$formulaCol['name']} has empty formula, skipping");
                continue;
            }
            
            error_log("Processing formula column: {$formulaCol['name']}, formula: $formulaStr");
            
            foreach ($itemIds as $iid) {
                $ctx = $valuesMap[$iid] ?? [];
                
                // Replace column names with values
                $expr = $formulaStr;
                foreach ($colNameMap as $name => $cid) {
                    $val = $ctx[$cid] ?? 0.0;
                    // Replace {ColumnName} with actual value
                    $expr = str_replace('{' . $name . '}', (string)$val, $expr);
                }
                
                // Remove any spaces
                $expr = str_replace(' ', '', $expr);
                
                error_log("Item $iid: Original formula: $formulaStr -> Expression: $expr");
                
                // Calculate
                $result = 0;
                try {
                    // Security: only allow numbers and operators
                    if (preg_match('/^[\d\.\+\-\*\/\(\)]+$/', $expr)) {
                        $tmp = @eval('return ' . $expr . ';');
                        $result = is_numeric($tmp) ? $tmp : 0;
                    } else {
                        error_log("Invalid expression for item $iid: $expr");
                    }
                } catch (Throwable $e) {
                    error_log("Formula eval error for item $iid: " . $e->getMessage());
                    $result = 0;
                }
                
                $formattedResult = number_format($result, $precision, '.', '');
                
                error_log("Item $iid: Result = $formattedResult");
                
                // Update or insert value
                $stmt = $DB->prepare("
                    INSERT INTO board_item_values (item_id, column_id, value) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = ?
                ");
                $stmt->execute([$iid, $colId, $formattedResult, $formattedResult]);
                
                // Update in-memory map for cascading formulas
                $valuesMap[$iid][$colId] = (float)$formattedResult;
                
                $updated++;
            }
        }
        
        $DB->commit();
        
        error_log("Formula calculation completed: $updated values updated");
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'updated' => $updated,
            'items' => count($itemIds),
            'columns' => count($formulaColumns),
            'message' => 'Formulas calculated successfully'
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Formula Calculate DB Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Formula Calculate Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to calculate formulas: ' . $e->getMessage()
    ]);
}
exit;