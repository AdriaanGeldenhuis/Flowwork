<?php
// /finances/ap/api/payment_create.php
// Creates an AP payment and its allocations, then posts it to the GL via PostingService.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../../finances/lib/PostingService.php';

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
$supplierId     = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;
$paymentDate    = $input['payment_date'] ?? date('Y-m-d');
$bankAccountId  = isset($input['bank_account_id']) && $input['bank_account_id'] ? (int)$input['bank_account_id'] : null;
$method         = $input['method'] ?? 'eft';
$reference      = $input['reference'] ?? null;
$notes          = $input['notes'] ?? null;
$allocations    = $input['allocations'] ?? [];

if (!$supplierId || !$paymentDate || !$allocations) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Compute total amount from allocations
$totalAmount = 0.0;
foreach ($allocations as $al) {
    $amt = isset($al['amount']) ? (float)$al['amount'] : 0.0;
    if ($amt <= 0) {
        continue;
    }
    $totalAmount += $amt;
}
if ($totalAmount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Payment amount must be greater than zero']);
    exit;
}

try {
    $DB->beginTransaction();
    // Insert payment header
    $stmt = $DB->prepare(
        "INSERT INTO ap_payments (company_id, supplier_id, bank_account_id, amount, payment_date, method, reference, notes, journal_id, created_by, created_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $supplierId,
        $bankAccountId,
        $totalAmount,
        $paymentDate,
        $method,
        $reference,
        $notes,
        $userId
    ]);
    $paymentId = (int)$DB->lastInsertId();
    // Insert allocations
    $allocStmt = $DB->prepare(
        "INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount, created_at) VALUES (?, ?, ?, NOW())"
    );
    foreach ($allocations as $al) {
        $billId = isset($al['bill_id']) ? (int)$al['bill_id'] : 0;
        $amt    = isset($al['amount']) ? (float)$al['amount'] : 0.0;
        if ($billId && $amt > 0) {
            $allocStmt->execute([$paymentId, $billId, $amt]);
        }
    }
    $DB->commit();
    // Post to GL using PostingService
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postSupplierPayment($paymentId);
    echo json_encode(['ok' => true, 'payment_id' => $paymentId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('AP payment create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}