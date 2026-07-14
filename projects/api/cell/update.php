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

    // Authorization: caller must be able to edit this board. Company scope alone
    // let any user edit any board's cells; require an explicit board/project role.
    $USER_ROLE = $_SESSION['role'] ?? 'viewer';
    require_once __DIR__ . '/../_guard.php';
    require_board_role($boardId, 'member');

    // Start transaction
    $DB->beginTransaction();

    try {
        // Select-then-write: correct with or without the UNIQUE(item_id,
        // column_id) key, so it stays safe if the code deploys before
        // Migrations/2026-07-04-board-performance.sql has been applied.
        $stmt = $DB->prepare("
            SELECT id
            FROM board_item_values
            WHERE item_id = ? AND column_id = ?
        ");
        $stmt->execute([$itemId, $columnId]);
        $existingId = $stmt->fetchColumn();

        if ($value === null || $value === '') {
            // Clearing a cell removes the row
            if ($existingId) {
                $stmt = $DB->prepare("DELETE FROM board_item_values WHERE item_id = ? AND column_id = ?");
                $stmt->execute([$itemId, $columnId]);
            }
        } elseif ($existingId) {
            $stmt = $DB->prepare("UPDATE board_item_values SET value = ? WHERE item_id = ? AND column_id = ?");
            $stmt->execute([$value, $itemId, $columnId]);
        } else {
            $stmt = $DB->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
            $stmt->execute([$itemId, $columnId, $value]);
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

        // Recompute + persist this item's formula cells so page loads read
        // cached values instead of re-evaluating formulas per request.
        // Skipped entirely on boards without formula columns.
        $formulas = [];
        try {
            $stmt = $DB->prepare("
                SELECT COUNT(*) FROM board_columns
                WHERE board_id = ? AND company_id = ? AND type = 'formula'
            ");
            $stmt->execute([$boardId, $COMPANY_ID]);
            if ((int)$stmt->fetchColumn() > 0) {
                require_once __DIR__ . '/../_formula.php';
                $formulas = _fw_update_formula_columns($DB, $boardId, (int)$COMPANY_ID, $itemId);
            }
        } catch (Exception $e) {
            error_log('Formula persist error: ' . $e->getMessage());
        }

        // Feed the activity log (failures are swallowed inside fw_audit)
        require_once __DIR__ . '/../_audit.php';
        fw_audit($DB, $COMPANY_ID, $boardId, $itemId, $USER_ID, 'item_updated', [
            'column_id' => $columnId,
            'column_name' => $row['name'],
            'value' => is_string($value) ? mb_substr($value, 0, 200) : $value,
        ]);

        // Success!
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Cell updated successfully',
            'data' => [
                'item_id' => $itemId,
                'column_id' => $columnId,
                'value' => $value,
                'column_type' => $columnType,
                'formulas' => $formulas ?: new stdClass()
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
        'error' => 'Database error'
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
        'error' => 'Failed to update cell'
    ]);
}

exit;