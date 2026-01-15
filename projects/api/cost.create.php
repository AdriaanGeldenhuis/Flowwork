<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$itemId = (int)($_POST['item_id'] ?? 0);
$type = trim($_POST['type'] ?? 'material');
$description = trim($_POST['description'] ?? '');
$qty = (float)($_POST['qty'] ?? 1);
$unitCost = (float)($_POST['unit_cost'] ?? 0);
$taxCode = trim($_POST['tax_code'] ?? 'standard');
$supplierId = (int)($_POST['supplier_id'] ?? 0);

if (!$itemId || !$description) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Get project_id from item
$stmt = $DB->prepare("
    SELECT bi.board_id, pb.project_id 
    FROM board_items bi
    JOIN project_boards pb ON bi.board_id = pb.board_id
    WHERE bi.id = ? AND bi.company_id = ?
");
$stmt->execute([$itemId, $COMPANY_ID]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// Validate supplier if provided (must be CRM supplier)
if ($supplierId) {
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND type = 'supplier' AND company_id = ?");
    $stmt->execute([$supplierId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid supplier']);
        exit;
    }
}

// Insert cost
$stmt = $DB->prepare("
    INSERT INTO cost_items 
    (company_id, project_id, item_id, type, description, qty, unit_cost, tax_code, supplier_id, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
    $COMPANY_ID, $item['project_id'], $itemId, $type, $description, $qty, $unitCost, $taxCode, 
    $supplierId ?: null, $USER_ID
]);

$costId = $DB->lastInsertId();

echo json_encode(['ok' => true, 'cost_id' => $costId]);