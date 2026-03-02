<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId) respond_error('Item ID required');

try {
    // Get item and verify company ownership
    $stmt = $DB->prepare("
        SELECT bi.*
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) respond_error('Item not found', 404);

    require_board_role($item['board_id'], 'member');

    // Get next position in group
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1
        FROM board_items
        WHERE group_id = ?
    ");
    $stmt->execute([$item['group_id']]);
    $nextPos = (int)$stmt->fetchColumn();

    $DB->beginTransaction();

    // Insert duplicate
    $stmt = $DB->prepare("
        INSERT INTO board_items (
            board_id, company_id, group_id, title, description,
            position, status_label, assigned_to, priority, progress,
            due_date, start_date, end_date, tags, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $item['board_id'],
        $COMPANY_ID,
        $item['group_id'],
        $item['title'] . ' (Copy)',
        $item['description'],
        $nextPos,
        $item['status_label'],
        $item['assigned_to'],
        $item['priority'],
        $item['progress'],
        $item['due_date'],
        $item['start_date'],
        $item['end_date'],
        $item['tags'],
        $USER_ID
    ]);
    $newItemId = (int)$DB->lastInsertId();

    // Copy cell values
    $stmt = $DB->prepare("
        INSERT INTO board_item_values (item_id, column_id, value)
        SELECT ?, column_id, value
        FROM board_item_values
        WHERE item_id = ?
    ");
    $stmt->execute([$newItemId, $itemId]);

    $DB->commit();

    respond_ok(['item_id' => $newItemId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log("Item duplicate error: " . $e->getMessage());
    respond_error('Failed to duplicate item', 500);
}
