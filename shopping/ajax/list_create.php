<?php
// /shopping/ajax/list_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? 'Untitled List');
$purpose = $input['purpose'] ?? 'general';
$items = $input['items'] ?? [];

if (!in_array($purpose, ['procurement', 'groceries', 'general'])) {
    $purpose = 'general';
}

try {
    $DB->beginTransaction();
    
    // Create list
    $stmt = $DB->prepare("
        INSERT INTO shopping_lists (company_id, name, purpose, owner_id, status)
        VALUES (?, ?, ?, ?, 'open')
    ");
    $stmt->execute([$companyId, $name, $purpose, $userId]);
    $listId = $DB->lastInsertId();
    
    // Add items
    if (!empty($items)) {
        $stmt = $DB->prepare("
            INSERT INTO shopping_items 
            (list_id, name_raw, name_norm, qty, unit, priority, status, order_index)
            VALUES (?, ?, ?, ?, ?, 'med', 'pending', ?)
        ");
        
        foreach ($items as $idx => $item) {
            $nameRaw = $item['name'] ?? 'Unnamed Item';
            $nameNorm = normalizeForSearch($nameRaw);
            $qty = floatval($item['qty'] ?? 1);
            $unit = $item['unit'] ?? 'ea';
            
            $stmt->execute([$listId, $nameRaw, $nameNorm, $qty, $unit, $idx]);
        }
    }
    
    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO shopping_actions (company_id, list_id, user_id, action, payload_json, ip)
        VALUES (?, ?, ?, 'add', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $listId,
        $userId,
        json_encode(['item_count' => count($items)]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true, 'list_id' => $listId]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("List create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

function normalizeForSearch($text) {
    // Remove special chars, lowercase, trim
    $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return strtolower(trim($text));
}