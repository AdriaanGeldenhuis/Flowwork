<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Force JSON output and stop any previous output
header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) ob_end_clean();

// CSRF validation
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$COMPANY_ID = $_SESSION['company_id'];
$itemId = (int)($_POST['item_id'] ?? 0);
$columnId = (int)($_POST['column_id'] ?? 0);
$value = $_POST['value'] ?? '';

// Validation
if (!$itemId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Item ID required']);
    exit;
}

if (!$columnId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Column ID required']);
    exit;
}

try {
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
        echo json_encode(['ok' => false, 'error' => 'Item not found or access denied']);
        exit;
    }
    
    // Verify column exists
    $stmt = $DB->prepare("
        SELECT bc.column_id 
        FROM board_columns bc
        JOIN project_boards pb ON bc.board_id = pb.board_id
        WHERE bc.column_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$columnId, $COMPANY_ID]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Column not found']);
        exit;
    }
    
    // Check if value already exists
    $stmt = $DB->prepare("
        SELECT id FROM board_item_values
        WHERE item_id = ? AND column_id = ?
    ");
    $stmt->execute([$itemId, $columnId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        if ($value === '' || $value === null) {
            // Delete if empty
            $stmt = $DB->prepare("
                DELETE FROM board_item_values
                WHERE item_id = ? AND column_id = ?
            ");
            $stmt->execute([$itemId, $columnId]);
        } else {
            // Update existing
            $stmt = $DB->prepare("
                UPDATE board_item_values
                SET value = ?, updated_at = NOW()
                WHERE item_id = ? AND column_id = ?
            ");
            $stmt->execute([$value, $itemId, $columnId]);
        }
    } else {
        // Insert new value (only if not empty)
        if ($value !== '' && $value !== null) {
            $stmt = $DB->prepare("
                INSERT INTO board_item_values (item_id, column_id, value, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$itemId, $columnId, $value]);
        }
    }
    
    echo json_encode([
        'ok' => true,
        'message' => 'Cell updated successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}