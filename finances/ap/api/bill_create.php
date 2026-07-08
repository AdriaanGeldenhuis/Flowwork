<?php
// /finances/ap/api/bill_create.php
// Creates a new AP bill and its lines. Accepts JSON POST with
// header and lines similar to receipts/api/save_bill.php but
// simplified. Returns bill ID on success.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../lib/http.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

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
// The supplier must belong to THIS company — otherwise a crafted payload could
// create a bill against another tenant's supplier.
$__sc = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
$__sc->execute([$supplierId, $companyId]);
if (!$__sc->fetchColumn()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid supplier']);
    exit;
}
$invoiceNo   = trim($header['invoice_number']);
$invoiceDate = $header['invoice_date'];

// Validate date formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid invoice date format']);
    exit;
}
$dueDate     = $header['due_date'] ?? null;
if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid due date format']);
    exit;
}
$currency    = $header['currency'] ?? 'ZAR';
$notes       = $header['notes'] ?? null;

// Foreign-currency AP is not implemented: postApBill has no FX conversion and
// would post the foreign face value as ZAR, mis-stating the expense, AP control
// and input VAT (ap_payments.exchange_rate is never captured either). Reject
// non-ZAR bills until AP FX is built (postApBill enforces the same guard).
if (strtoupper(trim((string)$currency)) !== 'ZAR' && trim((string)$currency) !== '') {
    json_error('Foreign-currency bills are not supported yet — capture the bill in ZAR.');
}

// Recompute header totals from the lines server-side; never trust the
// client-supplied subtotal/tax/total. Reject if they disagree by > 0.01.
if (!is_array($lines) || count($lines) === 0) {
    json_error('Bill must have at least one line');
}
// Default VAT rate from the company's STD tax code — never a hardcoded 15.
// The same default is applied when the lines are inserted below, so the
// recomputed totals always match the stored lines.
require_once __DIR__ . '/../../lib/TaxCodes.php';
$taxCodes = new TaxCodes($DB, (int)$companyId);
$defaultRate = $taxCodes->standardRatePercent();
$computedSubtotal = 0.0;
$computedTax      = 0.0;
foreach ($lines as $line) {
    $qty      = isset($line['qty']) ? (float)$line['qty'] : 1.0;
    $price    = isset($line['unit_price']) ? (float)$line['unit_price'] : 0.0;
    $discount = isset($line['discount']) ? (float)$line['discount'] : 0.0;
    $taxRate  = isset($line['tax_rate']) && $line['tax_rate'] !== '' ? (float)$line['tax_rate'] : $defaultRate;
    $net      = ($qty * $price) - $discount;
    $computedSubtotal += $net;
    $computedTax      += ($taxRate > 0) ? $net * ($taxRate / 100.0) : 0.0;
}
$computedSubtotal = round($computedSubtotal, 2);
$computedTax      = round($computedTax, 2);
$computedTotal    = round($computedSubtotal + $computedTax, 2);

$clientSubtotal = isset($header['subtotal']) ? (float)$header['subtotal'] : $computedSubtotal;
$clientTax      = isset($header['tax']) ? (float)$header['tax'] : $computedTax;
$clientTotal    = (float)$header['total'];
if (abs($clientSubtotal - $computedSubtotal) > 0.01
    || abs($clientTax - $computedTax) > 0.01
    || abs($clientTotal - $computedTotal) > 0.01) {
    json_error(sprintf(
        'Header totals do not match bill lines. Submitted subtotal/tax/total: %.2f/%.2f/%.2f; computed from lines: %.2f/%.2f/%.2f',
        $clientSubtotal, $clientTax, $clientTotal,
        $computedSubtotal, $computedTax, $computedTotal
    ));
}
// Store the server-computed values
$subtotal = $computedSubtotal;
$tax      = $computedTax;
$total    = $computedTotal;

// SARS: Capture and validate supplier VAT number
$vendorVat   = isset($header['vendor_vat']) ? trim($header['vendor_vat']) : null;
if ($vendorVat !== null && $vendorVat !== '') {
    // SA VAT numbers: 10 digits starting with 4
    if (!preg_match('/^4\d{9}$/', $vendorVat)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid supplier VAT number format (must be 10 digits starting with 4)']);
        exit;
    }
} else {
    $vendorVat = null;
}

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
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NULL, NULL, ?, NULL, ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $supplierId,
        $invoiceNo,
        $vendorVat,
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
    // Insert bill lines ($defaultRate resolved above, before the totals check)
    $sort = 0;
    foreach ($lines as $line) {
        $desc       = trim($line['description'] ?? '');
        $qty        = isset($line['qty']) ? (float)$line['qty'] : 1.0;
        $unit       = $line['unit'] ?? 'ea';
        $price      = isset($line['unit_price']) ? (float)$line['unit_price'] : 0.0;
        $discount   = isset($line['discount']) ? (float)$line['discount'] : 0.0;
        $taxRate    = isset($line['tax_rate']) && $line['tax_rate'] !== '' ? (float)$line['tax_rate'] : $defaultRate;
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