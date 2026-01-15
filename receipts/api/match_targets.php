<?php
// Dummy matching logic for receipts. Returns best match suggestions for PO, GRN, or project board item.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Expect JSON POST body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$header = isset($input['header']) ? $input['header'] : [];
$lines = isset($input['lines']) ? $input['lines'] : [];

// Perform matching on purchase orders (PO) and goods received notes (GRN).
// Note: Board item matching is not implemented here and will return null.

$poMatch = null;
$grnMatch = null;

// Proceed only if supplier, invoice_date and total are provided
$supplierId = isset($header['supplier_id']) ? (int)$header['supplier_id'] : 0;
$invDate = isset($header['invoice_date']) ? $header['invoice_date'] : null;
$total = isset($header['total']) ? (float)$header['total'] : null;

if ($supplierId && $invDate && $total !== null) {
    // Search for candidate POs within ±30 days and compute variance
    try {
        $stmt = $DB->prepare(
            "SELECT id, total, created_at
             FROM purchase_orders
             WHERE company_id = ? AND supplier_id = ? AND status != 'cancelled'
             AND ABS(DATEDIFF(created_at, ?)) <= 30
             ORDER BY ABS(total - ?) / CASE WHEN total = 0 THEN 1 ELSE total END ASC
             LIMIT 1"
        );
        $stmt->execute([$companyId, $supplierId, $invDate, $total]);
        $po = $stmt->fetch();
        if ($po) {
            $variance = ($po['total'] != 0) ? abs($po['total'] - $total) / $po['total'] * 100 : 0;
            $poMatch = [
                'id' => (int)$po['id'],
                'variance' => round($variance, 2)
            ];
        }
    } catch (Exception $e) {
        // Ignore matching errors
    }
    // Search for candidate GRNs via PO within ±30 days and compute variance
    try {
        $stmt = $DB->prepare(
            "SELECT g.id, g.total, g.received_date
             FROM goods_received_notes g
             JOIN purchase_orders p ON p.id = g.po_id
             WHERE g.company_id = ? AND p.supplier_id = ? AND g.status != 'cancelled'
             AND ABS(DATEDIFF(g.received_date, ?)) <= 30
             ORDER BY ABS(g.total - ?) / CASE WHEN g.total = 0 THEN 1 ELSE g.total END ASC
             LIMIT 1"
        );
        $stmt->execute([$companyId, $supplierId, $invDate, $total]);
        $grn = $stmt->fetch();
        if ($grn) {
            $variance = ($grn['total'] != 0) ? abs($grn['total'] - $total) / $grn['total'] * 100 : 0;
            $grnMatch = [
                'id' => (int)$grn['id'],
                'variance' => round($variance, 2)
            ];
        }
    } catch (Exception $e) {
        // Ignore matching errors
    }
}

$result = [
    'ok' => true,
    'po' => $poMatch,
    'grn' => $grnMatch,
    'board_item' => null
];

echo json_encode($result);