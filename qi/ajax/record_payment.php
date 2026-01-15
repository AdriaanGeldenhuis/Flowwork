<?php
// /qi/ajax/record_payment.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Support JSON input as well as form-data
$rawBody = file_get_contents('php://input');
$inputData = json_decode($rawBody, true);
if (is_array($inputData)) {
    $invoiceId   = isset($inputData['invoice_id'])   ? (int)$inputData['invoice_id'] : 0;
    $paymentDate = $inputData['payment_date'] ?? date('Y-m-d');
    $amount      = isset($inputData['amount']) ? (float)$inputData['amount'] : 0;
    $method      = $inputData['method'] ?? 'eft';
    $reference   = $inputData['reference'] ?? '';
    $notes       = $inputData['notes'] ?? '';
} else {
    // Fallback to standard form POST
    $invoiceId   = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount      = floatval($_POST['amount'] ?? 0);
    $method      = $_POST['method'] ?? 'eft';
    $reference   = $_POST['reference'] ?? '';
    $notes       = $_POST['notes'] ?? '';
}

if (!$invoiceId || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Fetch invoice
    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    if ($amount > $invoice['balance_due']) {
        throw new Exception('Payment amount exceeds balance due');
    }

    // Create payment
    $stmt = $DB->prepare("
        INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$companyId, $paymentDate, $amount, $method, $reference, $notes, $userId]);
    $paymentId = $DB->lastInsertId();

    // Allocate to invoice
    $stmt = $DB->prepare("
        INSERT INTO payment_allocations (payment_id, invoice_id, amount)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$paymentId, $invoiceId, $amount]);

    // Update invoice balance and status
    $newBalance = $invoice['balance_due'] - $amount;
    // Determine new status: fully paid => paid; partial => part-paid; else keep existing
    if ($newBalance <= 0) {
        $newStatus = 'paid';
        $paidAt    = date('Y-m-d H:i:s');
        $newBalance = 0; // ensure no negative balance
    } else {
        $newStatus = 'part-paid';
        $paidAt    = $invoice['paid_at'];
    }
    $stmt = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newBalance, $newStatus, $paidAt, $invoiceId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'payment_recorded', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['invoice_id' => $invoiceId, 'amount' => $amount, 'payment_id' => $paymentId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    // Post journal entry for this payment via PostingService
    try {
        require_once __DIR__ . '/../../finances/lib/PostingService.php';
        $posting = new PostingService($DB, $companyId, $userId);
        $posting->postCustomerPayment((int)$paymentId);
    } catch (Exception $e) {
        error_log('Payment journal posting failed: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'payment_id' => $paymentId, 'new_balance' => $newBalance]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Record payment error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}