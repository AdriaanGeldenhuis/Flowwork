<?php
/**
 * Receipts over HTTP (PHP's built-in server, the test database): the Maison
 * pages and their boot data, old links, CSRF on every POST, sending a slip
 * in (multipart, duplicates, content checks), reading it, serving it only to
 * whoever may see it, the bill API (load, supplier compliance, check, save,
 * post), the list and its CSV, who may do what (members see their own,
 * viewers cannot send in, settings for admins and bookkeepers), and the old
 * endpoints answering 410 Gone.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_helpers.php';

if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
$C = TEST_COMPANY_ID;
FinSchema::ensure($DB);
rc_fresh_schema($DB);
RcKit::saveSettings($DB, $C, ['require_po_over_amount' => 0, 'require_vat_number' => 1, 'block_expired_compliance' => 1, 'ocr_provider' => 'tesseract']);
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Receipts-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
$roleBefore = $DB->query("SELECT role FROM user_companies WHERE user_id = 1 AND company_id = $C")->fetchColumn();
$requiredBefore = $DB->query("SELECT id, required FROM crm_compliance_types WHERE company_id = $C")->fetchAll(PDO::FETCH_KEY_PAIR);
$DB->exec("UPDATE crm_compliance_types SET required = 0 WHERE company_id = $C");
$setRole = function (string $role) use ($DB, $C) {
    $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = 1 AND company_id = ?")->execute([$role, $C]);
    $DB->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role]);
};
$tag = strtoupper(bin2hex(random_bytes(3)));
$vatNo = '4' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$DB->prepare("INSERT INTO crm_accounts (company_id, type, name, vat_no, status, created_by, created_at) VALUES (?, 'supplier', ?, ?, 'active', 1, NOW())")->execute([$C, "Http Hardware $tag", $vatNo]);
$supplierId = (int)$DB->lastInsertId();
register_shutdown_function(function () use ($setRole, $roleBefore, $DB, $C, $supplierId, $requiredBefore) {
    $setRole($roleBefore ?: 'admin');
    $DB->exec("DELETE FROM crm_accounts WHERE id = $supplierId");
    $restore = $DB->prepare("UPDATE crm_compliance_types SET required = ? WHERE id = ? AND company_id = ?");
    foreach ($requiredBefore as $id => $req) $restore->execute([$req, $id, $C]);
});
foreach (glob(FLOWWORK_ROOT . '/storage/receipts/rl-*.json') ?: [] as $f) @unlink($f);

$port = 18000 + random_int(0, 999);
$env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS')]);
$log = tempnam(sys_get_temp_dir(), 'fwrc');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', FLOWWORK_ROOT], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, FLOWWORK_ROOT, $env);
register_shutdown_function(function () use ($server, $log) { proc_terminate($server); @unlink($log); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = 'http://127.0.0.1:' . $port;

function http(string $method, string $url, array &$jar, $body = null, array $headers = []): array
{
    $h = $jar ? ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar))] : [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '',
                            CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 60]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $head, $m, PREG_SET_ORDER);
    foreach ($m as $c) { if ($c[2] === '' || $c[2] === 'deleted') unset($jar[$c[1]]); else $jar[$c[1]] = $c[2]; }
    return ['code' => $code, 'head' => $head, 'body' => substr($raw, $hs), 'json' => json_decode(substr($raw, $hs), true)];
}
function csrf_from(string $html): string
{
    preg_match('/name="csrf(?:_token|-token)"\s+(?:value|content)="([a-f0-9]{64})"/', $html, $m);
    return $m[1] ?? '';
}

$setRole('admin');
$jar = [];
$r = http('GET', "$base/login.php", $jar);
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Receipts-test-1', 'csrf_token' => csrf_from($r['body'])]), ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in');

// ---------------------------------------------------------------- pages
foreach (['', 'inbox.php', 'inbox.php?view=done', 'upload.php', 'settings.php'] as $p) {
    $page = http('GET', "$base/receipts/$p", $jar);
    assert_eq(200, $page['code'], "/receipts/$p opens");
    assert_true(str_contains($page['body'], 'class="fwm fwr"') && str_contains($page['body'], 'id="rcBoot"') && str_contains($page['body'], '/mail/css/mail.css'), "/receipts/$p is a Maison page with its boot data");
}
$csrf = csrf_from($page['body']);
assert_true($csrf !== '', 'pages carry a CSRF token');
$r = http('GET', "$base/receipts/index.php?tab=approved", $jar);
assert_true($r['code'] === 302 && str_contains($r['head'], 'inbox.php?view=done'), 'an old ?tab= link goes to the matching view');
assert_eq(404, http('GET', "$base/receipts/review.php?id=99999999", $jar)['code'], 'an unknown receipt is a friendly 404');
$api = fn(string $path, array $body, bool $token = true) => http('POST', "$base/receipts/api/$path", $jar, json_encode($body), array_merge(['Content-Type: application/json', 'Accept: application/json'], $token ? ['X-CSRF-Token: ' . $csrf] : []));
$get = fn(string $path) => http('GET', "$base/receipts/api/$path", $jar, null, ['Accept: application/json']);

// ---------------------------------------------------------------- old endpoints
foreach (['api/save_bill.php', 'api/post_to_gl.php', 'api/upload_start.php', 'api/bank_link_rule.php', 'ajax/bill_approve_post.php', 'ajax/list_inbox.php'] as $old) {
    $r = http('POST', "$base/receipts/$old", $jar, '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    assert_eq(410, $r['code'], "the old $old answers 410 Gone");
}

// ---------------------------------------------------------------- send a slip in
$upload = function (string $path, string $name, bool $token = true) use ($base, &$jar, &$csrf) {
    return http('POST', "$base/receipts/api/upload.php", $jar, ['file' => new CURLFile($path, 'application/pdf', $name), 'note' => 'for the Smith job'],
                array_merge(['Accept: application/json'], $token ? ['X-CSRF-Token: ' . $csrf] : []));
};
$pdf = rc_pdf(tempnam(sys_get_temp_dir(), 'rchttp') . '.pdf', ["HTTP HARDWARE $tag", "VAT No $vatNo", "Tax Invoice H-$tag", 'Date 14/10/2026', 'TOTAL 230.00', 'VAT 15% 30.00']);
assert_eq(419, $upload($pdf, 'slip.pdf', false)['code'], 'an upload without the CSRF token is refused');
$r = $upload($pdf, 'slip.pdf');
assert_eq(200, $r['code'], 'a PDF is sent in (body: ' . substr($r['body'], 0, 200) . ')');
$id = (int)$r['json']['files'][0]['file_id'];
assert_true($id > 0 && $r['json']['files'][0]['duplicate'] === false, 'a new receipt');
assert_eq('for the Smith job', $DB->query("SELECT note FROM receipt_file WHERE file_id = $id")->fetchColumn(), 'with its note');
$r = $upload($pdf, 'again.pdf');
assert_true($r['json']['files'][0]['duplicate'] === true && (int)$r['json']['files'][0]['file_id'] === $id, 'the same slip again is a duplicate of the first');
$fake = tempnam(sys_get_temp_dir(), 'rcfake');
file_put_contents($fake, 'not a pdf at all');
$r = $upload($fake, 'invoice.pdf');
assert_eq(422, $r['code'], 'a file that is not a PDF or photo is refused');
assert_true(str_contains((string)$r['json']['error'], 'not a PDF or a photo'), 'with a reason');

// ---------------------------------------------------------------- read it, see it
$r = $api('read.php', ['id' => $id]);
assert_eq(200, $r['code'], 'read');
if (RcReader::canRun('pdftotext')) {
    assert_true($r['json']['read']['ok'] === true && $r['json']['read']['engine'] === 'pdf-text', 'read from the PDF text');
    assert_cents(230, $r['json']['read']['total'], 'the total was read');
}
$f = http('GET', "$base/receipts/api/file.php?id=$id", $jar);
assert_eq(200, $f['code'], 'the slip is served');
assert_true(stripos($f['head'], 'Content-Type: application/pdf') !== false && stripos($f['head'], 'X-Content-Type-Options: nosniff') !== false && str_starts_with($f['body'], '%PDF'), 'as a PDF, not sniffed');
assert_true(stripos($f['head'], 'Content-Disposition: inline') !== false, 'inline');
assert_true(stripos(http('GET', "$base/receipts/api/file.php?id=$id&download=1", $jar)['head'], 'Content-Disposition: attachment') !== false, 'or as a download');
assert_true(is_file(FLOWWORK_ROOT . '/uploads/receipts/.htaccess') && str_contains((string)file_get_contents(FLOWWORK_ROOT . '/uploads/receipts/.htaccess'), 'Require all denied'), 'old slips in uploads/receipts are closed to the web');

// ---------------------------------------------------------------- the bill
$r = $get("bill.php?op=load&id=$id");
assert_eq(200, $r['code'], 'the review loads');
$L = $r['json'];
assert_true(isset($L['form'], $L['checks'], $L['lookups']['accounts'], $L['lookups']['rates'], $L['queue']) && $L['editable'] === true, 'form, checks, lookups and the queue');
if (RcReader::canRun('pdftotext')) assert_eq($supplierId, $L['form']['supplier_id'], 'the supplier from the VAT number');
$form = $L['form'];
$form['supplier_id'] = $supplierId;
$form['number'] = "H-$tag";
$form['issued'] = '2026-10-14';
$form['incl'] = true;
$form['lines'] = [['description' => 'Cement', 'qty' => 2, 'price' => '115.00', 'rate' => 15, 'account_id' => $form['lines'][0]['account_id'] ?? null]];
$s = $get("bill.php?op=supplier&id=$supplierId");
assert_eq(['ok', 'missing'], array_keys($s['json']['supplier']['compliance']), 'supplier compliance keeps its {ok, missing} shape');
assert_true($s['json']['supplier']['vat_no'] === $vatNo && array_key_exists('learned', $s['json']['supplier']), 'the supplier with what was learned');
$s = $get('bill.php?op=suppliers&q=' . urlencode("Http Hardware $tag"));
assert_eq($supplierId, $s['json']['suppliers'][0]['id'] ?? null, 'suppliers are searched');
$r = $api('bill.php', ['op' => 'check', 'id' => $id, 'form' => $form]);
assert_true($r['code'] === 200 && $r['json']['blocked'] === false && $r['json']['totals']['total'] === 23000, 'checked: nothing holds it; R230.00');
assert_eq(419, $api('bill.php', ['op' => 'save', 'id' => $id, 'form' => $form], false)['code'], 'saving without the CSRF token is refused');
$r = $api('bill.php', ['op' => 'save', 'id' => $id, 'form' => $form]);
assert_eq(200, $r['code'], 'saved (body: ' . substr($r['body'], 0, 300) . ')');
$billId = (int)$r['json']['saved']['bill_id'];
assert_eq('draft', $r['json']['review']['receipt']['state'], 'a draft bill');
$r = $api('bill.php', ['op' => 'post', 'id' => $id, 'form' => $form]);
assert_eq(200, $r['code'], 'posted (body: ' . substr($r['body'], 0, 300) . ')');
assert_true($r['json']['posted']['journal_id'] > 0 && $r['json']['review']['receipt']['state'] === 'posted', 'in the books, with a journal');
assert_eq('posted', $DB->query("SELECT status FROM ap_bills WHERE id = $billId")->fetchColumn(), 'Finance has it posted');
$r = $api('receipts.php', ['op' => 'delete', 'ids' => [$id]]);
assert_eq(409, $r['code'], 'a slip behind a posted bill cannot be deleted');

// ---------------------------------------------------------------- the list and its CSV
$r = $get('receipts.php?op=list&view=done&q=' . urlencode("H-$tag"));
assert_true($r['code'] === 200 && ($r['json']['rows'][0]['id'] ?? 0) === $id && $r['json']['rows'][0]['amount'] == 230, 'found by its number under In the books');
$csv = http('GET', "$base/receipts/api/receipts.php?op=export&view=all&q=" . urlencode("H-$tag"), $jar);
assert_true($csv['code'] === 200 && stripos($csv['head'], 'text/csv') !== false && str_starts_with($csv['body'], "\xEF\xBB\xBF\"Receipt\""), 'the CSV export');

// ---------------------------------------------------------------- who may do what
$other = (int)$DB->query("SELECT u.id FROM users u JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = $C WHERE u.id <> 1 ORDER BY u.id LIMIT 1")->fetchColumn();
$DB->prepare("UPDATE receipt_file SET uploaded_by = ? WHERE file_id = ?")->execute([$other, $id]);   // someone else sent this one in
$setRole('member');
assert_eq(302, http('GET', "$base/receipts/settings.php", $jar)['code'], 'members are sent away from settings');
assert_eq(403, $get('settings.php')['code'], 'and the settings API refuses them');
assert_eq(404, http('GET', "$base/receipts/api/file.php?id=$id", $jar)['code'], 'a member cannot fetch someone else\'s slip');
$own = rc_pdf(tempnam(sys_get_temp_dir(), 'rcown') . '.pdf', ["MEMBER SLIP $tag", 'TOTAL 12.00']);
$r = $upload($own, 'mine.pdf');
assert_eq(200, $r['code'], 'a member sends a slip in');
$mine = (int)$r['json']['files'][0]['file_id'];
$r = $get('receipts.php?op=list&view=all');
assert_true(!in_array($id, array_column($r['json']['rows'], 'id'), true), 'a member\'s list leaves out others\' receipts');
assert_eq(403, $api('bill.php', ['op' => 'save', 'id' => $mine, 'form' => $form])['code'], 'a member cannot make a bill');
$r = $get("bill.php?op=load&id=$mine");
assert_true($r['code'] === 200 && $r['json']['lookups']['banks'] === [] && $r['json']['lookups']['boards'] === [] && $r['json']['checks']['checks'] === []
            && count($r['json']['lookups']['accounts']) <= count($r['json']['form']['lines']), 'a member follows their slip, not Finance: no banks, chart or check results');
assert_eq(403, $get("bill.php?op=supplier&id=$supplierId")['code'], 'nor a supplier\'s spend and orders');
assert_eq(403, $api('bill.php', ['op' => 'check', 'id' => $mine, 'form' => $form])['code'], 'nor the checks on a bill they typed');
assert_eq(200, $api('receipts.php', ['op' => 'note', 'id' => $mine, 'note' => 'coffee'])['code'], 'a member adds a note to their slip');
assert_eq(200, $api('receipts.php', ['op' => 'delete', 'ids' => [$mine]])['code'], 'and deletes it before a bill is made');
$setRole('viewer');
assert_eq(403, $upload($own, 'viewer.pdf')['code'], 'viewers cannot send slips in');
assert_eq(200, http('GET', "$base/receipts/api/file.php?id=$id", $jar)['code'], 'viewers see the company\'s slips');
$setRole('bookkeeper');
$r = $api('settings.php', ['op' => 'save', 'ocr_provider' => 'manual']);
assert_true($r['code'] === 422 && isset($r['json']['errors']['ocr_provider']), 'only an admin chooses how slips are read');
$r = $api('settings.php', ['op' => 'save', 'publish_to_drive' => '0']);
assert_true($r['code'] === 422 && isset($r['json']['errors']['publish_to_drive']), 'only an admin decides what goes to FlowWork Drive');
$r = $api('settings.php', ['op' => 'save', 'default_due_days' => '14', 'max_file_size_mb' => '99']);
assert_true($r['code'] === 422 && isset($r['json']['errors']['max_file_size_mb']), 'values are checked');
$r = $api('settings.php', ['op' => 'save', 'default_due_days' => '14']);
assert_true($r['code'] === 200 && $r['json']['settings']['default_due_days'] === 14, 'a bookkeeper saves the payment terms');
$r = $api('settings.php', ['op' => 'save', 'require_po_over_amount' => '10,000']);
assert_true($r['code'] === 200 && (float)$r['json']['settings']['require_po_over_amount'] === 10000.0, '"10,000" is ten thousand rand, not ten');
$api('settings.php', ['op' => 'save', 'require_po_over_amount' => '0']);
$setRole('admin');

test_done('receipts_http');
