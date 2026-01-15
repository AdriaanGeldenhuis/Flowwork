<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$groupId = (int)($_POST['group_id'] ?? 0);
$name = trim($_POST['name'] ?? '');

if (!$groupId) respond_error('Group ID required');
if (!$name) respond_error('Group name required');

$stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
$stmt->execute([$groupId]);
$group = $stmt->fetch();
if (!$group) respond_error('Group not found', 404);

require_board_role($group['board_id'], 'contributor');

try {
    $stmt = $DB->prepare("UPDATE board_groups SET name = ? WHERE id = ?");
    $stmt->execute([$name, $groupId]);
    respond_ok(['updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    error_log("Group rename error: " . $e->getMessage());
    respond_error('Failed to rename group', 500);
}