<?php
/**
 * Receipts: receipts/api/suggest_vendor.php over HTTP with PHP's built-in
 * server and the test database. The supplier matched from the OCR vendor name
 * comes back with its compliance: required document types it has no document
 * for, or whose latest document has expired, are listed in compliance.missing;
 * optional types are ignored; a document without an expiry date never expires;
 * a newer document replaces an expired one.
 */
require __DIR__ . '/../lib/bootstrap.php';

// The server below must only ever reach the local test database.
if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
$C = TEST_COMPANY_ID;

// ---------------------------------------------------------------- fixtures
// Only this test's document types count as required while it runs.
$requiredBefore = $DB->query("SELECT id, required FROM crm_compliance_types WHERE company_id = $C")->fetchAll(PDO::FETCH_KEY_PAIR);
$DB->exec("UPDATE crm_compliance_types SET required = 0 WHERE company_id = $C");

$tag = strtoupper(bin2hex(random_bytes(3)));
$addType = function (string $name, int $required) use ($DB, $C, $tag): int {
    $DB->prepare("INSERT INTO crm_compliance_types (company_id, code, name, default_expiry_months, required) VALUES (?, ?, ?, 12, ?)")
       ->execute([$C, substr("T$tag" . strtoupper(substr($name, 0, 3)), 0, 32), "$name $tag", $required]);
    return (int)$DB->lastInsertId();
};
$tax   = $addType('Tax Clearance', 1);
$ins   = $addType('Liability Insurance', 1);
$coida = $addType('COIDA', 1);
$bee   = $addType('BEE Certificate', 0);

$addDoc = function (int $accountId, int $typeId, ?string $expiry) use ($DB, $C): void {
    $DB->prepare("INSERT INTO crm_compliance_docs (company_id, account_id, type_id, reference_no, expiry_date, status, created_at)
                  VALUES (?, ?, ?, 'REF', ?, 'valid', NOW())")->execute([$C, $accountId, $typeId, $expiry]);
};
$today = date('Y-m-d');
$future = date('Y-m-d', strtotime('+1 year'));
$past = date('Y-m-d', strtotime('-10 days'));

// Lacking: tax clearance valid, no insurance, COIDA expired, no (optional) BEE.
$lacking = make_supplier($DB, 'Lacking Supplier');
$addDoc($lacking, $tax, $future);
$addDoc($lacking, $coida, $past);

// Complete: tax clearance without an expiry date, insurance renewed after an
// expired one, COIDA expiring today (still valid), BEE expired (optional).
$complete = make_supplier($DB, 'Complete Supplier');
$addDoc($complete, $tax, null);
$addDoc($complete, $ins, $past);
$addDoc($complete, $ins, $future);
$addDoc($complete, $coida, $today);
$addDoc($complete, $bee, $past);

$accountName = fn(int $id) => (string)$DB->query("SELECT name FROM crm_accounts WHERE id = $id")->fetchColumn();
$addReceipt = function (string $vendorName) use ($DB, $C): int {
    $DB->prepare("INSERT INTO receipt_file (company_id, path, mime, size_bytes, uploaded_by, uploaded_at, ocr_status)
                  VALUES (?, 'uploads/receipts/test/suggest_vendor.pdf', 'application/pdf', 1, ?, NOW(), 'parsed')")->execute([$C, TEST_USER_ID]);
    $fileId = (int)$DB->lastInsertId();
    $DB->prepare("INSERT INTO receipt_ocr (file_id, vendor_name, created_at) VALUES (?, ?, NOW())")->execute([$fileId, $vendorName]);
    return $fileId;
};
$lackingFile = $addReceipt($accountName($lacking));
$completeFile = $addReceipt($accountName($complete));

register_shutdown_function(function () use ($DB, $C, $requiredBefore, $tax, $ins, $coida, $bee, $lacking, $complete, $lackingFile, $completeFile) {
    $types = implode(',', [$tax, $ins, $coida, $bee]);
    $accounts = implode(',', [$lacking, $complete]);
    $files = implode(',', [$lackingFile, $completeFile]);
    $DB->exec("DELETE FROM crm_compliance_docs WHERE company_id = $C AND account_id IN ($accounts)");
    $DB->exec("DELETE FROM crm_compliance_types WHERE company_id = $C AND id IN ($types)");
    $DB->exec("DELETE FROM receipt_ocr WHERE file_id IN ($files)");
    $DB->exec("DELETE FROM receipt_file WHERE file_id IN ($files)");
    $DB->exec("DELETE FROM crm_accounts WHERE company_id = $C AND id IN ($accounts)");
    $restore = $DB->prepare("UPDATE crm_compliance_types SET required = ? WHERE id = ? AND company_id = ?");
    foreach ($requiredBefore as $id => $required) $restore->execute([$required, $id, $C]);
});

// Sign in as user 1 (company 1 admin).
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Receipts-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");

// ---------------------------------------------------------------- server
$port = 17000 + random_int(0, 999);
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
$base = 'http://127.0.0.1:' . $port;

/** Minimal browser: keeps cookies, does not follow redirects. */
function http(string $method, string $url, array &$jar, $body = null, array $headers = []): array
{
    $h = $jar ? ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar))] : [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', substr($raw, 0, $hs), $m, PREG_SET_ORDER);
    foreach ($m as $c) {
        if ($c[2] === '' || $c[2] === 'deleted') unset($jar[$c[1]]); else $jar[$c[1]] = $c[2];
    }
    return ['code' => $code, 'body' => substr($raw, $hs)];
}

function csrf_from(string $html): string
{
    preg_match('/name="csrf(?:_token|-token)"\s+(?:value|content)="([a-f0-9]{64})"/', $html, $m);
    return $m[1] ?? '';
}

$jar = [];
$r = http('GET', "$base/login.php", $jar);
assert_eq(200, $r['code'], 'login page');
$csrf = csrf_from($r['body']);
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Receipts-test-1', 'csrf_token' => $csrf]),
          ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in (redirect)');

// The review page carries the token its script sends with the suggestion request.
$r = http('GET', "$base/receipts/review.php?id=$lackingFile", $jar);
assert_eq(200, $r['code'], 'receipt review page');
$csrf = csrf_from($r['body']);
assert_true($csrf !== '', 'review page has a CSRF token');

$suggest = function (int $fileId) use ($base, &$jar, $csrf): array {
    $r = http('POST', "$base/receipts/api/suggest_vendor.php", $jar, json_encode(['file_id' => $fileId]),
              ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    assert_eq(200, $r['code'], "suggest_vendor for file $fileId answers 200 (body: " . substr($r['body'], 0, 300) . ')');
    $json = json_decode($r['body'], true);
    assert_true(is_array($json), "suggest_vendor for file $fileId returns JSON (body: " . substr($r['body'], 0, 300) . ")");
    return $json;
};

// ---------------------------------------------------------------- tests
// A supplier missing a required document type, with another one expired.
$res = $suggest($lackingFile);
assert_true($res['ok'] === true, 'request ok');
assert_eq($lacking, $res['supplier']['id'] ?? null, 'supplier matched on the OCR vendor name');
assert_eq($accountName($lacking), $res['supplier']['name'] ?? null, 'supplier name');
assert_eq(0.9, $res['supplier']['confidence'] ?? null, 'exact-name confidence');
assert_eq([], $res['alternatives'] ?? null, 'no alternatives for an exact match');
assert_eq(['ok', 'missing'], array_keys($res['compliance'] ?? []), 'compliance keeps its {ok, missing} shape');
assert_true($res['compliance']['ok'] === false, 'not compliant');
assert_eq(["COIDA $tag (expired)", "Liability Insurance $tag"], $res['compliance']['missing'],
          'the missing required type and the expired one are reported; valid and optional types are not');

// A supplier with every required document current.
$res = $suggest($completeFile);
assert_eq($complete, $res['supplier']['id'] ?? null, 'second supplier matched');
assert_true($res['compliance']['ok'] === true, 'compliant: no expiry date, a renewal and an expiry today all count as current; optional BEE is ignored');
assert_eq([], $res['compliance']['missing'], 'nothing missing');

// With no required types, nothing can be missing.
$DB->exec("UPDATE crm_compliance_types SET required = 0 WHERE company_id = $C");
$res = $suggest($lackingFile);
assert_true($res['compliance']['ok'] === true && $res['compliance']['missing'] === [], 'no required types: compliant');

test_done('receipts_suggest_vendor_http');
