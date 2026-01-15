<?php
/**
 * Save Custom View
 * Save board view configuration (filters, sorts, visible columns)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once dirname(dirname(__DIR__)) . '/init.php';
require_once dirname(dirname(__DIR__)) . '/auth_gate.php';
require_once dirname(__DIR__) . '/_response.php';

try {
    api_validate_csrf();
    api_require_auth();
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $viewType = $_POST['view_type'] ?? 'table';
    $filters = $_POST['filters'] ?? '[]';
    $sorts = $_POST['sorts'] ?? '[]';
    $visibleColumns = $_POST['visible_columns'] ?? '[]';
    $isShared = (int)($_POST['is_shared'] ?? 0);
    
    if (!$boardId) api_error('Board ID required', 'VALIDATION_ERROR');
    if (empty($name)) api_error('View name required', 'VALIDATION_ERROR');
    
    // Verify board access
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards 
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        api_error('Board not found', 'NOT_FOUND', 404);
    }
    
    // Validate JSON
    json_decode($filters);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid filters JSON', 'VALIDATION_ERROR');
    }
    
    json_decode($sorts);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid sorts JSON', 'VALIDATION_ERROR');
    }
    
    json_decode($visibleColumns);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid visible columns JSON', 'VALIDATION_ERROR');
    }
    
    // Insert view
    $stmt = $DB->prepare("
        INSERT INTO board_saved_views (
            board_id, user_id, name, view_type,
            filters, sorts, visible_columns, is_shared, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $boardId,
        $USER_ID,
        $name,
        $viewType,
        $filters,
        $sorts,
        $visibleColumns,
        $isShared
    ]);
    
    $viewId = (int)$DB->lastInsertId();
    
    api_success([
        'view_id' => $viewId,
        'name' => $name
    ], 'View saved successfully');
    
} catch (Exception $e) {
    error_log("Save View Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    api_error('Failed to save view: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}