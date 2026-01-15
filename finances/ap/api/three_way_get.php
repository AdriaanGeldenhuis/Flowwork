<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/three_way_get.php – Retrieve PO, GRN and Bill lines for three‑way matching

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

// Ensure a supplier ID is provided
$companyId  = (int)($_SESSION['company_id'] ?? 0);
$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

if ($companyId <= 0 || $supplierId <= 0) {
    echo json_encode(['error' => 'Missing company or supplier identifier']);
    exit;
}

try {
    // Fetch aggregated matched quantities to compute available quantities
    $matchedPo  = [];
    $matchedGrn = [];
    $matchedBill = [];

    // Aggregate matched quantities for PO lines
    $stmt = $DB->prepare("SELECT po_line_id, SUM(qty_matched) AS matched_qty FROM ap_match_links WHERE company_id = ? AND po_line_id IS NOT NULL GROUP BY po_line_id");
    $stmt->execute([$companyId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $matchedPo[(int)$row['po_line_id']] = (float)$row['matched_qty'];
    }

    // Aggregate matched quantities for GRN lines
    $stmt = $DB->prepare("SELECT grn_line_id, SUM(qty_matched) AS matched_qty FROM ap_match_links WHERE company_id = ? AND grn_line_id IS NOT NULL GROUP BY grn_line_id");
    $stmt->execute([$companyId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $matchedGrn[(int)$row['grn_line_id']] = (float)$row['matched_qty'];
    }

    // Aggregate matched quantities for Bill lines
    $stmt = $DB->prepare("SELECT bill_line_id, SUM(qty_matched) AS matched_qty FROM ap_match_links WHERE company_id = ? AND bill_line_id IS NOT NULL GROUP BY bill_line_id");
    $stmt->execute([$companyId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $matchedBill[(int)$row['bill_line_id']] = (float)$row['matched_qty'];
    }

    // Fetch PO lines for this supplier
    $sqlPo = "SELECT pol.id, pol.po_id, pol.description, pol.qty, pol.unit, pol.unit_price, po.po_number
              FROM purchase_order_lines pol
              JOIN purchase_orders po ON po.id = pol.po_id
              WHERE po.company_id = ? AND po.supplier_id = ? AND po.status != 'cancelled'
              ORDER BY po.po_number, pol.id";
    $stmt = $DB->prepare($sqlPo);
    $stmt->execute([$companyId, $supplierId]);
    $poLines = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $qtyOrdered = (float)$row['qty'];
        $matchedQty = $matchedPo[$id] ?? 0.0;
        $available  = $qtyOrdered - $matchedQty;
        $poLines[] = [
            'id' => $id,
            'po_id' => (int)$row['po_id'],
            'description' => $row['description'],
            'qty_ordered' => $qtyOrdered,
            'qty_matched' => $matchedQty,
            'qty_available' => $available,
            'unit' => $row['unit'],
            'unit_price' => (float)$row['unit_price'],
            'po_number' => $row['po_number'],
        ];
    }

    // Fetch GRN lines for this supplier
    // Join purchase_orders to ensure supplier matches
    $sqlGrn = "SELECT gl.id, gl.grn_id, gl.po_line_id, gl.qty_received,
                      grn.grn_number, grn.po_id AS grn_po_id,
                      pol.description AS po_description, pol.unit_price AS po_unit_price, pol.qty AS po_qty
               FROM grn_lines gl
               JOIN goods_received_notes grn ON grn.id = gl.grn_id
               JOIN purchase_orders po ON po.id = grn.po_id
               LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
               WHERE po.company_id = ? AND po.supplier_id = ? AND grn.status != 'cancelled'
               ORDER BY grn.grn_number, gl.id";
    $stmt = $DB->prepare($sqlGrn);
    $stmt->execute([$companyId, $supplierId]);
    $grnLines = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $qtyReceived = (float)$row['qty_received'];
        $matchedQty  = $matchedGrn[$id] ?? 0.0;
        $available   = $qtyReceived - $matchedQty;
        $grnLines[] = [
            'id' => $id,
            'grn_id' => (int)$row['grn_id'],
            'po_line_id' => $row['po_line_id'] !== null ? (int)$row['po_line_id'] : null,
            'qty_received' => $qtyReceived,
            'qty_matched' => $matchedQty,
            'qty_available' => $available,
            'grn_number' => $row['grn_number'],
            'po_id' => (int)$row['grn_po_id'],
            'po_description' => $row['po_description'],
            'po_unit_price' => $row['po_unit_price'] !== null ? (float)$row['po_unit_price'] : null,
            'po_qty' => $row['po_qty'] !== null ? (float)$row['po_qty'] : null
        ];
    }

    // Fetch Bill lines for this supplier
    $sqlBill = "SELECT bl.id, bl.bill_id, bl.item_description, bl.quantity, bl.unit, bl.unit_price,
                       b.vendor_invoice_number, b.status
                FROM ap_bill_lines bl
                JOIN ap_bills b ON b.id = bl.bill_id
                WHERE b.company_id = ? AND b.supplier_id = ? AND b.status != 'cancelled' AND b.status != 'blocked'
                ORDER BY b.vendor_invoice_number, bl.id";
    $stmt = $DB->prepare($sqlBill);
    $stmt->execute([$companyId, $supplierId]);
    $billLines = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $qty = (float)$row['quantity'];
        $matchedQty = $matchedBill[$id] ?? 0.0;
        $available  = $qty - $matchedQty;
        $billLines[] = [
            'id' => $id,
            'bill_id' => (int)$row['bill_id'],
            'description' => $row['item_description'],
            'qty' => $qty,
            'qty_matched' => $matchedQty,
            'qty_available' => $available,
            'unit' => $row['unit'],
            'unit_price' => (float)$row['unit_price'],
            'invoice_number' => $row['vendor_invoice_number'],
            'status' => $row['status']
        ];
    }

    echo json_encode([
        'po_lines' => $poLines,
        'grn_lines' => $grnLines,
        'bill_lines' => $billLines
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch matching lines: ' . $e->getMessage()]);
}