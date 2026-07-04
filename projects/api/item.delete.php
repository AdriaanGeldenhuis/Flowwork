<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_audit.php';

$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId) respond_error('Item ID required');

try {
    // Verify item belongs to company and get board_id for role check
    $stmt = $DB->prepare("
        SELECT bi.id, bi.board_id, bi.title
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) respond_error('Item not found', 404);

    require_board_role($item['board_id'], 'member');

    $DB->beginTransaction();

    // Delete all child records
    $DB->prepare("DELETE FROM board_item_attachments WHERE item_id = ?")->execute([$itemId]);
    $DB->prepare("DELETE FROM board_item_comments WHERE item_id = ?")->execute([$itemId]);
    $DB->prepare("DELETE FROM board_item_values WHERE item_id = ?")->execute([$itemId]);
    $DB->prepare("DELETE FROM board_watchers WHERE item_id = ?")->execute([$itemId]);
    $DB->prepare("DELETE FROM board_subitems WHERE parent_item_id = ?")->execute([$itemId]);

    // Delete the item
    $DB->prepare("DELETE FROM board_items WHERE id = ?")->execute([$itemId]);

    $DB->commit();

    fw_audit($DB, $COMPANY_ID, $item['board_id'], null, $USER_ID, 'item_deleted', [
        'title' => $item['title'],
    ]);

    respond_ok();

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log("Item delete error: " . $e->getMessage());
    respond_error('Failed to delete item', 500);
}
