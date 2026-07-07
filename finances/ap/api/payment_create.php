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

// Aggregate requested allocation per bill (guards against the same bill
// appearing more than once in a single request). The payment total is the
// sum of valid allocations only.
$perBill = [];
foreach ($allocations as $al) {
    $bId = isset($al['bill_id']) ? (int)$al['bill_id'] : 0;
    $amt = isset($al['amount']) ? (float)$al['amount'] : 0.0;
    if ($bId <= 0 || $amt <= 0) {
        continue;
    }
    $perBill[$bId] = ($perBill[$bId] ?? 0.0) + $amt;
}
$totalAmount = array_sum($perBill);
if (!$perBill || $totalAmount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Payment amount must be greater than zero']);
    exit;
}

try {
    $DB->beginTransaction();
    // Validate every allocated bill inside the transaction: it must exist,
    // belong to this company AND to the paid supplier, and have enough
    // remaining balance (total - prior payments - prior credits). Rows are
    // locked FOR UPDATE so concurrent payments/credits serialise per bill.
    $stmtBal = $DB->prepare(
        "SELECT b.total, b.supplier_id,
                COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = b.id), 0) AS paid,
                COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = b.id), 0) AS credited
         FROM ap_bills b WHERE b.id = ? AND b.company_id = ? FOR UPDATE"
    );
    foreach ($perBill as $bId => $amt) {
        $stmtBal->execute([$bId, $companyId]);
        $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Bill #' . $bId . ' was not found for this company']);
            exit;
        }
        if ((int)$row['supplier_id'] !== $supplierId) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Bill #' . $bId . ' belongs to a different supplier than this payment']);
            exit;
        }
        $remaining = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
        if ($amt > $remaining + 0.01) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Payment of R' . number_format($amt, 2) . ' exceeds outstanding balance of R' . number_format($remaining, 2) . ' on bill #' . $bId]);
            exit;
        }
    }
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
    // Insert allocations (validated per-bill amounts)
    $allocStmt = $DB->prepare(
        "INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount, created_at) VALUES (?, ?, ?, NOW())"
    );
    foreach ($perBill as $billId => $amt) {
        $allocStmt->execute([$paymentId, $billId, $amt]);
    }
    $DB->commit();
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('AP payment create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Post to GL using PostingService. postSupplierPayment() manages its own
// transaction (beginTransaction/commit/rollback), so it must run after our
// commit; if posting fails we compensate by removing the payment and its
// allocations just created, so no payment can exist without a journal.
try {
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postSupplierPayment($paymentId);
} catch (Exception $e) {
    error_log('AP payment GL post failed (payment #' . $paymentId . '), rolling back payment: ' . $e->getMessage());
    try {
        $del = $DB->prepare("DELETE FROM ap_payment_allocations WHERE ap_payment_id = ?");
        $del->execute([$paymentId]);
        $del = $DB->prepare("DELETE FROM ap_payments WHERE id = ? AND company_id = ?");
        $del->execute([$paymentId, $companyId]);
    } catch (Exception $cleanupEx) {
        error_log('AP payment compensating cleanup failed (payment #' . $paymentId . '): ' . $cleanupEx->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => 'GL posting failed, payment was not recorded: ' . $e->getMessage()]);
    exit;
}
echo json_encode(['ok' => true, 'payment_id' => $paymentId]);