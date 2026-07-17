<?php
// /qi/webhooks/yoco.php
// Public endpoint to handle Yoco webhook notifications for successful payments.
// Validates the signature using the company's webhook secret, then records the payment.

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/yoco_signature.php';

// Webhook does not require authentication but we need direct DB access

// Capture headers
$headers = getallheaders();
$webhookId       = $headers['webhook-id']       ?? null;
$webhookTimestamp= $headers['webhook-timestamp']?? null;
$signatureHeader = $headers['webhook-signature']?? null;

// Read raw body
$rawBody = file_get_contents('php://input');

// Attempt to decode JSON payload early to identify invoice reference
$payload = json_decode($rawBody, true);

// Extract Yoco reference from payload (try multiple paths)
$yocoRef = null;
if (is_array($payload)) {
    // Top level id
    if (!empty($payload['id'])) {
        $yocoRef = $payload['id'];
    } elseif (isset($payload['data']['id'])) {
        $yocoRef = $payload['data']['id'];
    } elseif (isset($payload['data']['order']['id'])) {
        $yocoRef = $payload['data']['order']['id'];
    }
}

if (!$yocoRef) {
    // Nothing to do if we cannot identify reference
    http_response_code(200);
    echo json_encode(['ignored' => true]);
    exit;
}

try {
    // Find the invoice by yoco_reference
    $stmt = $DB->prepare("SELECT * FROM invoices WHERE yoco_reference = ?");
    $stmt->execute([$yocoRef]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        http_response_code(200);
        echo json_encode(['ignored' => true]);
        exit;
    }
    $companyId = $invoice['company_id'];
    // Fetch company to get webhook secret
    $stmt = $DB->prepare("SELECT yoco_webhook_secret FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    $secret  = $company['yoco_webhook_secret'] ?? '';
    if (empty($secret)) {
        throw new Exception('Webhook secret not configured');
    }
    // Verify signature — MANDATORY. A request without valid signature headers
    // (or with a stale timestamp) is rejected; we never fall through to
    // recording a payment on an unsigned request.
    if (!yoco_verify_signature($secret, $webhookId, $webhookTimestamp, $signatureHeader, $rawBody)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
        exit;
    }
    // Only a SUCCESSFUL payment event settles the invoice. Previously ANY signed
    // event matching the reference marked it fully paid — a payment.failed,
    // refund or cancellation would have settled it. Reject any typed non-success
    // event (empty type is treated as legacy-success for older deliveries).
    $eventType = strtolower(is_array($payload) ? (string)($payload['type'] ?? '') : '');
    if ($eventType !== '' && strpos($eventType, 'succeeded') === false) {
        http_response_code(200);
        echo json_encode(['ignored' => true, 'reason' => 'non-success event: ' . $eventType]);
        exit;
    }
    // If invoice already paid, ignore
    if ($invoice['status'] === 'paid' || (float)$invoice['balance_due'] <= 0) {
        http_response_code(200);
        echo json_encode(['ignored' => true]);
        exit;
    }
    // Idempotency: Yoco retries deliveries (and a prior bug made every
    // delivery return 500 after committing, so retries were guaranteed).
    // The same checkout reference must never create a second payment.
    if (!empty($yocoRef)) {
        $stmt = $DB->prepare("SELECT id FROM payments WHERE company_id = ? AND method = 'yoco' AND reference = ? LIMIT 1");
        $stmt->execute([$companyId, $yocoRef]);
        if ($stmt->fetchColumn()) {
            http_response_code(200);
            echo json_encode(['ignored' => true, 'reason' => 'duplicate delivery']);
            exit;
        }
    }
    // Begin payment transaction
    $DB->beginTransaction();
    $invoiceId = $invoice['id'];
    $balance   = (float)$invoice['balance_due'];
    // Record the amount ACTUALLY paid (Yoco amounts are in cents), capped at the
    // outstanding balance — never assume the full balance was settled. Fall back
    // to the balance only when the payload carries no amount (the "Pay Now" link
    // is created for the full balance).
    $paidAmount = null;
    foreach ([$payload['amount'] ?? null, $payload['data']['amount'] ?? null,
              $payload['payload']['amount'] ?? null, $payload['data']['order']['amount'] ?? null] as $cand) {
        if (is_numeric($cand)) { $paidAmount = (float)$cand / 100.0; break; }
    }
    $amountToRecord = ($paidAmount !== null && $paidAmount > 0) ? min($paidAmount, $balance) : $balance;
    if ($balance <= 0) {
        // Nothing to allocate
        $DB->commit();
        http_response_code(200);
        echo json_encode(['ignored' => true]);
        exit;
    }
    // Determine payment date as now
    $paymentDate = date('Y-m-d');
    // Determine a user to assign the payment (first user in company)
    $stmt = $DB->prepare("SELECT id FROM users WHERE company_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$companyId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $receivedBy = $userRow['id'] ?? 1;
    // Insert payment. The idempotency_key makes uq_payments_idem
    // (company_id, idempotency_key) the DB-level backstop for CONCURRENT
    // duplicate deliveries — the SELECT-based dedupe above only catches
    // sequential retries.
    $stmt = $DB->prepare(
        "INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by, idempotency_key) " .
        "VALUES (?, ?, ?, 'yoco', ?, ?, ?, ?)"
    );
    try {
        $stmt->execute([$companyId, $paymentDate, $amountToRecord, $yocoRef, json_encode($payload), $receivedBy,
            substr('yoco:' . $yocoRef, 0, 64)]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062 || $e->getCode() == '23000') {
            $DB->rollBack();
            http_response_code(200);
            echo json_encode(['ignored' => true, 'reason' => 'duplicate delivery']);
            exit;
        }
        throw $e;
    }
    $paymentId = $DB->lastInsertId();
    // Allocate payment to invoice
    $stmt = $DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
    $stmt->execute([$paymentId, $invoiceId, $amountToRecord]);
    // Update invoice: fully paid only when the amount clears the balance,
    // otherwise part-paid (a partial Yoco payment must not mark it 'paid').
    $newBalance = round($balance - $amountToRecord, 2);
    if ($newBalance <= 0.005) {
        $stmt = $DB->prepare("UPDATE invoices SET balance_due = 0, status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$invoiceId]);
    } else {
        $stmt = $DB->prepare("UPDATE invoices SET balance_due = ?, status = 'part-paid', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newBalance, $invoiceId]);
    }
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp) " .
        "VALUES (?, ?, 'yoco_payment_received', 'invoice', ?, ?, ?, NOW())"
    );
    $stmt->execute([$companyId, $receivedBy, $invoiceId, json_encode(['payment_id' => $paymentId, 'yoco_ref' => $yocoRef]), $_SERVER['REMOTE_ADDR'] ?? null]);

    // Post to the GL inside the same transaction. (The previous code
    // required a non-existent path — ../services/JournalPoster.php resolves
    // outside qi/ — so every webhook fatalled AFTER committing the payment:
    // no journal, HTTP 500, and a Yoco retry that could duplicate it.)
    require_once __DIR__ . '/../finances/lib/PostingService.php';
    require_once __DIR__ . '/../qi/lib/InvoiceLifecycle.php';
    if ($invoice['status'] === 'draft') {
        InvoiceLifecycle::issueInvoice($DB, $companyId, $receivedBy, (int)$invoiceId);
    }
    $posting = new PostingService($DB, $companyId, $receivedBy);
    $posting->postCustomerPayment((int)$paymentId);

    $DB->commit();

    // Online payment applied — refresh the invoice PDF and its FlowWork Drive
    // copy so the payment history / balance shown there stays current.
    try {
        require_once __DIR__ . '/../qi/services/DocumentPdfService.php';
        DocumentPdfService::generateAndFile($DB, (int)$companyId, 'invoice', (int)$invoiceId);
    } catch (Throwable $pdfEx) {
        error_log('Yoco webhook PDF publish failed: ' . $pdfEx->getMessage());
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'invoice_id' => $invoiceId, 'payment_id' => $paymentId]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Yoco webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
