<?php
// /finances/ap/api/vendor_credit_create.php
// Creates a vendor credit and its lines. Accepts JSON with header and
// lines. Returns credit_id on success. Does not post to GL; that
// happens when allocations are applied via vendor_credit_post.php.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

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

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$header = $input['header'] ?? [];
$lines  = $input['lines'] ?? [];

if (empty($header['supplier_id']) || empty($header['credit_number']) || empty($header['issue_date'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}
$supplierId   = (int)$header['supplier_id'];
$creditNumber = trim($header['credit_number']);
$issueDate    = $header['issue_date'];
$notes        = $header['notes'] ?? null;

// Compute amounts
$subtotal = 0.0;
$tax      = 0.0;
foreach ($lines as $ln) {
    $qty      = isset($ln['qty']) ? (float)$ln['qty'] : 1.0;
    $price    = isset($ln['unit_price']) ? (float)$ln['unit_price'] : 0.0;
    $discount = isset($ln['discount']) ? (float)$ln['discount'] : 0.0;
    $taxRate  = isset($ln['tax_rate']) ? (float)$ln['tax_rate'] : 15.0;
    $net      = ($qty * $price) - $discount;
    $vat      = ($taxRate > 0) ? $net * ($taxRate / 100.0) : 0.0;
    $subtotal += $net;
    $tax      += $vat;
}
$total = $subtotal + $tax;

try {
    $DB->beginTransaction();
    // Insert vendor credit header
    $stmt = $DB->prepare(
        "INSERT INTO vendor_credits (company_id, credit_number, supplier_id, issue_date, status, subtotal, tax, total, journal_id, notes, created_by, created_at)\n"
        . "VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, NULL, ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $creditNumber,
        $supplierId,
        $issueDate,
        $subtotal,
        $tax,
        $total,
        $notes,
        $userId
    ]);
    $creditId = (int)$DB->lastInsertId();
    // Insert lines
    $sort = 0;
    $lineStmt = $DB->prepare(
        "INSERT INTO vendor_credit_lines (credit_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, gl_account_id, sort_order, project_board_id, project_item_id)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($lines as $ln) {
        $desc      = trim($ln['description'] ?? '');
        $qty       = isset($ln['qty']) ? (float)$ln['qty'] : 1.0;
        $unit      = $ln['unit'] ?? 'ea';
        $price     = isset($ln['unit_price']) ? (float)$ln['unit_price'] : 0.0;
        $discount  = isset($ln['discount']) ? (float)$ln['discount'] : 0.0;
        $taxRate   = isset($ln['tax_rate']) ? (float)$ln['tax_rate'] : 15.0;
        $lineTotal = $qty * $price - $discount;
        $glAcc     = isset($ln['gl_account_id']) && $ln['gl_account_id'] ? (int)$ln['gl_account_id'] : null;
        $boardId   = isset($ln['project_board_id']) && $ln['project_board_id'] ? (int)$ln['project_board_id'] : null;
        $itemId    = isset($ln['project_item_id']) && $ln['project_item_id'] ? (int)$ln['project_item_id'] : null;
        $lineStmt->execute([
            $creditId,
            $desc,
            $qty,
            $unit,
            $price,
            $discount,
            $taxRate,
            $lineTotal,
            $glAcc,
            $sort,
            $boardId,
            $itemId
        ]);
        $sort++;
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'credit_id' => $creditId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Vendor credit create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create vendor credit']);
}