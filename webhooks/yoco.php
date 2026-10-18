<?php
// /webhooks/yoco.php
// Public endpoint for Yoco webhook deliveries (Checkout payments). Every
// delivery must carry a valid signature made with the company's webhook
// secret; only a succeeded payment records money, exactly once per Yoco
// payment, through the same path as a payment captured by hand
// (QiActions::recordPayment: payment schedule, ledger posting, timeline).

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/yoco_signature.php';
require_once __DIR__ . '/../qi/lib/QiYoco.php';
require_once __DIR__ . '/../qi/lib/QiActions.php';

header('Content-Type: application/json');

function yoco_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$h = yoco_get_webhook_headers();
$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > 256 * 1024) yoco_reply(413, ['ok' => false]);
$payload = json_decode($rawBody, true);
if (!is_array($payload)) yoco_reply(200, ['ignored' => true]);
$ev = QiYoco::parseEvent($payload);

// Which invoice: our metadata first, then the checkout we stored on the invoice
$invoice = null;
if ($ev['invoice_id'] && $ev['company_id']) {
    $st = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
    $st->execute([$ev['invoice_id'], $ev['company_id']]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$invoice && $ev['checkout_id']) {
    $st = $DB->prepare("SELECT * FROM invoices WHERE yoco_checkout_id = ? OR yoco_reference = ? LIMIT 1");
    $st->execute([$ev['checkout_id'], $ev['checkout_id']]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$invoice) yoco_reply(200, ['ignored' => true]);
$companyId = (int)$invoice['company_id'];

// Signature — mandatory, verified with that company's secret
$st = $DB->prepare("SELECT yoco_webhook_secret FROM companies WHERE id = ?");
$st->execute([$companyId]);
$secret = trim((string)$st->fetchColumn());
if ($secret === '' || !yoco_verify_signature($secret, $h['id'], $h['timestamp'], $h['signature'], $rawBody)) {
    error_log('[yoco] rejected delivery for invoice #' . (int)$invoice['id'] . ($secret === '' ? ' (no webhook secret configured)' : ' (bad signature)'));
    yoco_reply(403, ['ok' => false, 'error' => 'Invalid signature']);
}

try {
    $DB->prepare("INSERT INTO yoco_payment_events (company_id, event_type, event_id, payload_json) VALUES (?, ?, ?, ?)")
       ->execute([$companyId, substr($ev['type'] ?: 'unknown', 0, 100), substr((string)($h['id'] ?? ''), 0, 128) ?: null, substr($rawBody, 0, 60000)]);
} catch (Throwable $e) {
    // the audit table is optional
}

// Only a successful payment settles anything
if ($ev['type'] !== 'payment.succeeded' && !($ev['type'] === '' && $ev['status'] === 'succeeded')) {
    yoco_reply(200, ['ignored' => true, 'reason' => 'event ' . ($ev['type'] ?: 'without a type')]);
}
if ($ev['currency'] !== 'ZAR' || !$ev['amount'] || $ev['amount'] <= 0) yoco_reply(200, ['ignored' => true, 'reason' => 'no ZAR amount']);
$paymentRef = $ev['payment_id'] ?: ($ev['checkout_id'] ?: (string)($h['id'] ?? ''));

$balance = (float)$invoice['balance_due'];
if ($balance <= 0.004) {
    error_log('[yoco] payment ' . $paymentRef . ' arrived for invoice ' . $invoice['invoice_number'] . ' which has nothing owing — record it as a refund/credit by hand');
    yoco_reply(200, ['ignored' => true, 'reason' => 'nothing owing']);
}

try {
    // the payment is recorded once: the idempotency key is the Yoco payment id
    $r = QiActions::recordPayment($DB, $companyId, null, (int)$invoice['id'], [
        'amount' => round(min($ev['amount'], $balance), 2), 'date' => date('Y-m-d'), 'method' => 'yoco',
        'reference' => $paymentRef, 'notes' => 'Card payment through Yoco' . ($ev['amount'] > $balance + 0.004 ? ' (paid ' . number_format($ev['amount'], 2) . ', more than the balance)' : ''),
        'idempotency_key' => 'yoco:' . $paymentRef,
    ], 'Customer · card');
    if (empty($r['duplicate'])) {
        try {
            $owner = (int)($invoice['created_by'] ?? 0);
            if ($owner > 0 && function_exists('fw_notify')) {
                fw_notify($DB, $companyId, $owner, 'qi_invoice_paid', 'Invoice ' . $invoice['invoice_number'] . ' paid by card',
                    'R ' . number_format(min($ev['amount'], $balance), 2) . ' received through Yoco' . ($r['status'] === 'paid' ? ' — paid in full.' : '.'),
                    '/qi/view.php?type=invoice&id=' . (int)$invoice['id']);
            }
        } catch (Throwable $e) {
            error_log('[yoco] notify: ' . $e->getMessage());
        }
    }
    yoco_reply(200, ['ok' => true, 'invoice_id' => (int)$invoice['id'], 'payment_id' => $r['payment_id'], 'duplicate' => !empty($r['duplicate'])]);
} catch (QiInvalid $e) {
    // not payable any more (voided, already settled): acknowledge, the money needs a person
    error_log('[yoco] payment ' . $paymentRef . ' for ' . $invoice['invoice_number'] . ' not recorded: ' . $e->getMessage());
    yoco_reply(200, ['ignored' => true, 'reason' => $e->getMessage()]);
} catch (Throwable $e) {
    // ledger or database trouble: 500 so Yoco retries the delivery
    error_log('[yoco] webhook error: ' . $e->getMessage());
    yoco_reply(500, ['ok' => false, 'error' => 'Internal error']);
}
