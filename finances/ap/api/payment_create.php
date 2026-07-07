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
$idempotencyKey = isset($input['idempotency_key']) ? substr(trim((string)$input['idempotency_key']), 0, 64) : '';

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

    // Idempotency: a replayed request (double-click / retry) with the same
    // key returns the original payment. Unique (company_id, idempotency_key)
    // is the backstop for truly concurrent replays.
    if ($idempotencyKey !== '') {
        $stmt = $DB->prepare("SELECT id FROM ap_payments WHERE company_id = ? AND idempotency_key = ? LIMIT 1");
        $stmt->execute([$companyId, $idempotencyKey]);
        if ($existingId = $stmt->fetchColumn()) {
            $DB->rollBack();
            echo json_encode(['ok' => true, 'payment_id' => (int)$existingId, 'duplicate' => true]);
            exit;
        }
    }

    // Validate allocations do not exceed each bill's outstanding balance —
    // INSIDE the transaction, with the bill rows locked, so two concurrent
    // payments cannot both pass the check (the old check-then-act ran before
    // the transaction started).
    foreach ($allocations as $al) {
        $bId = isset($al['bill_id']) ? (int)$al['bill_id'] : 0;
        $amt = isset($al['amount']) ? (float)$al['amount'] : 0.0;
        if (!$bId || $amt <= 0) {
            continue;
        }
        $stmtBal = $DB->prepare(
            "SELECT b.total, b.supplier_id,
                    COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = ?), 0) AS paid,
                    COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = ?), 0) AS credited
             FROM ap_bills b WHERE b.id = ? AND b.company_id = ? FOR UPDATE"
        );
        $stmtBal->execute([$bId, $bId, $bId, $companyId]);
        $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Bill #' . $bId . ' not found');
        }
        if ((int)$row['supplier_id'] !== $supplierId) {
            throw new Exception('Bill #' . $bId . ' belongs to a different supplier than this payment');
        }
        $remaining = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
        if ($amt > $remaining + 0.01) {
            throw new Exception('Payment of R' . number_format($amt, 2) . ' exceeds outstanding balance of R' . number_format($remaining, 2) . ' on bill #' . $bId);
        }
    }

    // Insert payment header
    $stmt = $DB->prepare(
        "INSERT INTO ap_payments (company_id, supplier_id, bank_account_id, amount, payment_date, method, reference, notes, journal_id, idempotency_key, created_by, created_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW())"
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
        $idempotencyKey !== '' ? $idempotencyKey : null,
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

    // Post to GL INSIDE this transaction: a payment must never exist without
    // its journal (the old flow committed first and only error_log'd a
    // posting failure, silently desyncing the AP subledger from the GL).
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postSupplierPayment($paymentId);

    $DB->commit();
    echo json_encode(['ok' => true, 'payment_id' => $paymentId]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('AP payment create error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to record payment' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}