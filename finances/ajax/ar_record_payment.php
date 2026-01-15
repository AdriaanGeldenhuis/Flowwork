<?php
// /finances/ajax/ar_record_payment.php
//
// Endpoint for recording a new accounts receivable (customer) payment.
// Supports allocating a payment to one or multiple invoices and ensures
// period locks are respected. After recording, it posts the payment
// to the general ledger via ArService/PostingService and updates
// invoice balances and statuses accordingly.

// Dynamically load init, auth, and permissions. Determine the project root
// two levels above the current file. If an `/app` directory exists, use it;
// otherwise fall back to the root-level files.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/ArService.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Enforce finance role permissions
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Decode JSON body. Supports both single-invoice and multi-allocation inputs.
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// Extract parameters
$invoiceId  = isset($data['invoice_id']) ? (int)$data['invoice_id'] : 0;
$allocations = isset($data['allocations']) && is_array($data['allocations']) ? $data['allocations'] : [];
$amount     = isset($data['amount']) ? floatval($data['amount']) : 0.0;
$method     = isset($data['method']) && $data['method'] ? trim((string)$data['method']) : 'eft';
$paymentDate = isset($data['payment_date']) && $data['payment_date'] ? $data['payment_date'] : date('Y-m-d');
$reference   = isset($data['reference']) ? trim((string)$data['reference']) : '';
$notes       = isset($data['notes']) ? trim((string)$data['notes']) : '';
$idempotencyKey = isset($data['idempotency_key']) ? trim((string)$data['idempotency_key']) : '';

// Basic validation
if ($amount <= 0 && empty($allocations)) {
    echo json_encode(['ok' => false, 'error' => 'Payment amount must be greater than zero']);
    exit;
}

// If allocations are not provided but invoice_id & amount are, create a default allocation
if (!$allocations && $invoiceId && $amount > 0) {
    $allocations = [
        ['invoice_id' => $invoiceId, 'amount' => $amount]
    ];
}

// Validate allocations: must contain invoice_id and positive amount
foreach ($allocations as $alloc) {
    if (empty($alloc['invoice_id']) || !isset($alloc['amount']) || floatval($alloc['amount']) <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid allocation data']);
        exit;
    }
}

try {
    // Period lock check on the payment date
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($paymentDate)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Cannot record payment to locked period (' . $paymentDate . ')'
        ]);
        exit;
    }

    // Idempotency: If idempotency_key provided, treat as unique reference. Prevent duplicate record.
    if ($idempotencyKey !== '') {
        $stmt = $DB->prepare("SELECT id FROM payments WHERE company_id = ? AND reference = ? LIMIT 1");
        $stmt->execute([$companyId, $idempotencyKey]);
        if ($stmt->fetchColumn()) {
            echo json_encode(['ok' => false, 'duplicate' => true, 'error' => 'Payment already recorded']);
            exit;
        }
        // Use key as reference
        $reference = $idempotencyKey;
    }

    // Begin transaction
    $DB->beginTransaction();

    // Sum allocations to determine total amount
    $totalAlloc = 0.0;
    foreach ($allocations as $alloc) {
        $totalAlloc += floatval($alloc['amount']);
    }
    if ($amount <= 0) {
        $amount = $totalAlloc;
    }
    // If user supplied amount and allocations sum differs, use allocations sum
    if (abs($totalAlloc - $amount) > 0.0001) {
        $amount = $totalAlloc;
    }

    // Insert payment record
    $stmt = $DB->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$companyId, $paymentDate, $amount, $method, $reference ?: null, $notes ?: null, $userId]);
    $paymentId = (int)$DB->lastInsertId();

    // Allocate amounts to invoices and update invoice balances/statuses
    foreach ($allocations as $alloc) {
        $invId  = (int)$alloc['invoice_id'];
        $allocAmt = floatval($alloc['amount']);
        // Insert allocation
        $stmt = $DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
        $stmt->execute([$paymentId, $invId, $allocAmt]);
        // Fetch invoice to compute new balance
        $stmtInv = $DB->prepare("SELECT balance_due, status, total, paid_at FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $stmtInv->execute([$invId, $companyId]);
        $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            throw new Exception('Invoice not found (ID ' . $invId . ')');
        }
        $newBalance = floatval($inv['balance_due']) - $allocAmt;
        if ($newBalance < 0) {
            $newBalance = 0.0;
        }
        $newStatus  = ($newBalance <= 0.001) ? 'paid' : 'part-paid';
        $paidAt = $inv['paid_at'];
        if ($newStatus === 'paid') {
            $paidAt = date('Y-m-d H:i:s');
        }
        // Update invoice balance and status
        $upd = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $upd->execute([$newBalance, $newStatus, $paidAt, $invId, $companyId]);
    }

    // Write audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'ar_payment_recorded', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['payment_id' => $paymentId, 'amount' => $amount, 'allocations' => $allocations]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    // Post to GL using ArService
    try {
        $arService = new ArService($DB, $companyId, $userId);
        $arService->postCustomerPayment($paymentId);
    } catch (Exception $ex) {
        error_log('AR payment posting failed: ' . $ex->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'payment_id' => $paymentId
    ]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('AR record payment error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
