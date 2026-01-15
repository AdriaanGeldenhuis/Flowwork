<?php
/**
 * List Comments for Item
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
    
    $itemId = (int)($_GET['item_id'] ?? 0);
    
    if (!$itemId) api_error('Item ID required', 'VALIDATION_ERROR');
    
    // Get comments
    $stmt = $DB->prepare("
        SELECT 
            bic.*,
            u.first_name,
            u.last_name,
            u.email
        FROM board_item_comments bic
        LEFT JOIN users u ON bic.user_id = u.id
        WHERE bic.item_id = ? AND bic.company_id = ?
        ORDER BY bic.created_at DESC
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format timestamps
    foreach ($comments as &$comment) {
        $comment['time_ago'] = timeAgo($comment['created_at']);
    }
    
    api_success([
        'comments' => $comments,
        'count' => count($comments)
    ], 'Comments loaded');
    
} catch (Exception $e) {
    error_log("List Comments Error: " . $e->getMessage());
    api_error('Failed to load comments: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $time);
}