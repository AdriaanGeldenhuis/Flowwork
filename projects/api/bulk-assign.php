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
$userId = (int)($input['user_id'] ?? 0);

if (!$boardId || empty($itemIds) || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

// Caller must be able to edit this board; scope the write to it as well.
require_board_role($boardId, 'member');

// The assignee must be a real user in the same company.
$chk = $DB->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
$chk->execute([$userId, $COMPANY_ID]);
if (!$chk->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Invalid assignee']);
    exit;
}
$itemIds = array_values(array_map('intval', $itemIds));

try {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$userId], $itemIds, [$boardId, $COMPANY_ID]);

    $stmt = $DB->prepare("
        UPDATE board_items
        SET assigned_to = ?, updated_at = NOW()
        WHERE id IN ($placeholders) AND board_id = ? AND company_id = ?
    ");
    $stmt->execute($params);
    
    fw_audit($DB, $COMPANY_ID, $boardId, null, $USER_ID, 'bulk_update', ['count' => $stmt->rowCount(), 'op' => 'assign', 'assigned_to' => $userId]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}