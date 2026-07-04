<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_audit.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);
$title = trim($_POST['title'] ?? '');

if (!$boardId) respond_error('Board ID required');
if (!$groupId) respond_error('Group ID required');
if (empty($title)) respond_error('Title required');

require_board_role($boardId, 'member');

try {
    // Verify group belongs to board
    $stmt = $DB->prepare("SELECT id FROM board_groups WHERE id = ? AND board_id = ?");
    $stmt->execute([$groupId, $boardId]);
    if (!$stmt->fetch()) respond_error('Group not found', 404);

    // Get next position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1
        FROM board_items
        WHERE group_id = ? AND archived = 0
    ");
    $stmt->execute([$groupId]);
    $nextPos = (int)$stmt->fetchColumn();

    // Insert item
    $stmt = $DB->prepare("
        INSERT INTO board_items (
            board_id, company_id, group_id, title,
            position, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$boardId, $COMPANY_ID, $groupId, $title, $nextPos, $USER_ID]);
    $itemId = (int)$DB->lastInsertId();

    // Fetch created item
    $stmt = $DB->prepare("
        SELECT bi.*, bg.name AS group_name, u.first_name, u.last_name
        FROM board_items bi
        LEFT JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    fw_audit($DB, $COMPANY_ID, $boardId, $itemId, $USER_ID, 'item_created', ['title' => $title]);

    respond_ok(['item_id' => $itemId, 'item' => $item]);

} catch (Exception $e) {
    error_log("Create item error: " . $e->getMessage());
    respond_error('Failed to create item', 500);
}
