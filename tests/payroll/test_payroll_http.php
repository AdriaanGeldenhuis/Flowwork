<?php
/**
 * Payroll over HTTP (PHP's built-in server, the test database): the pages
 * and their boot data, CSRF on every POST, who may use Payroll (admins and
 * bookkeepers; members get "Payroll is private"), admin-only settings,
 * reports as JSON and CSV, and the old addresses redirecting.
 */
require __DIR__ . '/../lib/bootstrap.php';

if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
$C = TEST_COMPANY_ID;
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Payroll-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
$roleBefore = $DB->query("SELECT role FROM user_companies WHERE user_id = 1 AND company_id = $C")->fetchColumn();
$setRole = function (string $role) use ($DB, $C) {
    $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = 1 AND company_id = ?")->execute([$role, $C]);
    $DB->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role]);
};
$made = [];
register_shutdown_function(function () use ($setRole, $roleBefore, $DB, &$made) {
    $setRole($roleBefore ?: 'admin');
    foreach ($made as $id) $DB->exec("DELETE FROM employees WHERE id = " . (int)$id);
});
foreach (glob(FLOWWORK_ROOT . '/storage/payroll/rl-*.json') ?: [] as $f) @unlink($f);

$port = 19000 + random_int(0, 999);
$env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS')]);
$log = tempnam(sys_get_temp_dir(), 'fwpay');
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

$setRole('admin');
$jar = [];
$r = http('GET', "$base/login.php", $jar);
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Payroll-test-1', 'csrf_token' => csrf_from($r['body'])]), ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in');

foreach (['', 'employees.php', 'runs.php', 'payitems.php', 'reports.php', 'settings.php'] as $p) {
    $page = http('GET', "$base/payroll/$p", $jar);
    assert_eq(200, $page['code'], "/payroll/$p opens");
    assert_true(str_contains($page['body'], 'id="payBoot"') && str_contains($page['body'], 'class="fwm fwp"'), "/payroll/$p is a Maison page with its boot data");
}
$csrf = csrf_from($page['body']);
assert_true($csrf !== '', 'pages carry a CSRF token');
$post = fn(string $path, array $body, bool $withToken = true) => http('POST', "$base/payroll/api/$path", $jar, json_encode($body), array_merge(['Content-Type: application/json'], $withToken ? ['X-CSRF-Token: ' . $csrf] : []));

// CSRF on every POST
$emp = ['op' => 'save', 'first_name' => 'Http', 'last_name' => 'Tester', 'employee_no' => 'PHTTP-1', 'hire_date' => '2026-01-05', 'basis' => 'salary', 'monthly' => '12000',
        'pay_frequency' => 'monthly', 'employment_type' => 'permanent', 'uif_included' => 1, 'sdl_included' => 1];
$r = $post('employees.php', $emp, false);
assert_eq(419, $r['code'], 'a POST without the CSRF token is refused');
$r = $post('employees.php', $emp);
assert_eq(200, $r['code'], 'with the token the employee is saved (' . substr($r['body'], 0, 200) . ')');
$made[] = $id = (int)($r['json']['id'] ?? 0);
assert_true($id > 0 && ($r['json']['employee']['per_period'] ?? 0) === 1200000, 'and answered with their pay');
$r = $post('employees.php', ['op' => 'save', 'first_name' => '', 'last_name' => 'X', 'hire_date' => 'soon', 'basis' => 'salary', 'monthly' => 'lots']);
assert_eq(422, $r['code'], 'bad values are a 422');
assert_true(isset($r['json']['errors']['first_name'], $r['json']['errors']['hire_date'], $r['json']['errors']['monthly']), 'with a message for each field');
$r = $post('runs.php', ['op' => 'hack']);
assert_eq(400, $r['code'], 'unknown actions are refused');
$r = $post('runs.php', ['op' => 'create', 'frequency' => ['monthly'], 'period_start' => ['x'], 'pay_date' => '2026-01-01']);
assert_eq(422, $r['code'], 'lists where a value belongs are refused, not a server error');
$r = http('GET', "$base/payroll/api/employees.php?op=list&frequency[]=monthly&q[]=x", $jar);
assert_eq(200, $r['code'], 'odd query strings are ignored, not a server error');

// Reports: JSON and a spreadsheet
$r = http('GET', "$base/payroll/api/reports.php?op=emp201&month=" . date('Y-m'), $jar);
assert_true(($r['json']['ok'] ?? false) && isset($r['json']['report']['due']), 'EMP201 answers with a due date');
$r = http('GET', "$base/payroll/api/reports.php?op=emp201&month=" . date('Y-m') . '&format=csv', $jar);
assert_true(str_contains($r['head'], 'text/csv') && str_starts_with($r['body'], "\xEF\xBB\xBF"), 'EMP201 downloads as a UTF-8 spreadsheet');
$r = http('GET', "$base/payroll/api/runs.php?op=get&id=999999", $jar);
assert_eq(404, $r['code'], 'a run that does not exist is a 404');

// Bookkeepers run payroll but do not change settings
$setRole('bookkeeper');
$r = http('GET', "$base/payroll/api/employees.php?op=list", $jar);
assert_true($r['json']['ok'] ?? false, 'a bookkeeper uses Payroll');
$r = $post('settings.php', ['op' => 'save', 'hours_per_week' => '40']);
assert_eq(403, $r['code'], 'but cannot change payroll settings');
$r = $post('items.php', ['op' => 'save', 'name' => 'Sneaky', 'type' => 'earning', 'taxable' => 0]);
assert_eq(403, $r['code'], 'nor pay items, which decide how pay is taxed');
$r = http('GET', "$base/payroll/settings.php", $jar);
assert_true(str_contains($r['body'], 'Only an admin can change these'), 'and the settings page says so');

// Members never see salaries
$setRole('member');
$r = http('GET', "$base/payroll/", $jar);
assert_eq(403, $r['code'], 'a member is turned away');
assert_true(str_contains($r['body'], 'Payroll is private'), 'with a polite page');
$r = http('GET', "$base/payroll/api/employees.php?op=get&id=$id", $jar);
assert_eq(403, $r['code'], 'the API refuses members');
assert_true(!str_contains($r['body'], '12000') && !str_contains($r['body'], 'Tester'), 'and gives nothing away');
$r = http('GET', "$base/payroll/api/payslip.php?run=1&employee=$id", $jar);
assert_eq(403, $r['code'], 'payslips refuse members');
$setRole('admin');

// Old addresses
$r = http('GET', "$base/payroll/run_new.php", $jar);
assert_true($r['code'] === 302 && str_contains($r['head'], 'runs.php?new=1'), 'the old new-run page opens the new-run dialog');
$r = http('GET', "$base/payroll/payslips.php?run_id=7", $jar);
assert_true($r['code'] === 302 && str_contains($r['head'], 'run_view.php?id=7'), 'the old payslips page opens the run');
$r = http('GET', "$base/payroll/ajax/run_list.php", $jar);
assert_eq(404, $r['code'], 'the old ajax endpoints are gone');

$r = $post('employees.php', ['op' => 'delete', 'id' => $id]);
assert_true($r['json']['ok'] ?? false, 'a never-paid employee can be deleted');
$made = [];

test_done('payroll_http');
