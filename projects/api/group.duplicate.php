<?php
/**
 * Duplicate a group and all of its items (including cell values)
 * within the same board.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_audit.php';

$groupId = (int)($_POST['group_id'] ?? 0);
if (!$groupId) respond_error('Group ID required');

try {
    // Verify the group belongs to a board in this company
    $stmt = $DB->prepare("
        SELECT bg.*, pb.board_id
        FROM board_groups bg
        JOIN project_boards pb ON bg.board_id = pb.board_id
        WHERE bg.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$groupId, $COMPANY_ID]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) respond_error('Group not found', 404);

    $boardId = (int)$group['board_id'];
    require_board_role($boardId, 'member');

    $DB->beginTransaction();

    // Place the copy right after the last group
    $stmt = $DB->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM board_groups WHERE board_id = ?");
    $stmt->execute([$boardId]);
    $nextPos = (int)$stmt->fetchColumn();

    $stmt = $DB->prepare("
        INSERT INTO board_groups (board_id, name, color, position, collapsed, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$boardId, $group['name'] . ' (Copy)', $group['color'], $nextPos]);
    $newGroupId = (int)$DB->lastInsertId();

    // Copy items (skip archived) and their cell values
    $stmt = $DB->prepare("
        SELECT * FROM board_items
        WHERE group_id = ? AND board_id = ? AND archived = 0
        ORDER BY position
    ");
    $stmt->execute([$groupId, $boardId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insertItem = $DB->prepare("
        INSERT INTO board_items (
            board_id, company_id, group_id, title, description, position,
            status_label, assigned_to, priority, progress, due_date,
            start_date, end_date, tags, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $copyValues = $DB->prepare("
        INSERT INTO board_item_values (item_id, column_id, value)
        SELECT ?, column_id, value
        FROM board_item_values
        WHERE item_id = ?
    ");

    foreach ($items as $item) {
        $insertItem->execute([
            $boardId, $COMPANY_ID, $newGroupId, $item['title'], $item['description'],
            $item['position'], $item['status_label'], $item['assigned_to'],
            $item['priority'], $item['progress'], $item['due_date'],
            $item['start_date'], $item['end_date'], $item['tags'], $USER_ID
        ]);
        $copyValues->execute([(int)$DB->lastInsertId(), $item['id']]);
    }

    $DB->commit();

    fw_audit($DB, $COMPANY_ID, $boardId, null, $USER_ID, 'group_added', [
        'name' => $group['name'] . ' (Copy)',
        'duplicated_from' => $groupId,
        'items' => count($items),
    ]);

    respond_ok(['group_id' => $newGroupId, 'items' => count($items)]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log("Group duplicate error: " . $e->getMessage());
    respond_error('Failed to duplicate group', 500);
}
