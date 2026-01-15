<?php
// /procurement/ajax/grn.create.php – Create a new Goods Received Note (GRN) for a purchase order
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Permissions: only admin/bookkeeper may create GRNs
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Read JSON payload
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$header = $payload['header'] ?? [];
$lines  = $payload['lines'] ?? [];

// Validate header
$poId      = isset($header['po_id']) ? (int)$header['po_id'] : 0;
$grnNumber = isset($header['grn_number']) ? trim($header['grn_number']) : '';
$subtotal  = isset($header['subtotal']) ? (float)$header['subtotal'] : 0.0;
$tax       = isset($header['tax']) ? (float)$header['tax'] : 0.0;
$total     = isset($header['total']) ? (float)$header['total'] : 0.0;
// Notes are accepted for forward compatibility but not stored (no column in DB)
$notes     = $header['notes'] ?? null;

if ($companyId <= 0 || $userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}
if ($poId <= 0 || $grnNumber === '' || $total <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid header fields']);
    exit;
}

// Validate PO belongs to this company and is not cancelled
$stmt = $DB->prepare("SELECT id, supplier_id FROM purchase_orders WHERE id = ? AND company_id = ? AND status != 'cancelled'");
$stmt->execute([$poId, $companyId]);
$poRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$poRow) {
    echo json_encode(['ok' => false, 'error' => 'Purchase order not found']);
    exit;
}

// Validate lines: ensure each po_line_id belongs to the PO and quantities are positive
// Build array of remaining quantities per PO line to prevent over-receipt
$remaining = [];
$stmt = $DB->prepare("SELECT id, qty FROM purchase_order_lines WHERE po_id = ?");
$stmt->execute([$poId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $remaining[(int)$row['id']] = (float)$row['qty'];
}
// Subtract already received quantities
$stmt = $DB->prepare(
    "SELECT gl.po_line_id, SUM(gl.qty_received) AS qty_received
     FROM grn_lines gl
     JOIN goods_received_notes grn ON grn.id = gl.grn_id
     WHERE grn.po_id = ? AND grn.status != 'cancelled'
     GROUP BY gl.po_line_id"
);
$stmt->execute([$poId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $lineId = (int)$row['po_line_id'];
    $received = (float)$row['qty_received'];
    if (isset($remaining[$lineId])) {
        $remaining[$lineId] -= $received;
    }
}

$insertLines = [];
foreach ($lines as $ln) {
    $poLineId   = isset($ln['po_line_id']) && $ln['po_line_id'] !== '' ? (int)$ln['po_line_id'] : 0;
    $qtyReceived= isset($ln['qty_received']) ? (float)$ln['qty_received'] : 0.0;
    if ($poLineId <= 0 || $qtyReceived <= 0) {
        // Skip invalid rows
        continue;
    }
    if (!isset($remaining[$poLineId])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PO line: ' . $poLineId]);
        exit;
    }
    // Ensure not exceeding remaining quantity
    if ($qtyReceived > $remaining[$poLineId] + 0.0001) {
        echo json_encode(['ok' => false, 'error' => 'Received quantity exceeds remaining for line ' . $poLineId]);
        exit;
    }
    $insertLines[] = [
        'po_line_id' => $poLineId,
        'qty_received' => $qtyReceived
    ];
}

if (empty($insertLines)) {
    echo json_encode(['ok' => false, 'error' => 'No valid lines to insert']);
    exit;
}

try {
    $DB->beginTransaction();
    // Insert GRN header
    $stmt = $DB->prepare(
        "INSERT INTO goods_received_notes (company_id, grn_number, po_id, total, received_date, status, created_at)
         VALUES (?, ?, ?, ?, CURDATE(), 'completed', NOW())"
    );
    $stmt->execute([$companyId, $grnNumber, $poId, $total]);
    $grnId = (int)$DB->lastInsertId();
    // Insert GRN lines
    $stmtL = $DB->prepare(
        "INSERT INTO grn_lines (grn_id, po_line_id, qty_received) VALUES (?, ?, ?)"
    );
    foreach ($insertLines as $ln) {
        $stmtL->execute([$grnId, $ln['po_line_id'], $ln['qty_received']]);
    }
    // Update PO status: if all lines fully received then completed, else partially_received
    $poComplete = true;
    foreach ($remaining as $lineId => $remainQty) {
        // Subtract inserted quantities
        foreach ($insertLines as $ln) {
            if ($ln['po_line_id'] == $lineId) {
                $remainQty -= $ln['qty_received'];
            }
        }
        if ($remainQty > 0.0001) {
            $poComplete = false;
            break;
        }
    }
    // Map completion to valid enum values: 'complete' when fully received, 'partial' otherwise
    $newStatus = $poComplete ? 'complete' : 'partial';
    $stmt = $DB->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $poId]);
    // Commit
    $DB->commit();
    echo json_encode(['ok' => true, 'grn_id' => $grnId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('GRN create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create GRN']);
}