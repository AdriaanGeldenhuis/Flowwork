<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$groupId = (int)($_POST['group_id'] ?? 0);
$collapsed = (int)($_POST['collapsed'] ?? 0);

if (!$groupId) respond_error('Group ID required');

$stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
$stmt->execute([$groupId]);
$group = $stmt->fetch();
if (!$group) respond_error('Group not found', 404);

require_board_role($group['board_id'], 'viewer');

try {
    $stmt = $DB->prepare("UPDATE board_groups SET collapsed = ? WHERE id = ?");
    $stmt->execute([$collapsed, $groupId]);
    respond_ok(['updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    error_log("Group collapse error: " . $e->getMessage());
    respond_error('Failed to update group', 500);
}