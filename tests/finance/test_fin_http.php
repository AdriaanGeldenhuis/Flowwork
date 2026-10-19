<?php
/**
 * Finance rebuild over HTTP (PHP's built-in server, the test database):
 * the page and its boot data, CSRF on every POST, roles enforced by the API
 * (a viewer reads but cannot write), a part switched off answers 404, safe
 * uploads (type checked by content, served with a sandbox CSP), rate limits,
 * and the old addresses: pages redirect, endpoints answer 410 Gone.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';

if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
fin_setup($DB);
$C = TEST_COMPANY_ID;
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Finance-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
$roleBefore = $DB->query("SELECT role FROM user_companies WHERE user_id = 1 AND company_id = $C")->fetchColumn();
$setRole = function (string $role) use ($DB, $C) {
    $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = 1 AND company_id = ?")->execute([$role, $C]);
    $DB->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role]);
};
register_shutdown_function(function () use ($setRole, $roleBefore) { $setRole($roleBefore ?: 'admin'); });
foreach (glob(FLOWWORK_ROOT . '/storage/finance/ratelimit/*_1.json') ?: [] as $f) @unlink($f);

$port = 18000 + random_int(0, 999);
$env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS')]);
$log = tempnam(sys_get_temp_dir(), 'fwfin');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', FLOWWORK_ROOT], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, FLOWWORK_ROOT, $env);
register_shutdown_function(function () use ($server, $log) { proc_terminate($server); @unlink($log); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = 'http://127.0.0.1:' . $port;

function http(string $method, string $url, array &$jar, $body = null, array $headers = []): array
{
    $h = $jar ? ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar))] : [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '',
                            CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 30]);
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

$jar = [];
$r = http('GET', "$base/login.php", $jar);
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Finance-test-1', 'csrf_token' => csrf_from($r['body'])]), ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in');

$page = http('GET', "$base/finances/", $jar);
assert_eq(200, $page['code'], 'Finance opens');
assert_true(str_contains($page['body'], 'id="finBoot"'), 'with its boot data');
$csrf = csrf_from($page['body']);
assert_true($csrf !== '', 'and a CSRF token');
$json = fn($r) => $r['json'] ?? [];
$post = fn(string $path, array $body, bool $withToken = true) => http('POST', "$base/finances/api/$path", $jar, json_encode($body), array_merge(['Content-Type: application/json'], $withToken ? ['X-CSRF-Token: ' . $csrf] : []));

$b = http('GET', "$base/finances/api/boot.php", $jar);
assert_true($json($b)['ok'] ?? false, 'boot API answers');
assert_true(($json($b)['boot']['caps']['write'] ?? false) === true, 'an admin may write');

// CSRF on every POST
$exp = fin_pick_account($DB, 'expense'); $liab = fin_pick_account($DB, 'liability');
$jl = ['op' => 'save', 'date' => date('Y-m-d'), 'description' => 'HTTP test', 'lines' => [['account_code' => $exp['code'], 'debit' => '10'], ['account_code' => $liab['code'], 'credit' => '10']]];
$r = $post('journals.php', $jl, false);
assert_eq(419, $r['code'], 'a POST without the CSRF token is refused');
$r = $post('journals.php', $jl);
assert_eq(200, $r['code'], 'with the token it saves (' . substr($r['body'], 0, 200) . ')');
$jid = (int)($json($r)['journal']['id'] ?? 0);
assert_true($jid > 0, 'a draft journal');
$r = $post('journals.php', ['op' => 'save', 'date' => date('Y-m-d'), 'lines' => [['account_code' => $exp['code'], 'debit' => '10'], ['account_code' => $liab['code'], 'credit' => '9']]]);
assert_eq(400, $r['code'], 'an unbalanced journal is a 400 with a message');
assert_true(str_contains((string)($json($r)['error'] ?? ''), 'balance'), 'which says why');

// uploads: checked by content, kept outside the web root, served sandboxed
$pdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$r = http('POST', "$base/finances/api/files.php", $jar, ['entity' => 'journal', 'entity_id' => $jid, 'file' => new CURLFile($pdf, 'application/pdf', 'invoice.pdf')], ['X-CSRF-Token: ' . $csrf]);
assert_eq(200, $r['code'], 'a PDF uploads (' . substr($r['body'], 0, 200) . ')');
$fid = (int)($json($r)['file']['id'] ?? 0);
$php = tempnam(sys_get_temp_dir(), 'php');
file_put_contents($php, "<?php echo 'pwned';");
$r = http('POST', "$base/finances/api/files.php", $jar, ['entity' => 'journal', 'entity_id' => $jid, 'file' => new CURLFile($php, 'application/pdf', 'evil.pdf')], ['X-CSRF-Token: ' . $csrf]);
assert_eq(400, $r['code'], 'a PHP file named .pdf is refused (type read from the content)');
$stored = $DB->query("SELECT stored_name FROM fin_attachments WHERE id = $fid")->fetchColumn();
assert_true(is_file(FLOWWORK_ROOT . '/storage/finance/' . $C . '/' . $stored), 'stored under storage/finance (not uploads/)');
assert_true(str_ends_with((string)$stored, '.pdf') && !str_contains((string)$stored, 'invoice'), 'under a random name');
$r = http('GET', "$base/finances/api/files.php?id=$fid", $jar);
assert_eq(200, $r['code'], 'the owner downloads it');
assert_true(stripos($r['head'], 'Content-Type: application/pdf') !== false, 'as a PDF');
assert_true(stripos($r['head'], 'sandbox') !== false, 'with a sandbox CSP');
@unlink($pdf); @unlink($php);

// a part switched off is not there
Fin::writePrefs($DB, $C, 0, ['modules' => ['vat' => false]]);
$r = http('GET', "$base/finances/api/vat.php?op=periods", $jar);
assert_eq(404, $r['code'], 'VAT switched off: its API answers 404');
Fin::writePrefs($DB, $C, 0, ['modules' => null]);
assert_eq(200, http('GET', "$base/finances/api/vat.php?op=periods", $jar)['code'], 'and is back when switched on');

// rate limit
$codes = [];
for ($i = 0; $i < 31; $i++) $codes[] = http('GET', "$base/finances/api/close.php?op=health", $jar)['code'];
assert_eq(429, end($codes), 'the health check is rate limited (30 a minute)');

// a viewer reads but cannot write; a member without the company's permission cannot see
$setRole('viewer');
$r = http('GET', "$base/finances/api/journals.php?status=all", $jar);
assert_eq(200, $r['code'], 'a viewer can read');
$r = $post('journals.php', $jl);
assert_eq(403, $r['code'], 'a viewer cannot write');
$r = $post('payables.php', ['op' => 'pay', 'supplier_id' => 1, 'allocations' => []]);
assert_eq(403, $r['code'], 'nor pay suppliers');
$setRole('member');
assert_eq(403, http('GET', "$base/finances/api/boot.php", $jar)['code'], 'a member cannot see Finance unless the company allows it');
$setRole('admin');

// old addresses
$r = http('GET', "$base/finances/ap/bill_view.php?id=12", $jar);
assert_eq(302, $r['code'], 'an old page redirects');
assert_true(str_contains($r['head'], 'Location: /finances/#payables?status=all&id=12'), 'to the matching screen');
$r = http('POST', "$base/finances/ajax/journal_post.php", $jar, json_encode(['id' => $jid]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
assert_eq(410, $r['code'], 'an old endpoint answers 410 Gone');
assert_eq('draft', $DB->query("SELECT status FROM journal_entries WHERE id = $jid")->fetchColumn(), 'and changed nothing');
$r = http('GET', "$base/finances/ajax/dashboard_stats.php", $jar);
assert_eq(200, $r['code'], 'the home dashboard feed still works');

test_done('fin_http');
