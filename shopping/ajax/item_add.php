<?php
// /shopping/ajax/item_add.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$listId = intval($input['list_id'] ?? 0);
$name = trim($input['name'] ?? '');
$qty = floatval($input['qty'] ?? 1);
$unit = trim($input['unit'] ?? 'ea');
$priority = $input['priority'] ?? 'med';

if (!$listId || empty($name)) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Verify list belongs to company
$stmt = $DB->prepare("SELECT id FROM shopping_lists WHERE id = ? AND company_id = ?");
$stmt->execute([$listId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'List not found']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Get next order index
    $stmt = $DB->prepare("SELECT COALESCE(MAX(order_index), 0) + 1 as next_idx FROM shopping_items WHERE list_id = ?");
    $stmt->execute([$listId]);
    $nextIdx = $stmt->fetchColumn();
    
    // Insert item
    $nameNorm = normalizeForSearch($name);
    $stmt = $DB->prepare("
        INSERT INTO shopping_items 
        (list_id, name_raw, name_norm, qty, unit, priority, status, order_index)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([$listId, $name, $nameNorm, $qty, $unit, $priority, $nextIdx]);
    $itemId = $DB->lastInsertId();
    
    // Audit
    $stmt = $DB->prepare("
        INSERT INTO shopping_actions (company_id, list_id, item_id, user_id, action, payload_json, ip)
        VALUES (?, ?, ?, ?, 'add', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $listId,
        $itemId,
        $userId,
        json_encode(['name' => $name, 'qty' => $qty]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Update list timestamp
    $stmt = $DB->prepare("UPDATE shopping_lists SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$listId]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true, 'item_id' => $itemId]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Item add error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

function normalizeForSearch($text) {
    $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return strtolower(trim($text));
}