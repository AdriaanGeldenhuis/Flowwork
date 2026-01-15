<?php
// /finances/ap/ajax/bill.match.php – Apply PO/GRN matches for a newly created bill
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../lib/Api.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Only allow admin or bookkeeper to apply matches
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

if ($companyId <= 0 || $userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Decode JSON payload
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}
$billId  = isset($data['bill_id']) ? (int)$data['bill_id'] : 0;
$matches = $data['matches'] ?? [];

if ($billId <= 0 || !is_array($matches)) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill id or matches']);
    exit;
}

try {
    // Check bill belongs to company
    $stmt = $DB->prepare("SELECT supplier_id FROM ap_bills WHERE id = ? AND company_id = ?");
    $stmt->execute([$billId, $companyId]);
    $billRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$billRow) {
        echo json_encode(['ok' => false, 'error' => 'Bill not found']);
        exit;
    }
    $supplierId = (int)$billRow['supplier_id'];
    // Fetch bill lines and map by sort_order (index) to id
    $stmt = $DB->prepare("SELECT id, sort_order FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$billId]);
    $billLines = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $billLines[(int)$row['sort_order']] = (int)$row['id'];
    }
    if (empty($billLines)) {
        echo json_encode(['ok' => false, 'error' => 'No bill lines found']);
        exit;
    }
    // Prepare insert statement
    $insertSql = "INSERT INTO ap_match_links (company_id, po_line_id, grn_line_id, bill_line_id, qty_matched, created_by) VALUES (?, ?, ?, ?, ?, ?)";
    $insertStmt = $DB->prepare($insertSql);
    $inserted = 0;
    $errors   = [];
    $DB->beginTransaction();
    foreach ($matches as $m) {
        // Accept bill_line_index or bill_line_id; prefer index
        $billLineIndex = null;
        $billLineId    = null;
        if (isset($m['bill_line_index'])) {
            $billLineIndex = (int)$m['bill_line_index'];
            if (isset($billLines[$billLineIndex])) {
                $billLineId = $billLines[$billLineIndex];
            }
        } elseif (isset($m['bill_line_id'])) {
            $billLineId = (int)$m['bill_line_id'];
        }
        $poLineId  = isset($m['po_line_id']) && $m['po_line_id'] !== '' ? (int)$m['po_line_id'] : null;
        $grnLineId = isset($m['grn_line_id']) && $m['grn_line_id'] !== '' ? (int)$m['grn_line_id'] : null;
        $qty       = isset($m['qty']) ? (float)$m['qty'] : 0.0;
        if ($qty <= 0 || $billLineId === null) {
            // skip invalid
            continue;
        }
        // Optional: validate PO and GRN lines belong to same supplier; skip if not
        if ($poLineId !== null) {
            // Validate poLine belongs to this company and supplier
            $stmtVal = $DB->prepare("SELECT po.id FROM purchase_order_lines pol JOIN purchase_orders po ON po.id = pol.po_id WHERE pol.id = ? AND po.company_id = ? AND po.supplier_id = ? AND po.status != 'cancelled'");
            $stmtVal->execute([$poLineId, $companyId, $supplierId]);
            if (!$stmtVal->fetch()) {
                $errors[] = 'PO line '. $poLineId .' invalid';
                continue;
            }
        }
        if ($grnLineId !== null) {
            // Validate grnLine belongs to same supplier via its GRN -> PO
            $stmtVal = $DB->prepare("SELECT po.id FROM grn_lines gl JOIN goods_received_notes grn ON grn.id = gl.grn_id JOIN purchase_orders po ON po.id = grn.po_id WHERE gl.id = ? AND po.company_id = ? AND po.supplier_id = ? AND grn.status != 'cancelled'");
            $stmtVal->execute([$grnLineId, $companyId, $supplierId]);
            if (!$stmtVal->fetch()) {
                $errors[] = 'GRN line '. $grnLineId .' invalid';
                continue;
            }
        }
        // Insert match
        $insertStmt->execute([
            $companyId,
            $poLineId,
            $grnLineId,
            $billLineId,
            $qty,
            $userId
        ]);
        $inserted++;
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'inserted' => $inserted, 'errors' => $errors]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bill match error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to apply matches']);
}