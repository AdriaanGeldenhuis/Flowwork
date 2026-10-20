<?php
/**
 * Shopping over HTTP (PHP's built-in server, the test database): the Maison
 * pages and their boot data, CSRF on every POST, the old endpoints answering
 * 410 Gone, the JSON API (make a list, add, tick with a price, finish), a
 * private list being "not found" for everyone else (page and API), viewers
 * kept to looking, the CSV keeping formulas as text, the Home tile, and a
 * shared link opened without signing in (no money shown, its own CSRF,
 * stopped links refused).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/shopping/lib/Sh.php';

if (getenv('DB_NAME') !== (getenv('TEST_DB_NAME') ?: 'flowwork_test') || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fail('refusing to start a web server outside the local test database');
}
$C = TEST_COMPANY_ID;
ShSchema::ensure($DB);
$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Shopping-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
$roleBefore = $DB->query("SELECT role FROM user_companies WHERE user_id = 1 AND company_id = $C")->fetchColumn();
$setRole = function (string $role) use ($DB, $C) {
    $DB->prepare("UPDATE user_companies SET role = ? WHERE user_id = 1 AND company_id = ?")->execute([$role, $C]);
    $DB->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role]);
};
register_shutdown_function(fn() => $setRole($roleBefore ?: 'admin'));
foreach (glob(FLOWWORK_ROOT . '/storage/shopping/rl-*.json') ?: [] as $f) @unlink($f);
$other = (int)$DB->query("SELECT user_id FROM user_companies WHERE company_id = $C AND user_id <> 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$privateId = ShLists::create($DB, $C, $other, 'member', ['name' => 'Someone else private', 'shared' => 'private'], ShParse::items('secret'));

$port = 18000 + random_int(1000, 1999);
$env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS')]);
$log = tempnam(sys_get_temp_dir(), 'fwsh');
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
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Shopping-test-1', 'csrf_token' => csrf_from($r['body'])]), ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in');

// ---------------------------------------------------------------- pages
foreach (['', 'templates.php', 'prices.php', 'preferences.php'] as $p) {
    $page = http('GET', "$base/shopping/$p", $jar);
    assert_eq(200, $page['code'], "/shopping/$p opens");
    assert_true(str_contains($page['body'], 'class="fwm fws') && str_contains($page['body'], 'id="shBoot"') && str_contains($page['body'], '/mail/css/mail.css') && str_contains($page['body'], '/shopping/css/shopping.css'), "/shopping/$p is a Maison page with its boot data");
    assert_true(!str_contains($page['body'], 'Fatal error') && !str_contains($page['body'], 'Warning:'), "/shopping/$p has no PHP errors");
}
$csrf = csrf_from($page['body']);
assert_true($csrf !== '', 'pages carry a CSRF token');
$r = http('GET', "$base/shopping/list.php?id=$privateId", $jar);
assert_true($r['code'] === 404 && str_contains($r['body'], 'That list is not here') && !str_contains($r['body'], 'secret'), 'someone else\'s private list is a friendly 404, with nothing of it shown');
$api = fn(string $path, array $body, bool $token = true) => http('POST', "$base/shopping/api/$path", $jar, json_encode($body), array_merge(['Content-Type: application/json', 'Accept: application/json'], $token ? ['X-CSRF-Token: ' . $csrf] : []));
$get = fn(string $path) => http('GET', "$base/shopping/api/$path", $jar, null, ['Accept: application/json']);
assert_eq(404, $get("lists.php?op=get&id=$privateId")['code'], 'and the API says the same');
assert_eq(404, $api('items.php', ['op' => 'add', 'list_id' => $privateId, 'text' => 'more'])['code'], 'nothing can be added to it');

// ---------------------------------------------------------------- old endpoints
foreach (['list_create', 'item_add', 'item_check_toggle', 'item_remove', 'session_close', 'session_start_buying', 'list_get', 'list.to_po', 'ai_item_parse', 'ai_stores_suggest'] as $old) {
    $r = http('POST', "$base/shopping/ajax/$old.php", $jar, '{"list_id":' . $privateId . '}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    assert_eq(410, $r['code'], "the old $old.php answers 410 Gone");
}
assert_eq('open', $DB->query("SELECT status FROM shopping_lists WHERE id = $privateId")->fetchColumn(), 'the old session_close can no longer close someone else\'s list');

// ---------------------------------------------------------------- the API
assert_eq(419, $api('lists.php', ['op' => 'create', 'name' => 'No token'], false)['code'], 'a POST without the CSRF token is refused');
$r = $get('items.php?op=parse&text=' . rawurlencode("10 bags cement @ R95\n2x PVC elbow 20mm"));
assert_true($r['code'] === 200 && count($r['json']['items']) === 2 && $r['json']['items'][0]['category_label'] === 'Building & hardware', 'parse reads a list without saving it');
$r = $api('lists.php', ['op' => 'create', 'name' => 'HTTP run', 'shared' => 'team', 'budget' => 500, 'text' => "10 bags cement @ R95\n=HYPERLINK(\"http://x\")"]);
assert_eq(200, $r['code'], 'a list is made (' . substr($r['body'], 0, 160) . ')');
$id = (int)$r['json']['id'];
assert_true($r['json']['url'] === "/shopping/list.php?id=$id" && $r['json']['items'] === 2, 'with its two items');
$page = http('GET', "$base/shopping/list.php?id=$id", $jar);
assert_true($page['code'] === 200 && str_contains($page['body'], 'HTTP run') && str_contains($page['body'], 'id="listApp"'), 'the list page opens');
$d = $get("lists.php?op=get&id=$id")['json'];
assert_true(count($d['items']) === 2 && $d['list']['budget'] === 50000 && $d['version'] !== '', 'get: items, budget, change stamp');
$cement = $d['items'][0]['id'];
$r = $api('items.php', ['op' => 'add', 'list_id' => $id, 'text' => 'cement x 2']);
assert_true($r['code'] === 200 && !$r['json']['added'] && $r['json']['merged'][0]['qty'] === '12', 'adding cement again adds up');
$r = $api('lists.php', ['op' => 'shop', 'id' => $id]);
assert_true($r['code'] === 200 && $r['json']['session'] !== null, 'shopping started');
$r = $api('items.php', ['op' => 'toggle', 'id' => $cement, 'bought' => true]);
assert_true($r['code'] === 200 && $r['json']['item']['bought'] === true && $r['json']['counts']['bought'] === 1, 'ticked');
$r = $api('items.php', ['op' => 'price', 'id' => $cement, 'paid' => '1 140,00']);
assert_true($r['code'] === 200 && $r['json']['item']['paid'] === 114000, 'what it cost (R 1 140,00 as typed)');
assert_eq(422, $api('items.php', ['op' => 'price', 'id' => $cement, 'paid' => 'lots'])['code'], 'a price that is not an amount is refused');
assert_true($get("lists.php?op=version&id=$id")['json']['version'] !== $d['version'], 'the change stamp moved');
$r = $api('lists.php', ['op' => 'finish', 'id' => $id, 'leftover' => 'keep']);
assert_true($r['code'] === 200 && $r['json']['finish']['bought'] === 1 && $r['json']['finish']['left'] === 1, 'finished: one bought, one left');
$csv = http('GET', "$base/shopping/api/lists.php?op=export&id=$id", $jar);
assert_true($csv['code'] === 200 && stripos($csv['head'], 'text/csv') !== false && str_contains($csv['body'], '"\'=HYPERLINK'), 'the CSV keeps a formula as text');
assert_true(str_contains($csv['body'], '1140.00'), 'and has what was paid');
$r = $get('lists.php?op=search&q=cem');
assert_true(in_array('Cement', array_column($r['json']['items'], 'name'), true), 'search finds the item');
$r = $api('settings.php', ['op' => 'save', 'route_mode' => 'walk', 'price_prompt' => 0]);
assert_true($r['code'] === 200 && $r['json']['prefs']['route_mode'] === 'walk' && $r['json']['prefs']['price_prompt'] === 0, 'preferences save');
assert_eq(422, $api('settings.php', ['op' => 'save', 'route_mode' => 'teleport'])['code'], 'and are checked');
$home = http('GET', "$base/home.php", $jar);
assert_true($home['code'] === 200 && str_contains($home['body'], 'to buy on'), 'the Home tile says what is waiting');

// ---------------------------------------------------------------- viewers look
$setRole('viewer');
assert_eq(403, $api('lists.php', ['op' => 'create', 'name' => 'Viewer list'])['code'], 'a viewer cannot make a list');
assert_eq(403, $api('items.php', ['op' => 'add', 'list_id' => $id, 'text' => 'eggs'])['code'], 'nor add to a team list');
assert_eq(200, $get("lists.php?op=get&id=$id")['code'], 'but can look at it');
assert_eq(403, $api('settings.php', ['op' => 'save', 'ai' => 1])['code'], 'and cannot switch AI ideas on');
$setRole('member');
assert_eq(403, $get('lists.php?op=suppliers')['code'], 'members do not make purchase orders');
$setRole('admin');

// ---------------------------------------------------------------- a shared link, no sign-in
$r = $api('lists.php', ['op' => 'share', 'id' => $id, 'days' => 7, 'can_edit' => 0]);
assert_eq(200, $r['code'], 'a link is made');
$token = substr($r['json']['url'], strpos($r['json']['url'], 't=') + 2);
$guest = [];
$page = http('GET', "$base/shopping/share.php?t=$token", $guest);
assert_true($page['code'] === 200 && str_contains($page['body'], 'HTTP run') && str_contains($page['body'], 'Cement'), 'the link opens without signing in');
assert_true(!str_contains($page['body'], '1140') && !str_contains($page['body'], '"paid"') && !str_contains($page['body'], 'budget'), 'no money on it');
assert_true(stripos($page['head'], 'Referrer-Policy: no-referrer') !== false && stripos($page['head'], 'noindex') !== false, 'the token does not leak through referrers or search engines');
$gcsrf = csrf_from($page['body']);
$second = $d['items'][1]['id'];
$gpost = fn(array $b, bool $tok = true) => http('POST', "$base/shopping/api/share.php", $guest, json_encode($b + ['t' => $token]), array_merge(['Content-Type: application/json', 'Accept: application/json'], $tok ? ['X-CSRF-Token: ' . $gcsrf] : []));
assert_eq(419, $gpost(['op' => 'toggle', 'id' => $second, 'bought' => 1, 'name' => 'Sipho'], false)['code'], 'a guest POST needs the page\'s CSRF token');
$r = $gpost(['op' => 'toggle', 'id' => $second, 'bought' => 1, 'name' => 'Sipho']);
assert_true($r['code'] === 200 && $r['json']['items'][1]['bought'] === true, 'the guest ticks it off');
assert_eq('Sipho', $DB->query("SELECT bought_name FROM shopping_items WHERE id = " . (int)$second)->fetchColumn(), 'with their name');
assert_eq(403, $gpost(['op' => 'add', 'text' => 'beer', 'name' => 'Sipho'])['code'], 'a tick-only link cannot add');
assert_eq(404, $gpost(['op' => 'toggle', 'id' => (int)$DB->query("SELECT id FROM shopping_items WHERE list_id = $privateId")->fetchColumn(), 'bought' => 1])['code'], 'nor reach another list\'s items');
$tokenId = (int)$DB->query("SELECT id FROM shopping_share_tokens WHERE token = " . $DB->quote($token))->fetchColumn();
assert_eq(200, $api('lists.php', ['op' => 'unshare', 'id' => $id, 'token_id' => $tokenId])['code'], 'the owner stops the link');
$page = http('GET', "$base/shopping/share.php?t=$token", $guest);
assert_true($page['code'] === 404 && str_contains($page['body'], 'no longer works') && !str_contains($page['body'], 'Cement'), 'a stopped link shows nothing of the list');
assert_eq(404, http('GET', "$base/shopping/share.php?t=" . str_repeat('a', 48), $guest)['code'], 'a made-up token opens nothing');
assert_eq(404, http('GET', "$base/shopping/api/share.php?t=$token", $guest, null, ['Accept: application/json'])['code'], 'nor does the API');

test_done('shopping_http');
