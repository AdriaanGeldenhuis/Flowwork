<?php
// /finances/ap/api/payment_create.php
// Creates an AP payment and its allocations, then posts it to the GL via PostingService.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../../finances/lib/PostingService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$supplierId     = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;
$paymentDate    = $input['payment_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payment date format']);
    exit;
}
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

// SARS: Validate allocations don't exceed outstanding balance per bill
foreach ($allocations as $al) {
    $bId = isset($al['bill_id']) ? (int)$al['bill_id'] : 0;
    $amt = isset($al['amount']) ? (float)$al['amount'] : 0.0;
    if ($bId && $amt > 0) {
        $stmtBal = $DB->prepare(
            "SELECT b.total,
                    COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = ?), 0) AS paid,
                    COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = ?), 0) AS credited
             FROM ap_bills b WHERE b.id = ? AND b.company_id = ?"
        );
        $stmtBal->execute([$bId, $bId, $bId, $companyId]);
        $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $remaining = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
            if ($amt > $remaining + 0.01) {
                echo json_encode(['ok' => false, 'error' => 'Payment of R' . number_format($amt, 2) . ' exceeds outstanding balance of R' . number_format($remaining, 2) . ' on bill #' . $bId]);
                exit;
            }
        }
    }
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