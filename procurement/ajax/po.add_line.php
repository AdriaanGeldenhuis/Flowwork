<?php
// /procurement/ajax/po.add_line.php – Add a line to an existing purchase order
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

$data = json_decode(file_get_contents('php://input'), true);
$poId = isset($data['po_id']) ? (int)$data['po_id'] : 0;
$line = $data['line'] ?? [];

if ($poId <= 0 || !$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

// Validate PO belongs to company
$stmt = $DB->prepare("SELECT id FROM purchase_orders WHERE id = ? AND company_id = ?");
$stmt->execute([$poId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Purchase order not found']);
    exit;
}

// Extract line fields
$desc    = trim($line['description'] ?? '');
$qty     = isset($line['qty']) ? (float)$line['qty'] : 1.0;
$unit    = $line['unit'] ?? 'ea';
$price   = isset($line['unit_price']) ? (float)$line['unit_price'] : 0.0;
$taxRate = isset($line['tax_rate']) ? (float)$line['tax_rate'] : 15.0;
$glAcc   = isset($line['gl_account_id']) && $line['gl_account_id'] ? (int)$line['gl_account_id'] : null;

try {
    $DB->beginTransaction();
    $stmtL = $DB->prepare(
        "INSERT INTO purchase_order_lines (po_id, description, qty, unit, unit_price, tax_rate, gl_account_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtL->execute([$poId, $desc, $qty, $unit, $price, $taxRate, $glAcc]);
    // Recompute PO total
    // Sum all lines: qty*price + tax
    $stmt = $DB->prepare(
        "SELECT qty, unit_price, tax_rate FROM purchase_order_lines WHERE po_id = ?"
    );
    $stmt->execute([$poId]);
    $subtotal = 0;
    $taxTotal = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $net = (float)$row['qty'] * (float)$row['unit_price'];
        $vat = ((float)$row['tax_rate'] > 0) ? $net * ((float)$row['tax_rate'] / 100) : 0;
        $subtotal += $net;
        $taxTotal += $vat;
    }
    $total = $subtotal + $taxTotal;
    $stmt = $DB->prepare("UPDATE purchase_orders SET total = ? WHERE id = ?");
    $stmt->execute([$total, $poId]);
    $DB->commit();
    echo json_encode(['ok' => true, 'total' => $total]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('PO add line error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to add line']);
}