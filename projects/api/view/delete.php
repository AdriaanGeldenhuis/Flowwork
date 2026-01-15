<?php
/**
 * Delete Custom View
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
    
    $USER_ID = $_SESSION['user_id'];
    $viewId = (int)($_POST['view_id'] ?? 0);
    
    if (!$viewId) api_error('View ID required', 'VALIDATION_ERROR');
    
    // Verify ownership
    $stmt = $DB->prepare("
        SELECT id FROM board_saved_views 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$viewId, $USER_ID]);
    
    if (!$stmt->fetch()) {
        api_error('View not found or access denied', 'NOT_FOUND', 404);
    }
    
    // Delete
    $stmt = $DB->prepare("DELETE FROM board_saved_views WHERE id = ?");
    $stmt->execute([$viewId]);
    
    api_success([], 'View deleted');
    
} catch (Exception $e) {
    error_log("Delete View Error: " . $e->getMessage());
    api_error('Failed to delete view: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}