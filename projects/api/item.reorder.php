<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);
$orderedIds = $_POST['ordered_item_ids'] ?? [];

if (!$boardId) respond_error('Board ID required');
if (!$groupId) respond_error('Group ID required');
if (!is_array($orderedIds) || empty($orderedIds)) respond_error('ordered_item_ids required');

require_board_role($boardId, 'contributor');

try {
    $DB->beginTransaction();
    
    $stmt = $DB->prepare("UPDATE board_items SET position = ?, updated_at = NOW() WHERE id = ? AND board_id = ? AND group_id = ? AND company_id = ?");
    
    foreach ($orderedIds as $pos => $iid) {
        $stmt->execute([$pos, (int)$iid, $boardId, $groupId, $COMPANY_ID]);
    }
    
    $DB->commit();
    respond_ok(['reordered' => count($orderedIds)]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Item reorder error: " . $e->getMessage());
    respond_error('Failed to reorder items', 500);
}