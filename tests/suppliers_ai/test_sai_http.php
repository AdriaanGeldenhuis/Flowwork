<?php
/**
 * Suppliers AI over HTTP (PHP's built-in server, the test database): the
 * Maison pages and their boot data, CSRF on every POST, the old endpoints
 * answering 410 Gone, the JSON API (read the words, search your suppliers,
 * open the search again, the history and the top-bar jump, ask for quotes,
 * the email that never carries the budget), a request of another company
 * being a friendly 404, viewers kept to looking, members kept out of the
 * settings, and the keys: saved, shown masked, never sent back to the browser.
 * Nothing here reaches Google or OpenAI.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/suppliers_ai/lib/Sa.php';

if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
$C = TEST_COMPANY_ID;
SaSchema::ensure($DB);
CrmSchema::ensure($DB);
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Suppliers-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
$roleBefore = $DB->query("SELECT role FROM user_companies WHERE user_id = 1 AND company_id = $C")->fetchColumn();
$setRole = function (string $role) use ($DB, $C) {
    $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = 1 AND company_id = ?")->execute([$role, $C]);
    $DB->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role]);
};
foreach (glob(FLOWWORK_ROOT . '/storage/suppliers_ai/rl-*.json') ?: [] as $f) @unlink($f);

// ---------------------------------------------------------------- fixtures (cleaned up at the end, and before)
$cleanAccounts = function () use ($DB, $C) {
    $ids = $DB->query("SELECT id FROM crm_accounts WHERE company_id = $C AND name LIKE 'Http Tiling %'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    foreach (['crm_interactions', 'crm_compliance_docs', 'crm_account_tags', 'crm_addresses', 'crm_contacts'] as $t) $DB->exec("DELETE FROM $t WHERE account_id IN ($in)");
    $DB->exec("DELETE FROM crm_accounts WHERE id IN ($in)");
};
$cleanAccounts();
SaKit::row($DB, $C);
$settingsBefore = $DB->query("SELECT api_keys_json, rules_json FROM ai_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC);
$blockBefore = $DB->query("SELECT setting_value FROM company_settings WHERE company_id = $C AND setting_key = 'crm_block_expired_suppliers'")->fetchColumn();
$DB->exec("DELETE FROM company_settings WHERE company_id = $C AND setting_key = 'crm_block_expired_suppliers'");
$DB->prepare("UPDATE ai_settings SET api_keys_json = NULL WHERE company_id = ?")->execute([$C]);
register_shutdown_function(function () use ($DB, $C, $setRole, $roleBefore, $settingsBefore, $blockBefore, $cleanAccounts) {
    $setRole($roleBefore ?: 'admin');
    $DB->prepare("UPDATE ai_settings SET api_keys_json = ?, rules_json = ? WHERE company_id = ?")->execute([$settingsBefore['api_keys_json'], $settingsBefore['rules_json'], $C]);
    if ($blockBefore !== false) {
        $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, 'crm_block_expired_suppliers', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$C, $blockBefore]);
    }
    $cleanAccounts();
});
$sfx = substr(bin2hex(random_bytes(3)), 0, 6);
$DB->prepare("INSERT INTO crm_accounts (company_id, type, name, phone, email, notes, status, created_by, created_at) VALUES (?, 'supplier', ?, '011 555 7788', ?, 'Floor and wall tiling, bathrooms.', 'active', 1, NOW())")
   ->execute([$C, "Http Tiling $sfx", "quotes@httptiling-$sfx.co.za"]);
$tiler = (int)$DB->lastInsertId();
CrmAccounts::addTag($DB, $C, $tiler, 'Tiling', null);
$otherCompany = (int)$DB->query("SELECT id FROM companies WHERE id <> $C ORDER BY id LIMIT 1")->fetchColumn();
$foreign = null;
if ($otherCompany) {
    $DB->prepare("INSERT INTO sai_requests (company_id, title, status, created_by, created_at) VALUES (?, 'Secret tender of another company', 'open', 1, NOW())")->execute([$otherCompany]);
    $foreign = (int)$DB->lastInsertId();
}

// ---------------------------------------------------------------- the server
$port = 18000 + random_int(2000, 2999);
$env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS')]);
$log = tempnam(sys_get_temp_dir(), 'fwsa');
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
function boot_from(string $html): ?array
{
    if (!preg_match('/<script id="saBoot" type="application\/json">(.*?)<\/script>/s', $html, $m)) return null;
    return json_decode($m[1], true);
}

$setRole('admin');
$jar = [];
$r = http('GET', "$base/login.php", $jar);
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Suppliers-test-1', 'csrf_token' => csrf_from($r['body'])]), ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in');

// ---------------------------------------------------------------- pages
foreach (['', 'requests.php', 'history.php', 'settings.php', 'help.php'] as $p) {
    $page = http('GET', "$base/suppliers_ai/$p", $jar);
    assert_eq(200, $page['code'], "/suppliers_ai/$p opens");
    assert_true(str_contains($page['body'], 'class="fwm fsa') && str_contains($page['body'], 'id="saBoot"') && str_contains($page['body'], '/mail/css/mail.css') && str_contains($page['body'], '/suppliers_ai/css/suppliers_ai.css'), "/suppliers_ai/$p is a Maison page with its boot data");
    assert_true(!str_contains($page['body'], 'Fatal error') && !str_contains($page['body'], 'Warning:') && !str_contains($page['body'], 'Notice:') && !str_contains($page['body'], 'Deprecated:'), "/suppliers_ai/$p has no PHP errors");
    assert_true(!str_contains($page['body'], 'style.css') && !str_contains($page['body'], 'suppliers_ai.js'), "/suppliers_ai/$p no longer loads the old graphite files");
    $boot = boot_from($page['body']);
    assert_true(is_array($boot) && ($boot['caps']['search'] ?? null) === true && isset($boot['settings']) && !isset($boot['settings']['openai_key']) && !isset($boot['settings']['google_key']), "/suppliers_ai/$p boot data: what the person may do, and no keys");
}
$csrf = csrf_from($page['body']);
assert_true($csrf !== '', 'pages carry a CSRF token');
$api = fn(string $path, array $body, bool $token = true) => http('POST', "$base/suppliers_ai/api/$path", $jar, json_encode($body), array_merge(['Content-Type: application/json', 'Accept: application/json'], $token ? ['X-CSRF-Token: ' . $csrf] : []));
$get = fn(string $path) => http('GET', "$base/suppliers_ai/api/$path", $jar, null, ['Accept: application/json']);

// ---------------------------------------------------------------- old endpoints
foreach (['add_to_crm', 'generate_rfq', 'generate_rfq_bulk', 'generate_rfq_email', 'log_action', 'search', 'test_openai'] as $old) {
    $r = http('POST', "$base/suppliers_ai/ajax/$old.php", $jar, '{"query":"plumber","candidate_ids":[1]}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    assert_true($r['code'] === 410 && ($r['json']['use'] ?? '') !== '', "the old $old.php answers 410 Gone, with where to go");
}
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM purchase_orders WHERE company_id = $C AND po_number LIKE 'RFQ-%'")->fetchColumn(), 'and the old bulk RFQ makes no empty purchase orders any more');

// ---------------------------------------------------------------- the API
assert_eq(419, $api('search.php', ['op' => 'run', 'text' => 'tiler'], false)['code'], 'a POST without the CSRF token is refused');
assert_eq(405, $get('requests.php?op=create')['code'], 'a write over GET is refused');
assert_eq(404, $get('search.php?op=nope')['code'], 'an unknown request is a 404');
$r = $get('search.php?op=parse&text=' . rawurlencode('3 tilers in Vanderbijlpark by Friday, under R20k, COC needed'));
assert_true($r['code'] === 200 && $r['json']['read']['trade']['key'] === 'flooring' && $r['json']['read']['title'] === '3 tilers in Vanderbijlpark, COC' && $r['json']['read']['location'] === 'Vanderbijlpark', 'parse reads the trade and the place without saving anything (' . substr($r['body'], 0, 200) . ')');
assert_true(str_contains(json_encode($r['json']['read']), 'COC'), 'and the requirements');
$r = $api('search.php', ['op' => 'run', 'text' => 'tiler for a bathroom', 'sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
assert_eq(200, $r['code'], 'a search runs (' . substr($r['body'], 0, 200) . ')');
$hit = current(array_filter($r['json']['rows'], fn($x) => $x['account_id'] === $tiler)) ?: null;
assert_true($hit !== null && $hit['email'] === "quotes@httptiling-$sfx.co.za" && $hit['reasons'], 'your tagged tiler is found, with reasons');
$qid = (int)$r['json']['query_id'];
assert_true($qid > 0 && !$r['json']['google'], 'saved, and Google was not asked');
$r = $get("search.php?op=get&id=$qid");
assert_true($r['code'] === 200 && in_array($tiler, array_column($r['json']['rows'], 'account_id'), true), 'the search opens again as it was found');
$r = $get('search.php?op=history');
assert_true($r['code'] === 200 && in_array($qid, array_map('intval', array_column($r['json']['rows'], 'id')), true), 'and is in the history (' . substr($r['body'], 0, 200) . ')');
$r = $get('search.php?op=jump&q=' . rawurlencode("Http Tiling $sfx"));
assert_true($r['code'] === 200 && str_contains($r['body'], "Http Tiling $sfx"), 'the top bar finds the supplier');
$r = $get('suppliers.php?op=crm&q=' . rawurlencode('Http Tiling'));
assert_true($r['code'] === 200 && in_array($tiler, array_map('intval', array_column($r['json']['suppliers'], 'account_id')), true), 'CRM suppliers can be looked up to add by hand');
assert_eq(200, $api('suppliers.php', ['op' => 'log', 'candidate_id' => $hit['id'], 'action' => 'call'])['code'], 'a call is logged');
assert_eq(422, $api('suppliers.php', ['op' => 'log', 'candidate_id' => $hit['id'], 'action' => 'hack'])['code'], 'an unknown action is refused');

// ---------------------------------------------------------------- a request for quotes
$r = $api('requests.php', ['op' => 'create', 'title' => 'Tile a bathroom', 'details' => '12 m² floor, 20 m² walls', 'quantity' => '32 m²', 'location' => 'Vanderbijlpark',
                           'category' => 'flooring', 'needed_by' => date('Y-m-d', strtotime('+14 days')), 'budget' => 'R 18 500', 'query_id' => $qid,
                           'suppliers' => [['account_id' => $tiler, 'candidate_id' => $hit['id']]]]);
assert_true($r['code'] === 200 && $r['json']['added'] === 1, 'a request is made with the tiler on it (' . substr($r['body'], 0, 200) . ')');
$rid = (int)$r['json']['id'];
assert_true(str_starts_with((string)$r['json']['url'], "/suppliers_ai/request.php?id=$rid"), 'and where it is');
$page = http('GET', "$base/suppliers_ai/request.php?id=$rid", $jar);
assert_true($page['code'] === 200 && str_contains($page['body'], 'Tile a bathroom') && str_contains($page['body'], 'id="reqApp"'), 'the request page opens');
$boot = boot_from($page['body']);
assert_true(($boot['data']['request']['budget'] ?? null) === 1850000 && count($boot['data']['suppliers'] ?? []) === 1, 'with the budget (inside the company) and the supplier');
$r = $get("requests.php?op=compose&id=$rid");
assert_true($r['code'] === 200 && str_contains($r['json']['subject'], 'Tile a bathroom') && !str_contains($r['json']['body'] . $r['json']['subject'] . ($r['json']['whatsapp'] ?? ''), '18') , 'the email asks for a quote and never carries the budget');
$r = $get('requests.php?op=list&status=all');
assert_true($r['code'] === 200 && in_array($rid, array_map('intval', array_column($r['json']['requests'], 'id')), true) && isset($r['json']['counts']), 'the list has it');
$recipient = (int)$boot['data']['suppliers'][0]['id'];
$r = $api('requests.php', ['op' => 'mark', 'recipient_id' => $recipient, 'how' => 'phone']);
assert_true($r['code'] === 200 && $r['json']['request']['status'] === 'open', 'asking by phone opens the request');
$r = $api('requests.php', ['op' => 'quote', 'recipient_id' => $recipient, 'amount' => '17 250,00', 'vat' => 'incl', 'lead_days' => 5]);
assert_true($r['code'] === 200 && current(array_filter($r['json']['suppliers'], fn($x) => (int)$x['id'] === $recipient))['quote'] === 1725000, 'the quote as typed (R 17 250,00)');
assert_eq(422, $api('requests.php', ['op' => 'quote', 'recipient_id' => $recipient, 'amount' => 'a lot'])['code'], 'a quote that is not an amount is refused');
assert_eq(422, $api('requests.php', ['op' => 'state', 'id' => $rid, 'to' => 'explode'])['code'], 'an unknown state is refused');
assert_eq(200, $api('requests.php', ['op' => 'refresh', 'id' => $rid])['code'], 'looking for replies works');

// ---------------------------------------------------------------- another company's request
if ($foreign) {
    $page = http('GET', "$base/suppliers_ai/request.php?id=$foreign", $jar);
    assert_true($page['code'] === 404 && str_contains($page['body'], 'That request is not here') && !str_contains($page['body'], 'Secret tender'), 'another company\'s request is a friendly 404, with nothing of it shown');
    assert_eq(404, $get("requests.php?op=get&id=$foreign")['code'], 'and the API says the same');
    assert_eq(404, $api('requests.php', ['op' => 'state', 'id' => $foreign, 'to' => 'cancel'])['code'], 'it cannot be changed');
    $DB->exec("DELETE FROM sai_requests WHERE id = $foreign");
}

// ---------------------------------------------------------------- settings and the keys
$r = $api('settings.php', ['op' => 'template', 'subject' => 'Quote please', 'body' => 'Our budget is {budget}']);
assert_eq(422, $r['code'], 'the budget cannot be put into the email');
$r = $api('settings.php', ['op' => 'save', 'radius_km' => 'far']);
assert_eq(422, $r['code'], 'settings are checked');
$r = $api('settings.php', ['op' => 'keys', 'openai_key' => 'not-a-key']);
assert_eq(422, $r['code'], 'a key that is not an OpenAI key is refused');
$openai = 'sk-http' . bin2hex(random_bytes(14)) . 'AbCd';
$google = 'AIzaHttp' . bin2hex(random_bytes(16)) . 'WxYz';
$r = $api('settings.php', ['op' => 'keys', 'openai_key' => $openai, 'openai_on' => 0, 'google_key' => $google]);
assert_true($r['code'] === 200 && !str_contains($r['body'], $openai) && !str_contains($r['body'], $google), 'keys are saved, and not sent back');
assert_true(str_contains($r['body'], 'AbCd') && str_contains($r['body'], 'WxYz'), 'only their last characters show');
$keys = json_decode((string)$DB->query("SELECT api_keys_json FROM ai_settings WHERE company_id = $C")->fetchColumn(), true);
assert_true($keys['openai_api_key'] === $openai && $keys['google_places_key'] === $google, 'they are where Receipts and Shopping read the OpenAI key');
foreach (['settings.php', '', 'help.php'] as $p) {
    $page = http('GET', "$base/suppliers_ai/$p", $jar);
    assert_true($page['code'] === 200 && !str_contains($page['body'], $openai) && !str_contains($page['body'], $google), "/suppliers_ai/$p never carries the keys");
}
assert_true(!str_contains($get('settings.php?op=get')['body'], $openai), 'nor does the settings API');
$r = $api('settings.php', ['op' => 'keys', 'forget' => 'google']);
assert_true($r['code'] === 200 && empty($r['json']['settings']['google_ready']), 'the Google key is forgotten');
assert_eq(409, $api('settings.php', ['op' => 'test_google'])['code'], 'testing Google without a key says to add it first');

// ---------------------------------------------------------------- members and viewers
$setRole('member');
$page = http('GET', "$base/suppliers_ai/settings.php", $jar);
assert_true($page['code'] === 403 && str_contains($page['body'], 'Settings are for admins and bookkeepers') && !str_contains($page['body'], 'AbCd'), 'a member gets a friendly page instead of the settings');
assert_eq(403, $api('settings.php', ['op' => 'save', 'radius_km' => 10])['code'], 'and cannot change them');
assert_eq(403, $get('settings.php?op=get')['code'], 'nor read the company\'s lists through the API');
assert_eq(403, $api('settings.php', ['op' => 'keys', 'openai_on' => 1])['code'], 'nor the keys');
assert_eq(403, $api('requests.php', ['op' => 'po', 'id' => $rid])['code'], 'members do not make purchase orders');
$r = $api('search.php', ['op' => 'run', 'text' => 'tiler', 'sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
assert_eq(200, $r['code'], 'a member searches');
$setRole('viewer');
assert_eq(403, $api('search.php', ['op' => 'run', 'text' => 'tiler', 'sources' => ['crm' => true]])['code'], 'a viewer does not search');
assert_eq(403, $api('requests.php', ['op' => 'create', 'title' => 'Viewer', 'suppliers' => [['account_id' => $tiler]]])['code'], 'nor ask for quotes');
assert_eq(403, $api('requests.php', ['op' => 'quote', 'recipient_id' => $recipient, 'amount' => '1'])['code'], 'nor change a request');
assert_eq(200, $get("requests.php?op=get&id=$rid")['code'], 'but can look at it');
$page = http('GET', "$base/suppliers_ai/", $jar);
assert_true($page['code'] === 200 && (boot_from($page['body'])['caps']['search'] ?? true) === false, 'and the page knows they only look');
$setRole('admin');

// ---------------------------------------------------------------- Home
$home = http('GET', "$base/home.php", $jar);
assert_true($home['code'] === 200 && str_contains($home['body'], 'Find a supplier') && !str_contains($home['body'], 'Fatal error'), 'the Home tile');

test_done('sai_http');
