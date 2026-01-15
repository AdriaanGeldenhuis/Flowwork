<?php
// /shopping/ajax/list.to_po.php
// Converts a shopping list into a purchase order for a selected supplier.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$role = $_SESSION['role'] ?? 'member';
// Only admin or bookkeeper may convert a shopping list to a PO
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);
$listId     = isset($input['list_id']) ? (int)$input['list_id'] : 0;
$supplierId = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;

if (!$listId || !$supplierId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Verify that procurement module tables exist
$tableCheck = $DB->query("SHOW TABLES LIKE 'purchase_orders'")->fetch();
if (!$tableCheck) {
    echo json_encode(['ok' => false, 'error' => 'Procurement module not available']);
    exit;
}

// Check that the shopping list belongs to this company
$stmt = $DB->prepare(
    "SELECT id FROM shopping_lists WHERE id = ? AND company_id = ?"
);
$stmt->execute([$listId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Shopping list not found']);
    exit;
}

// Check that the supplier is valid and belongs to this company
$stmt = $DB->prepare(
    "SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND status = 'active'"
);
$stmt->execute([$supplierId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid supplier']);
    exit;
}

// Fetch items from the shopping list (exclude removed items)
$stmt = $DB->prepare(
    "SELECT name_raw, qty, unit
     FROM shopping_items
     WHERE list_id = ? AND status != 'removed'
     ORDER BY id"
);
$stmt->execute([$listId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo json_encode(['ok' => false, 'error' => 'No items on list to convert']);
    exit;
}

try {
    $DB->beginTransaction();

    // Generate a PO number: e.g. PO-20251006-1234
    $poNumber = 'PO-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // Insert purchase order header
    $stmtPo = $DB->prepare(
        "INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at)
         VALUES (?, ?, ?, 0.00, 'draft', NOW())"
    );
    $stmtPo->execute([$companyId, $poNumber, $supplierId]);
    $poId = (int)$DB->lastInsertId();

    // Prepare insert statement for lines
    $stmtLine = $DB->prepare(
        "INSERT INTO purchase_order_lines (po_id, description, qty, unit, unit_price, tax_rate, gl_account_id)
         VALUES (?, ?, ?, ?, 0.00, 15.00, NULL)"
    );

    foreach ($items as $item) {
        $desc = trim($item['name_raw'] ?? '') ?: '-';
        $qty  = isset($item['qty']) ? (float)$item['qty'] : 1.0;
        $unit = $item['unit'] ?? 'ea';
        $stmtLine->execute([$poId, $desc, $qty, $unit]);
    }

    // Commit transaction
    $DB->commit();

    // Audit log
    try {
        $details = json_encode([
            'list_id' => $listId,
            'supplier_id' => $supplierId,
            'items_count' => count($items),
            'po_number' => $poNumber
        ]);
        $stmtAudit = $DB->prepare(
            "INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
             VALUES (?, ?, 'shopping_to_po', 'purchase_order', ?, ?, NOW())"
        );
        $stmtAudit->execute([$companyId, $userId, $poId, $details]);
    } catch (Exception $e) {
        // Audit failure should not block main success
        error_log('Audit log error (shopping_to_po): ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'po_id' => $poId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('List to PO error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to convert list']);
}