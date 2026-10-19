<?php
/**
 * Card payments: the Yoco webhook (webhooks/yoco.php) over HTTP with PHP's
 * built-in server and the test database. A signed payment.succeeded records
 * the payment once (ledger, schedule, timeline); a replayed delivery records
 * nothing; a bad or stale signature is refused; other events are ignored; a
 * payment larger than the balance settles only what is owing.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiActions.php';

// The server below must only ever reach the local test database.
if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;

// ---------------------------------------------------------------- fixtures
$secret = 'whsec_' . base64_encode(random_bytes(24));
$oldSecret = $DB->query("SELECT yoco_webhook_secret FROM companies WHERE id = $C")->fetchColumn();
$DB->prepare("UPDATE companies SET yoco_webhook_secret = ? WHERE id = ?")->execute([$secret, $C]);
register_shutdown_function(function () use ($DB, $C, $oldSecret) {
    $DB->prepare("UPDATE companies SET yoco_webhook_secret = ? WHERE id = ?")->execute([$oldSecret === false ? null : $oldSecret, $C]);
});

$cust = make_customer($DB, 'Card Customer');
$DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, city, postal_code, country, created_at)
              VALUES (?, ?, 'billing', '1 Bree Street', 'Cape Town', '8001', 'ZA', NOW())")->execute([$C, $cust]);
$inv = QiWriter::save($DB, $C, TEST_USER_ID, 'invoice', ['customer_id' => $cust, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+14 days')),
    'rows' => [['kind' => 'item', 'description' => 'Annual service', 'qty' => 1, 'price' => 2000, 'tax_code' => 'STD']]]);
$invId = (int)$inv['id'];
QiActions::issueInvoice($DB, $C, TEST_USER_ID, $invId);   // R2 300 owing

// ---------------------------------------------------------------- server
$port = 19000 + random_int(0, 999);
$env = array_merge(getenv(), [
    'APP_ENV' => 'testing',
    'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS'),
]);
$log = tempnam(sys_get_temp_dir(), 'fwsrv');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', FLOWWORK_ROOT], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
                    $pipes, FLOWWORK_ROOT, $env);
register_shutdown_function(function () use ($server, $log) {
    proc_terminate($server);
    @unlink($log);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$url = 'http://127.0.0.1:' . $port . '/webhooks/yoco.php';

function yoco_body(int $invoiceId, int $cents, string $paymentId, string $type = 'payment.succeeded'): string
{
    return json_encode(['id' => 'evt_' . bin2hex(random_bytes(6)), 'type' => $type, 'createdDate' => date('c'),
        'payload' => ['id' => $paymentId, 'type' => 'payment', 'amount' => $cents, 'currency' => 'ZAR', 'status' => $type === 'payment.succeeded' ? 'succeeded' : 'failed',
                      'mode' => 'test', 'metadata' => ['checkoutId' => 'ch_test_1', 'invoiceId' => (string)$invoiceId, 'companyId' => (string)TEST_COMPANY_ID]]]);
}

function yoco_post(string $url, string $body, ?string $secret, ?int $ts = null): array
{
    $id = 'msg_' . bin2hex(random_bytes(5));
    $ts = (string)($ts ?? time());
    $headers = ['Content-Type: application/json'];
    if ($secret !== null) {
        $sig = base64_encode(hash_hmac('sha256', "$id.$ts.$body", base64_decode(explode('_', $secret, 2)[1]), true));
        $headers = array_merge($headers, ["Webhook-Id: $id", "Webhook-Timestamp: $ts", "Webhook-Signature: v1,$sig"]);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                            CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => $headers]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($resp, true) ?: [], 'body' => $resp];
}
$yocoPayments = fn() => (int)$DB->query("SELECT COUNT(*) FROM payments p JOIN payment_allocations pa ON pa.payment_id = p.id
                                          WHERE pa.invoice_id = $invId AND p.method = 'yoco'")->fetchColumn();
$invoice = fn() => $DB->query("SELECT status, balance_due FROM invoices WHERE id = $invId")->fetch(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------- tests
$r = yoco_post($url, yoco_body($invId, 92000, 'p_test_a'), null);
assert_eq(403, $r['code'], 'unsigned delivery refused');
$r = yoco_post($url, yoco_body($invId, 92000, 'p_test_a'), 'whsec_' . base64_encode(random_bytes(24)));
assert_eq(403, $r['code'], 'wrong secret refused');
$r = yoco_post($url, yoco_body($invId, 92000, 'p_test_a'), $secret, time() - 3600);
assert_eq(403, $r['code'], 'stale timestamp refused (replay protection)');
assert_eq(0, $yocoPayments(), 'nothing recorded for refused deliveries');

$body = yoco_body($invId, 92000, 'p_test_a');           // R920 = 40%
$r = yoco_post($url, $body, $secret);
assert_eq(200, $r['code'], 'signed delivery accepted: ' . $r['body']);
assert_true(!empty($r['json']['ok']) && empty($r['json']['duplicate']), 'payment recorded');
assert_eq(1, $yocoPayments(), 'one card payment');
assert_eq(['status' => 'part-paid', 'balance_due' => '1380.00'], $invoice(), 'invoice part paid');
$p = $DB->query("SELECT p.* FROM payments p JOIN payment_allocations pa ON pa.payment_id = p.id WHERE pa.invoice_id = $invId")->fetch(PDO::FETCH_ASSOC);
assert_eq('yoco:p_test_a', $p['idempotency_key'], 'keyed on the Yoco payment id');
assert_true((int)$p['journal_id'] > 0, 'card receipt posted to the ledger');

$r = yoco_post($url, $body, $secret);                     // Yoco retries the same event
assert_eq(200, $r['code'], 'redelivery acknowledged');
assert_true(!empty($r['json']['duplicate']), 'redelivery recognised as a duplicate');
assert_eq(1, $yocoPayments(), 'redelivery records nothing');

$r = yoco_post($url, yoco_body($invId, 50000, 'p_test_f', 'payment.failed'), $secret);
assert_eq(200, $r['code'], 'failed payment acknowledged');
assert_true(!empty($r['json']['ignored']), 'failed payment ignored');

$r = yoco_post($url, yoco_body($invId, 500000, 'p_test_b'), $secret);   // R5 000 against R1 380 owing
assert_eq(200, $r['code'], 'overpayment accepted');
assert_eq(['status' => 'paid', 'balance_due' => '0.00'], $invoice(), 'only the balance is settled');
$notes = (string)$DB->query("SELECT notes FROM payments WHERE idempotency_key = 'yoco:p_test_b'")->fetchColumn();
assert_true(str_contains($notes, 'more than the balance'), 'the overpayment is noted for a person to handle');

$r = yoco_post($url, yoco_body($invId, 1000, 'p_test_c'), $secret);
assert_true(!empty($r['json']['ignored']), 'a payment on a settled invoice is not recorded');
$r = yoco_post($url, yoco_body(999999, 1000, 'p_test_d'), $secret);
assert_eq(200, $r['code'], 'unknown invoice acknowledged');
assert_true(!empty($r['json']['ignored']), 'unknown invoice ignored');

$acts = $DB->query("SELECT COUNT(*) FROM qi_activity WHERE doc_type = 'invoice' AND doc_id = $invId AND action = 'payment'")->fetchColumn();
assert_eq(2, (int)$acts, 'both card payments on the timeline');

test_done('qi_yoco_webhook_http');
