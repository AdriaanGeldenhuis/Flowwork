<?php
// /finances/ajax/ar_allocate_payment.php
//
// Endpoint for reallocating an existing customer payment to one or more invoices.
// This script reverses previous allocations, adjusts invoice balances and
// statuses, applies new allocations and reposts the payment to the GL via
// ArService. It respects period locks based on the payment date.

// Load init, auth, and permissions dynamically. Compute root two levels up.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Decode JSON payload
$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$paymentId   = isset($data['payment_id']) ? (int)$data['payment_id'] : 0;
$allocations = isset($data['allocations']) && is_array($data['allocations']) ? $data['allocations'] : [];

if ($paymentId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Payment ID required']);
    exit;
}
if (!$allocations) {
    echo json_encode(['ok' => false, 'error' => 'Allocations array required']);
    exit;
}

// Validate allocation entries
foreach ($allocations as $alloc) {
    if (empty($alloc['invoice_id']) || !isset($alloc['amount']) || floatval($alloc['amount']) <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid allocation data']);
        exit;
    }
}

try {
    // Fetch payment
    $stmt = $DB->prepare("SELECT * FROM payments WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$paymentId, $companyId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        echo json_encode(['ok' => false, 'error' => 'Payment not found']);
        exit;
    }
    $paymentDate = $payment['payment_date'] ?: date('Y-m-d');
    // Check period lock on payment date
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($paymentDate)) {
        echo json_encode(['ok' => false, 'error' => 'Cannot reallocate payment in locked period (' . $paymentDate . ')']);
        exit;
    }

    $DB->beginTransaction();
    // Fetch existing allocations
    $stmt = $DB->prepare("SELECT invoice_id, amount FROM payment_allocations WHERE payment_id = ?");
    $stmt->execute([$paymentId]);
    $oldAllocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Reverse previous allocations: add amount back to invoice balance and adjust status
    foreach ($oldAllocs as $oa) {
        $invId = (int)$oa['invoice_id'];
        $amt   = floatval($oa['amount']);
        // Fetch invoice
        $stmtInv = $DB->prepare("SELECT balance_due, total, status, paid_at FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $stmtInv->execute([$invId, $companyId]);
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if ($invoice) {
            $newBalance = floatval($invoice['balance_due']) + $amt;
            if ($newBalance > floatval($invoice['total'])) {
                $newBalance = floatval($invoice['total']);
            }
            $newStatus  = $newBalance <= 0.001 ? 'paid' : 'part-paid';
            // If it becomes unpaid (newBalance > 0) reset paid_at
            $paidAt = $invoice['paid_at'];
            if ($newBalance > 0.001) {
                $paidAt = null;
            }
            $upd = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $upd->execute([$newBalance, $newStatus, $paidAt, $invId, $companyId]);
        }
    }
    // Delete existing allocations
    $stmt = $DB->prepare("DELETE FROM payment_allocations WHERE payment_id = ?");
    $stmt->execute([$paymentId]);

    // Sum new allocations to recalc payment amount
    $newTotal = 0.0;
    foreach ($allocations as $na) {
        $newTotal += floatval($na['amount']);
    }
    // Update payment amount if different
    if (abs(floatval($payment['amount']) - $newTotal) > 0.0001) {
        $stmt = $DB->prepare("UPDATE payments SET amount = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newTotal, $paymentId, $companyId]);
    }
    // Apply new allocations and adjust invoice balances
    foreach ($allocations as $na) {
        $invId  = (int)$na['invoice_id'];
        $amt    = floatval($na['amount']);
        // Insert allocation
        $stmt = $DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
        $stmt->execute([$paymentId, $invId, $amt]);
        // Fetch invoice
        $stmtInv = $DB->prepare("SELECT balance_due, total, paid_at FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $stmtInv->execute([$invId, $companyId]);
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new Exception('Invoice not found (ID ' . $invId . ')');
        }
        $newBalance = floatval($invoice['balance_due']) - $amt;
        if ($newBalance < 0) {
            $newBalance = 0.0;
        }
        $newStatus  = ($newBalance <= 0.001) ? 'paid' : 'part-paid';
        $paidAt = $invoice['paid_at'];
        if ($newStatus === 'paid') {
            $paidAt = date('Y-m-d H:i:s');
        }
        $upd = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $upd->execute([$newBalance, $newStatus, $paidAt, $invId, $companyId]);
    }
    // Audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'ar_payment_allocated', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['payment_id' => $paymentId, 'allocations' => $allocations]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    // Repost journal to reflect new allocations
    try {
        $arService = new ArService($DB, $companyId, $userId);
        $arService->postCustomerPayment($paymentId);
    } catch (Exception $ex) {
        error_log('AR payment re-posting failed: ' . $ex->getMessage());
    }
    echo json_encode(['ok' => true, 'payment_id' => $paymentId]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('AR allocate payment error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
