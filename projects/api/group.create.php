<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$name    = trim($_POST['name'] ?? '');
$color   = $_POST['color'] ?? '#8b5cf6';

if (!$boardId) respond_error('Board ID required');
if (!$name)    respond_error('Group name required');

require_board_role($boardId, 'contributor');

try {
    // Determine next position
    $stmt = $DB->prepare("SELECT COALESCE(MAX(position), 0) FROM board_groups WHERE board_id = ?");
    $stmt->execute([$boardId]);
    $maxPos = (int)$stmt->fetchColumn();

    // Insert
    $stmt = $DB->prepare("
        INSERT INTO board_groups (board_id, name, color, position, collapsed, is_locked, created_at)
        VALUES (?, ?, ?, ?, 0, 0, NOW())
    ");
    $stmt->execute([$boardId, $name, $color, $maxPos + 1]);

    $groupId = (int)$DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
        VALUES (?, ?, NULL, ?, 'group_added', ?, ?, NOW())
    ");
    $stmt->execute([
        $COMPANY_ID,
        $boardId,
        $USER_ID,
        json_encode(['group_id' => $groupId, 'name' => $name]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    respond_ok(['group_id' => $groupId]);

} catch (Exception $e) {
    error_log("Group create error: " . $e->getMessage());
    respond_error('Failed to create group', 500);
}
