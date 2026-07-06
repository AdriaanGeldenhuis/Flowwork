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
    $milestoneId = isset($inputData['milestone_id']) && $inputData['milestone_id'] !== '' ? (int)$inputData['milestone_id'] : null;
    $idempotencyKey = isset($inputData['idempotency_key']) ? substr(trim((string)$inputData['idempotency_key']), 0, 64) : '';
} else {
    // Fallback to standard form POST
    $invoiceId   = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $amount      = floatval($_POST['amount'] ?? 0);
    $method      = $_POST['method'] ?? 'eft';
    $reference   = $_POST['reference'] ?? '';
    $notes       = $_POST['notes'] ?? '';
    $milestoneId = isset($_POST['milestone_id']) && $_POST['milestone_id'] !== '' ? (int)$_POST['milestone_id'] : null;
    $idempotencyKey = isset($_POST['idempotency_key']) ? substr(trim((string)$_POST['idempotency_key']), 0, 64) : '';
}

if (!$invoiceId || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Idempotency: a replayed request (double-click, network retry) with the
    // same key returns the original payment instead of creating a second one.
    // The unique index on (company_id, idempotency_key) is the backstop for
    // concurrent replays.
    if ($idempotencyKey !== '') {
        $stmt = $DB->prepare("SELECT id FROM payments WHERE company_id = ? AND idempotency_key = ? LIMIT 1");
        $stmt->execute([$companyId, $idempotencyKey]);
        if ($existingId = $stmt->fetchColumn()) {
            $DB->rollBack();
            echo json_encode(['ok' => true, 'payment_id' => (int)$existingId, 'duplicate' => true]);
            exit;
        }
    }

    // Fetch invoice (FOR UPDATE prevents concurrent overpayment)
    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    if ($amount > $invoice['balance_due']) {
        throw new Exception('Payment amount exceeds balance due');
    }

    // Paying a draft implicitly issues it: transition to sent and post it to
    // the GL so the receipt below has an invoice journal to settle against.
    if ($invoice['status'] === 'draft') {
        require_once __DIR__ . '/../lib/InvoiceLifecycle.php';
        InvoiceLifecycle::issueInvoice($DB, $companyId, $userId, $invoiceId);
    }

    // Create payment
    $stmt = $DB->prepare("
        INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by, idempotency_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$companyId, $paymentDate, $amount, $method, $reference, $notes, $userId,
        $idempotencyKey !== '' ? $idempotencyKey : null]);
    $paymentId = $DB->lastInsertId();

    // Allocate to invoice
    $stmt = $DB->prepare("
        INSERT INTO payment_allocations (payment_id, invoice_id, amount)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$paymentId, $invoiceId, $amount]);

    // ── Allocate the payment across the payment schedule (waterfall) ─────────────
    // A recorded payment always reduces the invoice's outstanding balance. When the
    // invoice has a payment schedule, MilestoneAllocator spreads the amount across
    // its phases (oldest unpaid first) and re-derives each phase's status, so the
    // schedule's paid figures and percentages stay consistent with the balance no
    // matter what amount is entered — the user just types what was received and the
    // rest is worked out automatically. ($milestoneId is accepted for backward
    // compatibility but no longer forces a single-phase allocation.)
    if (!empty($invoice['has_milestones'])) {
        require_once __DIR__ . '/../lib/MilestoneAllocator.php';
        MilestoneAllocator::allocate($DB, $companyId, $invoiceId, (int)$paymentId, $amount);
    }

    // Update invoice balance and status
    $newBalance = round((float)$invoice['balance_due'] - $amount, 2);
    // Determine new status: fully paid => paid; partial => part-paid
    if ($newBalance <= 0.005) {
        $newStatus  = 'paid';
        $paidAt     = date('Y-m-d H:i:s');
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

    // Post the receipt to the GL INSIDE this transaction: if posting fails
    // (locked period, broken account mapping) the payment must not exist
    // either — a payment with no ledger entry silently breaks the AR
    // subledger-to-GL tie-out.
    require_once __DIR__ . '/../../finances/lib/PostingService.php';
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postCustomerPayment((int)$paymentId);

    $DB->commit();

    echo json_encode(['ok' => true, 'payment_id' => $paymentId, 'new_balance' => $newBalance]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Record payment error: " . $e->getMessage());
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}