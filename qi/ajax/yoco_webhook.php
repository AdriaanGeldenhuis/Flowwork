<?php
// /qi/ajax/yoco_webhook.php
// Public webhook endpoint for Yoco payment events.
//
// This script accepts POST requests from Yoco containing event data in JSON
// format. It stores the raw payload in the yoco_payment_events table for
// auditing and attempts to reconcile completed payments to invoices based on
// the reference supplied when the payment link was created. If a payment
// succeeded and matches an invoice, a payment is recorded using method 'yoco'
// and the invoice status is updated accordingly. PostingService is invoked to
// create the appropriate GL entries. Any errors are logged but do not
// propagate to the response to avoid repeated webhook retries.

require_once __DIR__ . '/../../init.php';

// Yoco webhooks should not require authentication – this endpoint must be
// accessible publicly. Therefore, we do not include auth_gate.php.

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// Read the raw payload and decode JSON
$payload = file_get_contents('php://input');
$event   = json_decode($payload, true);

// Always log the event to yoco_payment_events for audit purposes
try {
    $companyId = null;
    $eventType = is_array($event) && isset($event['type']) ? $event['type'] : 'unknown';
    $eventId   = is_array($event) && isset($event['id']) ? (string)$event['id'] : null;

    // Attempt to extract company_id from event metadata if provided
    if (is_array($event) && isset($event['metadata']['company_id'])) {
        $companyId = (int)$event['metadata']['company_id'];
    }
    $stmt = $DB->prepare('INSERT INTO yoco_payment_events (company_id, event_type, event_id, payload_json) VALUES (?, ?, ?, ?)');
    $stmt->execute([$companyId, $eventType, $eventId, $payload]);
} catch (Exception $e) {
    error_log('Yoco webhook logging failed: ' . $e->getMessage());
    // Do not return error to Yoco; still proceed
}

// If the event payload is not valid JSON, stop here
if (!is_array($event)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// Extract reference from the event data. Yoco typically returns the reference
// used when creating the payment link in either `reference` or nested in
// `metadata.reference`. This value should correspond to the invoice's
// yoco_reference.
$reference = null;
if (isset($event['reference']) && $event['reference']) {
    $reference = $event['reference'];
} elseif (isset($event['metadata']['reference']) && $event['metadata']['reference']) {
    $reference = $event['metadata']['reference'];
} elseif (isset($event['data']['reference']) && $event['data']['reference']) {
    $reference = $event['data']['reference'];
} elseif (isset($event['data']['metadata']['reference']) && $event['data']['metadata']['reference']) {
    $reference = $event['data']['metadata']['reference'];
}

// Determine the payment status. We treat statuses containing 'paid',
// 'successful' or 'completed' as successful payments.
$status = null;
if (isset($event['status'])) {
    $status = strtolower((string)$event['status']);
} elseif (isset($event['data']['status'])) {
    $status = strtolower((string)$event['data']['status']);
}

// Only attempt to record payment if we have a reference and the status
// indicates success
if ($reference && $status && preg_match('/paid|success|complete/', $status)) {
    try {
        // Find the invoice by reference
        $stmt = $DB->prepare('SELECT id, company_id, balance_due, status FROM invoices WHERE yoco_reference = ?');
        $stmt->execute([$reference]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice && $invoice['status'] !== 'paid') {
            // Use company_id from invoice
            $invCompanyId = (int)$invoice['company_id'];
            $invoiceId    = (int)$invoice['id'];
            $balanceDue   = (float)$invoice['balance_due'];

            // We'll record the full outstanding balance as paid. If event
            // includes an amount, use the minimum of event amount and balance
            $amountPaid = $balanceDue;
            if (isset($event['amount'])) {
                $amountPaid = min($balanceDue, (float)$event['amount'] / 100);
            } elseif (isset($event['data']['amount'])) {
                $amountPaid = min($balanceDue, (float)$event['data']['amount'] / 100);
            }
            // If amountPaid is zero or negative, skip
            if ($amountPaid > 0.0) {
                $DB->beginTransaction();
                // Insert payment
                $stmt = $DB->prepare('INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $invCompanyId,
                    date('Y-m-d'),
                    $amountPaid,
                    'yoco',
                    $reference,
                    'Yoco payment',
                    null // received_by: null for system
                ]);
                $paymentId = $DB->lastInsertId();
                // Allocate payment to invoice
                $stmt = $DB->prepare('INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)');
                $stmt->execute([$paymentId, $invoiceId, $amountPaid]);
                // Update invoice
                $newBalance = $balanceDue - $amountPaid;
                $newStatus  = ($newBalance <= 0.001) ? 'paid' : 'part-paid';
                if ($newBalance < 0) $newBalance = 0;
                $stmt = $DB->prepare('UPDATE invoices SET balance_due = ?, status = ?, paid_at = CASE WHEN ? = "paid" THEN NOW() ELSE paid_at END, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$newBalance, $newStatus, $newStatus, $invoiceId]);
                // Audit log
                $stmt = $DB->prepare('INSERT INTO audit_log (company_id, user_id, action, details, ip) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $invCompanyId,
                    0,
                    'yoco_payment',
                    json_encode(['invoice_id' => $invoiceId, 'amount' => $amountPaid, 'payment_id' => $paymentId]),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                $DB->commit();
                // Post payment to finance
                try {
                    require_once __DIR__ . '/../../finances/lib/PostingService.php';
                    $posting = new PostingService($DB, $invCompanyId, 0);
                    $posting->postCustomerPayment((int)$paymentId);
                } catch (Exception $e) {
                    error_log('Yoco payment journal posting failed: ' . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        // Log but do not interrupt webhook response
        error_log('Yoco webhook payment processing error: ' . $e->getMessage());
        if ($DB->inTransaction()) {
            $DB->rollBack();
        }
    }
}

// Always respond with 200 OK. Yoco retries on non-2xx responses.
http_response_code(200);
echo 'OK';
exit;