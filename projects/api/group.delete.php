<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$groupId = (int)($_POST['group_id'] ?? 0);
if (!$groupId) respond_error('Group ID required');

$stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
$stmt->execute([$groupId]);
$grp = $stmt->fetch();
if (!$grp) respond_error('Group not found', 404);

require_board_role($grp['board_id'], 'manager');

try {
    $DB->beginTransaction();
    
    // Get items in group
    $stmt = $DB->prepare("SELECT id FROM board_items WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        
        // Delete item values
        $stmt = $DB->prepare("DELETE FROM board_item_values WHERE item_id IN ($placeholders)");
        $stmt->execute($itemIds);
        
        // Delete items
        $stmt = $DB->prepare("DELETE FROM board_items WHERE group_id = ?");
        $stmt->execute([$groupId]);
    }
    
    // Delete group
    $stmt = $DB->prepare("DELETE FROM board_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    
    $DB->commit();
    respond_ok(['deleted' => true]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Group delete error: " . $e->getMessage());
    respond_error('Failed to delete group', 500);
}