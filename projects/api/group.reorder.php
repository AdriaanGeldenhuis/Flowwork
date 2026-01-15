<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$orderedIds = $_POST['ordered_group_ids'] ?? [];

if (!$boardId) respond_error('Board ID required');
if (!is_array($orderedIds) || empty($orderedIds)) respond_error('ordered_group_ids required');

require_board_role($boardId, 'contributor');

try {
    $DB->beginTransaction();
    
    $stmt = $DB->prepare("UPDATE board_groups SET position = ? WHERE id = ? AND board_id = ?");
    
    foreach ($orderedIds as $pos => $gid) {
        $stmt->execute([$pos, (int)$gid, $boardId]);
    }
    
    $DB->commit();
    respond_ok(['reordered' => count($orderedIds)]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Group reorder error: " . $e->getMessage());
    respond_error('Failed to reorder groups', 500);
}