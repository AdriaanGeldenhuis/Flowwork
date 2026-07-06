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
    // Insert payment
    $stmt = $DB->prepare(
        "INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by) " .
        "VALUES (?, ?, ?, 'yoco', ?, ?, ?)"
    );
    $stmt->execute([$companyId, $paymentDate, $balance, $yocoRef, json_encode($payload), $receivedBy]);
    $paymentId = $DB->lastInsertId();
    // Allocate payment to invoice
    $stmt = $DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
    $stmt->execute([$paymentId, $invoiceId, $balance]);
    // Update invoice
    $stmt = $DB->prepare("UPDATE invoices SET balance_due = 0, status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$invoiceId]);
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
