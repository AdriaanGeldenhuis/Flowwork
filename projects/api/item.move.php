<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$itemId = (int)($_POST['item_id'] ?? 0);
$newGroupId = (int)($_POST['group_id'] ?? 0);
$newPosition = isset($_POST['position']) ? (int)$_POST['position'] : null;
$beforeItemId = (int)($_POST['before_item_id'] ?? 0);

if (!$itemId) respond_error('Item ID required');
if (!$newGroupId) respond_error('Group ID required');

try {
    // Verify item belongs to company
    $stmt = $DB->prepare("
        SELECT bi.board_id
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) respond_error('Item not found', 404);

    require_board_role($item['board_id'], 'member');

    $DB->beginTransaction();

    // If before_item_id is specified, insert before that item
    if ($beforeItemId) {
        $stmt = $DB->prepare("SELECT position FROM board_items WHERE id = ? AND group_id = ?");
        $stmt->execute([$beforeItemId, $newGroupId]);
        $targetPosition = $stmt->fetchColumn();

        if ($targetPosition !== false) {
            // Shift items down to make room
            $stmt = $DB->prepare("
                UPDATE board_items
                SET position = position + 1
                WHERE group_id = ? AND position >= ?
            ");
            $stmt->execute([$newGroupId, $targetPosition]);
            $newPosition = (int)$targetPosition;
        }
    }

    // If no position determined yet, append to end
    if ($newPosition === null) {
        $stmt = $DB->prepare("
            SELECT COALESCE(MAX(position), -1) + 1
            FROM board_items
            WHERE board_id = ? AND company_id = ? AND group_id = ?
        ");
        $stmt->execute([$item['board_id'], $COMPANY_ID, $newGroupId]);
        $newPosition = (int)$stmt->fetchColumn();
    }

    $stmt = $DB->prepare("
        UPDATE board_items
        SET group_id = ?, position = ?, updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$newGroupId, $newPosition, $itemId, $COMPANY_ID]);

    $DB->commit();

    respond_ok(['moved' => true, 'position' => $newPosition]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log("Item move error: " . $e->getMessage());
    respond_error('Failed to move item', 500);
}
