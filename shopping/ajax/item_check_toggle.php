<?php
// /shopping/ajax/item_check_toggle.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$listId = intval($input['list_id'] ?? 0);
$itemId = intval($input['item_id'] ?? 0);
$checked = (bool)($input['checked'] ?? false);

if (!$listId || !$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Missing IDs']);
    exit;
}

// Verify ownership
$stmt = $DB->prepare("
    SELECT i.id 
    FROM shopping_items i
    JOIN shopping_lists l ON i.list_id = l.id
    WHERE i.id = ? AND i.list_id = ? AND l.company_id = ?
");
$stmt->execute([$itemId, $listId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Item not found']);
    exit;
}

try {
    $DB->beginTransaction();
    
    $newStatus = $checked ? 'bought' : 'pending';
    $stmt = $DB->prepare("UPDATE shopping_items SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $itemId]);
    
    // Audit
    $action = $checked ? 'check' : 'uncheck';
    $stmt = $DB->prepare("
        INSERT INTO shopping_actions (company_id, list_id, item_id, user_id, action, payload_json, ip)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $listId,
        $itemId,
        $userId,
        $action,
        json_encode(['status' => $newStatus]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true, 'status' => $newStatus]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Item check error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}