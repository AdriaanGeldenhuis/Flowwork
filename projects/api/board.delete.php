<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'owner');

try {
    $DB->beginTransaction();
    
    // Verify board belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) respond_error('Board not found', 404);
    
    // Delete attachments from disk
    $stmt = $DB->prepare("SELECT file_path FROM board_item_attachments WHERE item_id IN (SELECT id FROM board_items WHERE board_id = ?)");
    $stmt->execute([$boardId]);
    while ($file = $stmt->fetch()) {
        if (file_exists($file['file_path'])) {
            @unlink($file['file_path']);
        }
    }
    
    // Delete database records
    $DB->prepare("DELETE FROM board_item_attachments WHERE item_id IN (SELECT id FROM board_items WHERE board_id = ?)")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_item_comments WHERE item_id IN (SELECT id FROM board_items WHERE board_id = ?)")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_item_values WHERE item_id IN (SELECT id FROM board_items WHERE board_id = ?)")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_watchers WHERE item_id IN (SELECT id FROM board_items WHERE board_id = ?)")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_items WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_groups WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_columns WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_members WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_activity WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM board_saved_views WHERE board_id = ?")->execute([$boardId]);
    $DB->prepare("DELETE FROM project_boards WHERE board_id = ? AND company_id = ?")->execute([$boardId, $COMPANY_ID]);
    
    $DB->commit();
    respond_ok();
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Board delete error: " . $e->getMessage());
    respond_error('Failed to delete board', 500);
}