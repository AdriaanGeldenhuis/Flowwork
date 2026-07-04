<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];
$USER_ROLE = $_SESSION['role'] ?? 'viewer';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_audit.php';

$input = json_decode(file_get_contents('php://input'), true);
$boardId = (int)($input['board_id'] ?? 0);
$itemIds = $input['item_ids'] ?? [];
$groupId = (int)($input['group_id'] ?? 0);

if (!$boardId || empty($itemIds) || !$groupId) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

// Caller must be able to edit this board; scope the write to it as well.
require_board_role($boardId, 'member');

// The destination group must belong to this board.
$gchk = $DB->prepare("SELECT id FROM board_groups WHERE id = ? AND board_id = ?");
$gchk->execute([$groupId, $boardId]);
if (!$gchk->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid group']);
    exit;
}
$itemIds = array_values(array_map('intval', $itemIds));

try {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$groupId], $itemIds, [$boardId, $COMPANY_ID]);

    $stmt = $DB->prepare("
        UPDATE board_items
        SET group_id = ?, updated_at = NOW()
        WHERE id IN ($placeholders) AND board_id = ? AND company_id = ?
    ");
    $stmt->execute($params);
    
    fw_audit($DB, $COMPANY_ID, $boardId, null, $USER_ID, 'bulk_update', ['count' => $stmt->rowCount(), 'op' => 'move', 'group_id' => $groupId]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}