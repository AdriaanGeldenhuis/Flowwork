<?php
/**
 * API: Link supplier to column
 * POST /projects/api/column/supplier-link.php
 * 
 * Body:
 *   column_id: int
 *   supplier_id: int (optional, null to unlink)
 */

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// CSRF validation
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$COMPANY_ID = $_SESSION['company_id'];
$columnId = (int)($_POST['column_id'] ?? 0);
$supplierId = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' 
    ? (int)$_POST['supplier_id'] 
    : null;

if (!$columnId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Column ID required']);
    exit;
}

try {
    // Verify column belongs to company
    $stmt = $DB->prepare("
        SELECT bc.column_id, bc.board_id
        FROM board_columns bc
        JOIN project_boards pb ON bc.board_id = pb.board_id
        WHERE bc.column_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$columnId, $COMPANY_ID]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$column) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Column not found']);
        exit;
    }
    
    // If supplier ID provided, verify it exists
    if ($supplierId !== null) {
        $stmt = $DB->prepare("
            SELECT id FROM crm_accounts
            WHERE id = ? AND company_id = ? AND type = 'supplier' AND status = 'active'
        ");
        $stmt->execute([$supplierId, $COMPANY_ID]);
        
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Supplier not found']);
            exit;
        }
    }
    
    // Check if supplier_id column exists in board_columns table
    $stmt = $DB->prepare("SHOW COLUMNS FROM board_columns LIKE 'supplier_id'");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        // Add supplier_id column if it doesn't exist
        $DB->exec("
            ALTER TABLE board_columns 
            ADD COLUMN supplier_id INT(10) NULL DEFAULT NULL AFTER config,
            ADD KEY idx_supplier_id (supplier_id)
        ");
    }
    
    // Update column with supplier link
    $stmt = $DB->prepare("
        UPDATE board_columns 
        SET supplier_id = ?
        WHERE column_id = ?
    ");
    $stmt->execute([$supplierId, $columnId]);
    
    echo json_encode([
        'ok' => true,
        'message' => $supplierId ? 'Supplier linked successfully' : 'Supplier unlinked'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to link supplier: ' . $e->getMessage()
    ]);
}