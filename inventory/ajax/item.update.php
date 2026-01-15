<?php
// inventory/ajax/item.update.php
// Endpoint to update an existing inventory item via AJAX.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';

// Only admin, bookkeeper and member can update items
requireRoles(['admin', 'bookkeeper', 'member']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// CSRF token from header
$headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$headerToken || $headerToken !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Inputs
$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$sku  = trim($_POST['sku'] ?? '');
$name = trim($_POST['name'] ?? '');
$uom  = trim($_POST['uom'] ?? '');
$isStocked = isset($_POST['is_stocked']) && $_POST['is_stocked'] ? 1 : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Item ID is required']);
    exit;
}
if ($sku === '' || $name === '') {
    echo json_encode(['success' => false, 'message' => 'SKU and Name are required']);
    exit;
}

try {
    $companyId = (int)$_SESSION['company_id'];
    // Ensure item exists and belongs to company
    $stmt = $DB->prepare(
        "SELECT id FROM inventory_items WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$id, $companyId]);
    $exists = $stmt->fetchColumn();
    if (!$exists) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit;
    }
    // Update record
    $stmt = $DB->prepare(
        "UPDATE inventory_items SET sku = ?, name = ?, uom = ?, is_stocked = ?
          WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([
        $sku,
        $name,
        $uom !== '' ? $uom : 'ea',
        $isStocked,
        $id,
        $companyId
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Inventory item update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update item']);
}
