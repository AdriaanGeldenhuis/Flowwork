<?php
// /finances/ap/api/bill_create.php
// Creates a new AP bill and its lines. Accepts JSON POST with
// header and lines similar to receipts/api/save_bill.php but
// simplified. Returns bill ID on success.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Only admin/bookkeeper can create bills
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Expect JSON body: {header: {...}, lines: [...]} similar to receipts
$input = json_decode(file_get_contents('php://input'), true);
$header = $input['header'] ?? [];
$lines  = $input['lines'] ?? [];

// Validate required fields
if (empty($header['supplier_id']) || empty($header['invoice_number']) || empty($header['invoice_date']) || !isset($header['total'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing required header fields']);
    exit;
}

$supplierId  = (int)$header['supplier_id'];
$invoiceNo   = trim($header['invoice_number']);
$invoiceDate = $header['invoice_date'];
$dueDate     = $header['due_date'] ?? null;
$currency    = $header['currency'] ?? 'ZAR';
$subtotal    = isset($header['subtotal']) ? (float)$header['subtotal'] : 0.0;
$tax         = isset($header['tax']) ? (float)$header['tax'] : 0.0;
$total       = (float)$header['total'];
$notes       = $header['notes'] ?? null;

// Compute fingerprint to avoid duplicates: invoice number + date + total + supplier
$hash = sha1($invoiceNo . '|' . $invoiceDate . '|' . $total . '|' . $supplierId);

// Check if duplicate exists
$stmt = $DB->prepare("SELECT id FROM ap_bills WHERE company_id = ? AND hash_fingerprint = ? LIMIT 1");
$stmt->execute([$companyId, $hash]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate bill detected']);
    exit;
}

try {
    $DB->beginTransaction();
    // Insert bill header
    $stmt = $DB->prepare(
        "INSERT INTO ap_bills (company_id, supplier_id, vendor_invoice_number, vendor_vat, issue_date, due_date,\n"
        . "currency, subtotal, tax, total, status, ocr_id, file_id, hash_fingerprint, journal_id, notes, created_by, created_at)\n"
        . "VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'draft', NULL, NULL, ?, NULL, ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $supplierId,
        $invoiceNo,
        $invoiceDate,
        $dueDate,
        $currency,
        $subtotal,
        $tax,
        $total,
        $hash,
        $notes,
        $userId
    ]);
    $billId = (int)$DB->lastInsertId();
    // Insert bill lines
    $sort = 0;
    foreach ($lines as $line) {
        $desc       = trim($line['description'] ?? '');
        $qty        = isset($line['qty']) ? (float)$line['qty'] : 1.0;
        $unit       = $line['unit'] ?? 'ea';
        $price      = isset($line['unit_price']) ? (float)$line['unit_price'] : 0.0;
        $discount   = isset($line['discount']) ? (float)$line['discount'] : 0.0;
        $taxRate    = isset($line['tax_rate']) ? (float)$line['tax_rate'] : 15.0;
        $lineTotal  = $qty * $price - $discount;
        $glAccount  = isset($line['gl_account_id']) && $line['gl_account_id'] ? (int)$line['gl_account_id'] : null;
        $inventoryItem = isset($line['inventory_item_id']) && $line['inventory_item_id'] !== '' ? (int)$line['inventory_item_id'] : null;
        $boardId    = isset($line['project_board_id']) && $line['project_board_id'] ? (int)$line['project_board_id'] : null;
        $itemId     = isset($line['project_item_id']) && $line['project_item_id'] ? (int)$line['project_item_id'] : null;
        $stmtL = $DB->prepare(
            "INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, sort_order, gl_account_id, project_board_id, project_item_id, inventory_item_id)\n"
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtL->execute([
            $billId,
            $desc,
            $qty,
            $unit,
            $price,
            $discount,
            $taxRate,
            $lineTotal,
            $sort,
            $glAccount,
            $boardId,
            $itemId,
            $inventoryItem
        ]);
        $sort++;
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'bill_id' => $billId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('AP bill create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create bill']);
}