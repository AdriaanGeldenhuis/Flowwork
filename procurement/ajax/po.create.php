<?php
// /procurement/ajax/po.create.php – Create a new purchase order via AJAX
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$role = $_SESSION['role'] ?? 'member';
// Only admin or bookkeeper can create POs; allow member if needed
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Read JSON input
$data = json_decode(file_get_contents('php://input'), true);
$header = $data['header'] ?? [];
$lines  = $data['lines'] ?? [];

// Validate fields
if (empty($header['supplier_id']) || empty($header['po_number']) || !isset($header['total'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing required header fields']);
    exit;
}

$supplierId = (int)$header['supplier_id'];
$poNumber   = trim($header['po_number']);
$total      = (float)$header['total'];
$notes      = $header['notes'] ?? null; // not stored but accepted for forward compatibility

if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Ensure supplier belongs to company
$stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
$stmt->execute([$supplierId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid supplier']);
    exit;
}

try {
    $DB->beginTransaction();
    // Insert purchase order
    $stmt = $DB->prepare(
        "INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at)
         VALUES (?, ?, ?, ?, 'draft', NOW())"
    );
    $stmt->execute([$companyId, $poNumber, $supplierId, $total]);
    $poId = (int)$DB->lastInsertId();
    // Insert lines
    foreach ($lines as $ln) {
        $desc    = trim($ln['description'] ?? '');
        $qty     = isset($ln['qty']) ? (float)$ln['qty'] : 1.0;
        $unit    = $ln['unit'] ?? 'ea';
        $price   = isset($ln['unit_price']) ? (float)$ln['unit_price'] : 0.0;
        $taxRate = isset($ln['tax_rate']) ? (float)$ln['tax_rate'] : 15.0;
        $glAcc   = isset($ln['gl_account_id']) && $ln['gl_account_id'] ? (int)$ln['gl_account_id'] : null;
        $stmtL = $DB->prepare(
            "INSERT INTO purchase_order_lines (po_id, description, qty, unit, unit_price, tax_rate, gl_account_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtL->execute([$poId, $desc, $qty, $unit, $price, $taxRate, $glAcc]);
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'po_id' => $poId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('PO create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create purchase order']);
}