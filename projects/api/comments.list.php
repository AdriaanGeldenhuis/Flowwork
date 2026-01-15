<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$itemId = (int)($_GET['item_id'] ?? 0);
if (!$itemId) respond_error('Item ID required');

try {
    $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch();
    if (!$item) respond_error('Item not found', 404);
    
    require_board_role($item['board_id'], 'viewer');
    
    // FIXED: column is 'comment', not 'body'
    $stmt = $DB->prepare("
        SELECT c.id, c.comment AS body, c.created_at, u.first_name, u.last_name
        FROM board_item_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.item_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$itemId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok(['comments' => $comments]);
    
} catch (Exception $e) {
    error_log("Comments list error: " . $e->getMessage());
    respond_error('Failed to load comments', 500);
}