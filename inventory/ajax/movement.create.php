<?php
// inventory/ajax/movement.create.php
// Endpoint to record a new inventory movement (receipt or issue).

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
require_once __DIR__ . '/../../finances/lib/InventoryService.php';

// Only admin, bookkeeper and member roles may create movements
requireRoles(['admin', 'bookkeeper', 'member']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// CSRF header check
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrfHeader || $csrfHeader !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Parse inputs
$itemId   = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$date     = trim($_POST['date'] ?? '');
$qty      = isset($_POST['qty']) ? floatval($_POST['qty']) : 0.0;
$unitCost = isset($_POST['unit_cost']) ? floatval($_POST['unit_cost']) : 0.0;

if (!$itemId || !$date) {
    echo json_encode(['success' => false, 'message' => 'Item ID and date are required']);
    exit;
}

// Validate date format (YYYY-mm-dd)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

if (abs($qty) < 0.0001) {
    echo json_encode(['success' => false, 'message' => 'Quantity must be non‑zero']);
    exit;
}

try {
    $companyId = (int)$_SESSION['company_id'];
    // Ensure the item exists and belongs to company
    $stmt = $DB->prepare(
        "SELECT id FROM inventory_items WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$itemId, $companyId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit;
    }
    $inventoryService = new InventoryService($DB, $companyId);
    if ($qty > 0) {
        // Receipt: unit cost must be positive
        if ($unitCost <= 0) {
            echo json_encode(['success' => false, 'message' => 'Unit cost must be greater than zero for receipts']);
            exit;
        }
        // Record receipt
        $inventoryService->receive($itemId, $qty, $unitCost, $date, 'manual', null);
    } else {
        // Issue: use absolute quantity; unit cost ignored (calculated by service)
        $inventoryService->issue($itemId, abs($qty), $date, 'manual', null);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Inventory movement create error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
