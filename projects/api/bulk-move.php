<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

$input = json_decode(file_get_contents('php://input'), true);
$boardId = (int)($input['board_id'] ?? 0);
$itemIds = $input['item_ids'] ?? [];
$groupId = (int)($input['group_id'] ?? 0);

if (!$boardId || empty($itemIds) || !$groupId) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$groupId], $itemIds, [$COMPANY_ID]);
    
    $stmt = $DB->prepare("
        UPDATE board_items 
        SET group_id = ?, updated_at = NOW()
        WHERE id IN ($placeholders) AND company_id = ?
    ");
    $stmt->execute($params);
    
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}