<?php
// /shopping/ajax/item_remove.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$listId = intval($input['list_id'] ?? 0);
$itemId = intval($input['item_id'] ?? 0);

if (!$listId || !$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Missing IDs']);
    exit;
}

// Verify ownership
$stmt = $DB->prepare("
    SELECT i.id, i.name_raw
    FROM shopping_items i
    JOIN shopping_lists l ON i.list_id = l.id
    WHERE i.id = ? AND i.list_id = ? AND l.company_id = ?
");
$stmt->execute([$itemId, $listId, $companyId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'Item not found']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Soft delete - mark as removed
    $stmt = $DB->prepare("UPDATE shopping_items SET status = 'removed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$itemId]);
    
    // Audit
    $stmt = $DB->prepare("
        INSERT INTO shopping_actions (company_id, list_id, item_id, user_id, action, payload_json, ip)
        VALUES (?, ?, ?, ?, 'remove', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $listId,
        $itemId,
        $userId,
        json_encode(['name' => $item['name_raw']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Item remove error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}