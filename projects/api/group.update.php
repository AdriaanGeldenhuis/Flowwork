<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$groupId = (int)($_POST['group_id'] ?? 0);
if (!$groupId) respond_error('Group ID required');

// Resolve board
$stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
$stmt->execute([$groupId]);
$grp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$grp) respond_error('Group not found', 404);

require_board_role((int)$grp['board_id'], 'contributor');

$updates = [];
$values  = [];

$fields = [
    'name'      => 'name',
    'color'     => 'color',
    'position'  => 'position',
    'collapsed' => 'collapsed',
    'is_locked' => 'is_locked'
];

foreach ($fields as $key => $col) {
    if (isset($_POST[$key])) {
        $updates[] = "$col = ?";
        $values[]  = $_POST[$key];
    }
}

if (!$updates) respond_error('No fields to update');

$values[] = $groupId;

try {
    $sql = "UPDATE board_groups SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $DB->prepare($sql);
    $stmt->execute($values);
    respond_ok(['updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    error_log("Group update error: " . $e->getMessage());
    respond_error('Failed to update group', 500);
}
