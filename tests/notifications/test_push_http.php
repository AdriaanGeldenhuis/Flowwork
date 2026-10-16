<?php
/**
 * End-to-end over HTTP with PHP's built-in server and the test database:
 * the Android app signs in, its push-token cookie gets bound to that device,
 * a notification tap marks the bell item read and switches company, the
 * notification's Mark as read button works without a session, and signing
 * the device out stops it all.
 *
 *   php tests/notifications/test_push_http.php
 *
 * Needs the test database (tests/db/setup.sh). Resets user 1's password in
 * the test database and adds mail fixtures for it.
 */

require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/includes/auth_sessions.php';

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$saFile = tempnam(sys_get_temp_dir(), 'fwsa');
file_put_contents($saFile, json_encode([
    'type' => 'service_account', 'project_id' => 'flowwork-test', 'private_key_id' => 'k1', 'private_key' => $pem,
    'client_email' => 'push@flowwork-test.iam.gserviceaccount.com',
]));
putenv('FCM_SERVICE_ACCOUNT_FILE=' . $saFile);
require FLOWWORK_ROOT . '/includes/notify.php';
require FLOWWORK_ROOT . '/mail/lib/autoload.php';

// ---------------------------------------------------------------- fixtures

$email = $DB->query("SELECT email FROM users WHERE id = 1")->fetchColumn();
$DB->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE id = 1")->execute([password_hash('Push-test-1', PASSWORD_DEFAULT)]);
$DB->exec("DELETE FROM user_sessions");
$DB->exec("DELETE FROM auth_login_attempts");
foreach (['push_outbox', 'push_devices', 'notifications'] as $t) $DB->exec("DROP TABLE IF EXISTS `$t`");
fw_push_install($DB);
fw_notify_install($DB);
MailSchema::ensure($DB);

$DB->exec("DELETE FROM email_imap_ops");
$DB->exec("DELETE FROM emails WHERE company_id = 1");
$DB->exec("DELETE FROM email_threads WHERE company_id = 1");
$DB->exec("DELETE FROM email_accounts WHERE company_id = 1");
$DB->prepare("INSERT INTO email_accounts (company_id, user_id, account_name, email_address, imap_server, is_active)
              VALUES (1, 1, 'Work', 'me@example.com', '', 1)")->execute();
$accountId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO email_threads (company_id, subject, subject_norm, last_message_at) VALUES (1, 'Quote 1042', 'quote 1042', NOW())")->execute();
$threadId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO emails (company_id, account_id, thread_id, folder, direction, sender, sender_name, subject, body_text, sent_at, is_read, imap_uid, imap_folder)
              VALUES (1, ?, ?, 'INBOX', 'incoming', 'ann@example.com', 'Ann Lee', 'Quote 1042', 'Hi', NOW(), 0, 77, 'INBOX')")
   ->execute([$accountId, $threadId]);
$emailId = (int)$DB->lastInsertId();
$nid = fw_notify($DB, 1, 1, 'mail_new', 'New email from Ann Lee', 'Quote 1042', '/mail/?open=t' . $threadId, false);

// ---------------------------------------------------------------- server

$port = 18000 + random_int(0, 999);
$env = array_merge(getenv(), [
    'APP_ENV' => 'testing', 'FCM_SERVICE_ACCOUNT_FILE' => $saFile,
    'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS'),
]);
$log = tempnam(sys_get_temp_dir(), 'fwsrv');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', FLOWWORK_ROOT], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
                    $pipes, FLOWWORK_ROOT, $env);
register_shutdown_function(function () use ($server, $saFile, $log) {
    proc_terminate($server);
    @unlink($saFile);
    @unlink($log);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$base = 'http://127.0.0.1:' . $port;
$token = str_repeat('k', 22) . ':APA91b' . str_repeat('kx_-', 40);
$jar = ['fw_push' => $token];
const APP_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36 FlowWorkApp/1.1';

/** Minimal browser: keeps cookies, does not follow redirects. */
function http(string $method, string $url, array &$jar, $body = null, array $headers = [], bool $cookies = true): array
{
    $h = ['User-Agent: ' . APP_UA];
    if ($cookies && $jar) {
        $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    if ($cookies) {
        preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $head, $m, PREG_SET_ORDER);
        foreach ($m as $c) {
            if ($c[2] === '' || $c[2] === 'deleted') unset($jar[$c[1]]); else $jar[$c[1]] = $c[2];
        }
    }
    preg_match('/^Location:\s*(\S+)/mi', $head, $loc);
    return ['code' => $code, 'body' => substr($raw, $hs), 'location' => $loc[1] ?? null];
}

function csrf_from(string $html): string
{
    preg_match('/name="csrf(?:_token|-token)"\s+(?:value|content)="([a-f0-9]{64})"/', $html, $m);
    return $m[1] ?? '';
}

// ---------------------------------------------------------------- sign in as the app

$r = http('GET', "$base/login.php", $jar);
assert_eq(200, $r['code'], 'login page');
$csrf = csrf_from($r['body']);
assert_true($csrf !== '', 'login form has a CSRF token');
$r = http('POST', "$base/login.php", $jar, http_build_query(['email' => $email, 'password' => 'Push-test-1', 'csrf_token' => $csrf]),
          ['Content-Type: application/x-www-form-urlencoded']);
assert_eq(302, $r['code'], 'signed in (redirect)');
$sid = (int)$DB->query("SELECT id FROM user_sessions WHERE user_id = 1 AND revoked_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true($sid > 0, 'device row created');
assert_eq(1, (int)$DB->query("SELECT is_app FROM user_sessions WHERE id = $sid")->fetchColumn(), 'recognised as the app');

$r = http('GET', "$base/account/", $jar);
assert_eq(200, $r['code'], 'account page');
$dev = $DB->query("SELECT * FROM push_devices WHERE token_hash = '" . hash('sha256', $token) . "'")->fetch();
assert_true((bool)$dev, 'push token bound on the next request');
assert_eq($sid, (int)$dev['session_id'], 'bound to this signed-in device');
assert_eq(1, (int)$dev['user_id'], 'bound to the signed-in user');
assert_eq('1.1', $dev['app_version'], 'app version recorded');
$accountCsrf = csrf_from($r['body']);

$r = http('GET', "$base/account/api/sessions.php", $jar, null, ['Accept: application/json']);
$j = json_decode($r['body'], true);
assert_true($j['ok'] && $j['push_ready'], 'devices API: push is configured');
$here = array_values(array_filter($j['devices'], fn($d) => $d['current']))[0];
assert_true($here['push'], 'this device shows Notifications on');

$r = http('POST', "$base/account/api/sessions.php", $jar, json_encode(['action' => 'test_push']), ['Content-Type: application/json']);
assert_eq(419, $r['code'], 'test notification needs the CSRF token');

// ---------------------------------------------------------------- notification tap

assert_eq(0, (int)$DB->query("SELECT is_read FROM notifications WHERE id = $nid")->fetchColumn(), 'bell item unread');
$r = http('GET', "$base/mail/?open=t$threadId&fw_nid=$nid&fw_co=1", $jar);
assert_eq(200, $r['code'], 'tap opens the mailbox');
assert_eq(1, (int)$DB->query("SELECT is_read FROM notifications WHERE id = $nid")->fetchColumn(), 'tap marks the bell item read');

// A tap the day after: the PHP session is gone, "keep me signed in" restores it,
// and the tap must still switch company and mark the bell item read.
$nid2 = fw_notify($DB, 1, 1, 'mail_new', 'New email from Bob', 'Invoice', '/mail/?open=t' . $threadId, false);
unset($jar['FLOWWORKSESSID']);
assert_true(isset($jar['fw_remember']), 'the app keeps the "keep me signed in" cookie');
$r = http('GET', "$base/mail/?open=t$threadId&fw_nid=$nid2&fw_co=1", $jar);
assert_eq(200, $r['code'], 'restored and opened');
assert_eq(1, (int)$DB->query("SELECT is_read FROM notifications WHERE id = $nid2")->fetchColumn(), 'tap after a restore marks it read');

// Crafted array parameters must not break pages with PHP warnings.
$jarArr = $jar + ['fw_push[x]' => 'y'];
$r = http('GET', "$base/account/api/sessions.php?fw_nid[]=1&fw_co[]=2", $jarArr, null, ['Accept: application/json']);
assert_true(json_decode($r['body'], true)['ok'] ?? false, 'array parameters ignored, JSON intact');
assert_true(!str_contains($r['body'], 'Array to string'), 'no PHP warning in the response');
$none = [];
$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => ['x'], 'action' => ['read']]),
          ['Content-Type: application/json'], false);
assert_eq(400, $r['code'], 'array action rejected cleanly');
assert_true(!str_contains($r['body'], 'Array to string'), 'no PHP warning from push_action');

// ---------------------------------------------------------------- Mark as read button (no session)

$deviceId = (int)$dev['id'];
$act = fw_push_action_token(1, 1, 't' . $threadId, $deviceId);
$none = [];
$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => $act . 'x', 'action' => 'read']),
          ['Content-Type: application/json'], false);
assert_eq(401, $r['code'], 'tampered token rejected');
$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => $act, 'action' => 'delete_forever']),
          ['Content-Type: application/json'], false);
assert_eq(400, $r['code'], 'only read and archive are allowed');
$r = http('GET', "$base/mail/api/push_action.php", $none, null, [], false);
assert_eq(405, $r['code'], 'POST only');

$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => $act, 'action' => 'read']),
          ['Content-Type: application/json'], false);
$j = json_decode($r['body'], true);
assert_eq(200, $r['code'], 'Mark as read accepted: ' . $r['body']);
assert_true($j['ok'] && $j['affected'] >= 1, 'message affected');
assert_eq(1, (int)$DB->query("SELECT is_read FROM emails WHERE email_id = $emailId")->fetchColumn(), 'email is read');
assert_true((int)$DB->query("SELECT COUNT(*) FROM email_imap_ops WHERE email_id = $emailId")->fetchColumn() >= 1,
            'server-side flag change queued for the next sync');
$dismiss = $DB->query("SELECT payload FROM push_outbox WHERE payload LIKE '%\"t\":\"d\"%' ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true($dismiss && str_contains($dismiss, 'mail:t' . $threadId), "removed from the user's other phones");

$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => $act, 'action' => 'archive']),
          ['Content-Type: application/json'], false);
assert_eq(200, $r['code'], 'Archive accepted');
assert_eq('Archive', $DB->query("SELECT folder FROM emails WHERE email_id = $emailId")->fetchColumn(), 'archived');

// Reading in the web app also clears the phones.
$DB->exec("UPDATE emails SET folder = 'INBOX', is_read = 0 WHERE email_id = $emailId");
$DB->exec("DELETE FROM push_outbox");
$r = http('GET', "$base/mail/", $jar);
$mailCsrf = csrf_from($r['body']);
$r = http('POST', "$base/mail/api/action.php", $jar, json_encode(['keys' => ['t' . $threadId], 'action' => 'read', 'view' => 'inbox']),
          ['Content-Type: application/json', 'X-CSRF-Token: ' . $mailCsrf, 'Accept: application/json']);
assert_eq(200, $r['code'], 'read in the web app: ' . substr($r['body'], 0, 200));
$dismiss = $DB->query("SELECT payload FROM push_outbox ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true($dismiss && str_contains($dismiss, '"t":"d"') && str_contains($dismiss, 'mail:t' . $threadId),
            'reading in the web app dismisses the phone notification');

// ---------------------------------------------------------------- sign out stops it

$r = http('GET', "$base/logout.php", $jar);
assert_eq(0, count(fw_push_devices($DB, 1)), 'signed out: no more pushes to that phone');
$r = http('POST', "$base/mail/api/push_action.php", $none, json_encode(['token' => $act, 'action' => 'read']),
          ['Content-Type: application/json'], false);
assert_eq(403, $r['code'], 'buttons stop working once the device is signed out');

// Nothing left for the end-of-request sender to try against the real Firebase.
$DB->exec("DELETE FROM push_outbox");

echo 'push http: OK (' . $GLOBALS['__assert_count'] . " assertions)\n";
