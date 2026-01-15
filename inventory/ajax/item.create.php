<?php
// inventory/ajax/item.create.php
// Endpoint to create a new inventory item via AJAX.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';

// Only allow admin, bookkeeper and member roles
requireRoles(['admin', 'bookkeeper', 'member']);

header('Content-Type: application/json');

// Expect POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Basic CSRF check: header must match session token
$headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$headerToken || $headerToken !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Sanitize and validate input
$sku  = trim($_POST['sku'] ?? '');
$name = trim($_POST['name'] ?? '');
$uom  = trim($_POST['uom'] ?? '');
$isStocked = isset($_POST['is_stocked']) && $_POST['is_stocked'] ? 1 : 0;

if ($sku === '' || $name === '') {
    echo json_encode(['success' => false, 'message' => 'SKU and Name are required']);
    exit;
}

try {
    $companyId = (int)$_SESSION['company_id'];
    $stmt = $DB->prepare(
        "INSERT INTO inventory_items (company_id, sku, name, uom, is_stocked) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $companyId,
        $sku,
        $name,
        $uom !== '' ? $uom : 'ea',
        $isStocked,
    ]);
    $id = (int)$DB->lastInsertId();
    echo json_encode(['success' => true, 'item_id' => $id]);
} catch (Exception $e) {
    error_log('Inventory item create error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to create item']);
}
