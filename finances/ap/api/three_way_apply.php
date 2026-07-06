<?php
// /finances/ap/api/three_way_apply.php – Apply matches between PO, GRN and Bill lines

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../lib/http.php'; // provides json_error()

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    json_error('Error');
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Role check: only admin/bookkeeper can apply matches
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Read the JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    json_error('Error');
}

// Expect an array of matches under the "matches" key or treat the whole input as an array of matches
$matches = [];
if (isset($data['matches']) && is_array($data['matches'])) {
    $matches = $data['matches'];
} elseif (isset($data[0])) {
    // Input is an array of matches directly
    $matches = $data;
} else {
    http_response_code(400);
    json_error('Error');
}

if ($companyId <= 0 || $userId <= 0) {
    http_response_code(400);
    json_error('Error');
}

// Prepare the insert statement
$insertSql = "INSERT INTO ap_match_links (company_id, po_line_id, grn_line_id, bill_line_id, qty_matched, created_by) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $DB->prepare($insertSql);

$inserted = 0;
$errors = [];

try {
    $DB->beginTransaction();

    // Validators: each referenced line must exist, belong to this company
    // (and not be cancelled/blocked), and expose its supplier + total qty and
    // the qty already matched. Line rows are locked FOR UPDATE so concurrent
    // matching serialises.
    $poStmt = $DB->prepare(
        "SELECT po.supplier_id, pol.qty AS line_qty,
                COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE po_line_id = pol.id), 0) AS matched_qty
         FROM purchase_order_lines pol
         JOIN purchase_orders po ON po.id = pol.po_id
         WHERE pol.id = ? AND po.company_id = ? AND po.status != 'cancelled'
         FOR UPDATE"
    );
    $grnStmt = $DB->prepare(
        "SELECT po.supplier_id, gl.qty_received AS line_qty,
                COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE grn_line_id = gl.id), 0) AS matched_qty
         FROM grn_lines gl
         JOIN goods_received_notes grn ON grn.id = gl.grn_id
         JOIN purchase_orders po ON po.id = grn.po_id
         WHERE gl.id = ? AND po.company_id = ? AND grn.status != 'cancelled'
         FOR UPDATE"
    );
    $billStmt = $DB->prepare(
        "SELECT b.supplier_id, bl.quantity AS line_qty,
                COALESCE((SELECT SUM(qty_matched) FROM ap_match_links WHERE bill_line_id = bl.id), 0) AS matched_qty
         FROM ap_bill_lines bl
         JOIN ap_bills b ON b.id = bl.bill_id
         WHERE bl.id = ? AND b.company_id = ? AND b.status NOT IN ('cancelled','blocked')
         FOR UPDATE"
    );

    // Track quantity consumed per line within this request so a batch cannot
    // exceed a line's available quantity across multiple match rows.
    $usedQty = ['po' => [], 'grn' => [], 'bill' => []];

    foreach ($matches as $idx => $m) {
        $rowNo = $idx + 1;
        // Each match should be an associative array
        $poLineId  = isset($m['po_line_id']) && $m['po_line_id'] !== '' ? (int)$m['po_line_id'] : null;
        $grnLineId = isset($m['grn_line_id']) && $m['grn_line_id'] !== '' ? (int)$m['grn_line_id'] : null;
        $billLineId = isset($m['bill_line_id']) && $m['bill_line_id'] !== '' ? (int)$m['bill_line_id'] : null;
        $qty        = isset($m['qty']) ? (float)$m['qty'] : 0.0;

        if ($qty <= 0) {
            $DB->rollBack();
            json_error('Match #' . $rowNo . ': quantity must be greater than zero');
        }
        if ($poLineId === null && $grnLineId === null && $billLineId === null) {
            $DB->rollBack();
            json_error('Match #' . $rowNo . ': at least one of PO/GRN/Bill line is required');
        }

        // Resolve and validate each referenced line
        $suppliers = [];
        $checks = [
            'po'   => [$poStmt,   $poLineId,   'PO line'],
            'grn'  => [$grnStmt,  $grnLineId,  'GRN line'],
            'bill' => [$billStmt, $billLineId, 'Bill line'],
        ];
        foreach ($checks as $kind => [$chkStmt, $lineId, $label]) {
            if ($lineId === null) {
                continue;
            }
            $chkStmt->execute([$lineId, $companyId]);
            $row = $chkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $DB->rollBack();
                json_error('Match #' . $rowNo . ': ' . $label . ' ' . $lineId . ' was not found for this company');
            }
            $suppliers[$kind] = (int)$row['supplier_id'];
            $pending   = $usedQty[$kind][$lineId] ?? 0.0;
            $available = (float)$row['line_qty'] - (float)$row['matched_qty'] - $pending;
            if ($qty > $available + 0.0001) {
                $DB->rollBack();
                json_error('Match #' . $rowNo . ': quantity ' . $qty . ' exceeds available (unmatched) quantity ' . number_format(max($available, 0), 3) . ' on ' . $label . ' ' . $lineId);
            }
        }
        // All referenced lines must share the same supplier
        if (count(array_unique($suppliers)) > 1) {
            $DB->rollBack();
            json_error('Match #' . $rowNo . ': PO/GRN/Bill lines belong to different suppliers');
        }

        // Ownership checks: every referenced line must belong to this
        // company (the ids arrive from the client and were previously
        // trusted verbatim — a cross-company reference was accepted).
        $ownershipChecks = [
            [$poLineId,  "SELECT 1 FROM purchase_order_lines pol JOIN purchase_orders po ON po.id = pol.po_id WHERE pol.id = ? AND po.company_id = ?"],
            [$grnLineId, "SELECT 1 FROM grn_lines gl JOIN goods_received_notes grn ON grn.id = gl.grn_id JOIN purchase_orders po ON po.id = grn.po_id WHERE gl.id = ? AND po.company_id = ?"],
            [$billLineId,"SELECT 1 FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id WHERE bl.id = ? AND b.company_id = ?"],
        ];
        foreach ($ownershipChecks as [$id, $sql]) {
            if ($id === null) continue;
            $chk = $DB->prepare($sql);
            $chk->execute([$id, $companyId]);
            if (!$chk->fetchColumn()) {
                throw new Exception('Referenced line does not belong to this company');
            }
        }

        // Insert the match record
        $stmt->execute([
            $companyId,
            $poLineId,
            $grnLineId,
            $billLineId,
            $qty,
            $userId
        ]);
        // Consume quantity for subsequent rows in this batch (keys must match
        // the availability read above: $usedQty['po'|'grn'|'bill'][line id])
        foreach (['po' => $poLineId, 'grn' => $grnLineId, 'bill' => $billLineId] as $kind => $lineId) {
            if ($lineId !== null) {
                $usedQty[$kind][$lineId] = ($usedQty[$kind][$lineId] ?? 0.0) + $qty;
            }
        }
        $inserted++;
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['inserted' => $inserted, 'errors' => $errors]]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    http_response_code(500);
    $msg = ($e instanceof PDOException) ? 'Failed to apply matches' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}