<?php
/**
 * Add Comment to Item
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
    
    $itemId = (int)($_POST['item_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    if (!$itemId) api_error('Item ID required', 'VALIDATION_ERROR');
    if (empty($comment)) api_error('Comment cannot be empty', 'VALIDATION_ERROR');
    
    // Verify item access
    $stmt = $DB->prepare("
        SELECT bi.id, bi.board_id 
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        api_error('Item not found', 'NOT_FOUND', 404);
    }
    
    // Extract mentions (@username)
    preg_match_all('/@(\w+)/', $comment, $mentions);
    $mentionedUsers = [];
    
    if (!empty($mentions[1])) {
        $usernames = array_unique($mentions[1]);
        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        
        $stmt = $DB->prepare("
            SELECT id, first_name, last_name, email
            FROM users
            WHERE company_id = ? 
                AND (
                    LOWER(CONCAT(first_name, last_name)) IN (" . implode(',', array_map(function($u) { return "LOWER(?)"; }, $usernames)) . ")
                    OR LOWER(email) IN (" . implode(',', array_map(function($u) { return "LOWER(?)"; }, $usernames)) . ")
                )
        ");
        $params = [$COMPANY_ID, ...$usernames, ...$usernames];
        $stmt->execute($params);
        $mentionedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Insert comment
    $stmt = $DB->prepare("
        INSERT INTO board_item_comments (
            company_id, item_id, user_id, comment, created_at
        ) VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$COMPANY_ID, $itemId, $USER_ID, $comment]);
    
    $commentId = (int)$DB->lastInsertId();
    
    // Log audit
    try {
        $stmt = $DB->prepare("
            INSERT INTO board_audit_log (
                company_id, board_id, item_id, user_id,
                action, details, ip_address, created_at
            ) VALUES (?, ?, ?, ?, 'comment_added', ?, ?, NOW())
        ");
        $stmt->execute([
            $COMPANY_ID,
            $item['board_id'],
            $itemId,
            $USER_ID,
            json_encode(['comment_id' => $commentId]),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
    
    // TODO: Send notifications to mentioned users
    
    api_success([
        'comment_id' => $commentId,
        'mentioned_users' => array_column($mentionedUsers, 'id')
    ], 'Comment added');
    
} catch (Exception $e) {
    error_log("Add Comment Error: " . $e->getMessage());
    api_error('Failed to add comment: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}