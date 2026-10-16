<?php
/**
 * Phone notifications (includes/push.php, includes/notify.php) against the
 * test database, with a fake Firebase transport: no network, no real key.
 *
 *   php tests/notifications/test_push.php
 *
 * Needs the test database (tests/db/setup.sh). Drops and recreates the
 * notifications / push tables and clears user_sessions.
 */

require __DIR__ . '/../lib/bootstrap.php';

// A throwaway Firebase service account with a fresh RSA key.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$publicKey = openssl_pkey_get_details($key)['key'];
$saFile = tempnam(sys_get_temp_dir(), 'fwsa');
file_put_contents($saFile, json_encode([
    'type' => 'service_account', 'project_id' => 'flowwork-test', 'private_key_id' => 'k1', 'private_key' => $pem,
    'client_email' => 'push@flowwork-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
]));
putenv('FCM_SERVICE_ACCOUNT_FILE=' . $saFile);
register_shutdown_function(fn() => @unlink($saFile));

require FLOWWORK_ROOT . '/includes/auth_sessions.php';
require FLOWWORK_ROOT . '/includes/notify.php';
require FLOWWORK_ROOT . '/mail/lib/autoload.php';

foreach (['push_outbox', 'push_devices', 'notifications'] as $t) $DB->exec("DROP TABLE IF EXISTS `$t`");
$DB->exec("DELETE FROM user_sessions");

const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 FlowWorkApp/1.1';

function make_session(PDO $db, int $uid, string $expires = '+1 day'): int
{
    fw_auth_db($db, fn() => $db->prepare("INSERT INTO user_sessions (user_id, device_label, is_app, expires_at) VALUES (?, 'test', 1, ?)")
                               ->execute([$uid, date('Y-m-d H:i:s', strtotime($expires))]));
    return (int)$db->lastInsertId();
}

function fake_token(string $c): string
{
    return str_repeat($c, 22) . ':APA91b' . str_repeat($c . 'x_-', 40);
}

function outbox(PDO $db, int $id): array
{
    $st = $db->prepare("SELECT * FROM push_outbox WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function jwt_part(string $jwt, int $i): array
{
    return json_decode(fw_push_b64url_decode(explode('.', $jwt)[$i]), true);
}

// ---------------------------------------------------------------- in-app table

$nid = fw_notify($DB, 1, 1, 'mention', 'Anna mentioned you', '"Task": hello', '/projects/board.php?board_id=1&item=2', false);
assert_true($nid > 0, 'fw_notify creates the missing notifications table and stores the row');
$row = $DB->query("SELECT * FROM notifications WHERE id = $nid")->fetch();
assert_eq('/projects/board.php?board_id=1&item=2', $row['url'], 'url stored');
assert_eq($row['url'], $row['link'], 'link mirrors url (the bell reads link)');
assert_eq(0, (int)$row['is_read'], 'new row is unread');

// A hand-made table without the newer columns is repaired on first use.
$DB->exec("DROP TABLE notifications");
$DB->exec("CREATE TABLE notifications (id int AUTO_INCREMENT PRIMARY KEY, company_id int NOT NULL, user_id int NOT NULL,
           type varchar(40), title varchar(255), message varchar(500), created_at datetime)");
$nid = fw_notify($DB, 1, 1, 'calendar_reminder', 'Site visit', 'Event starts at 9:00 AM', '/calendar/event_view.php?id=4', false);
assert_true($nid > 0, 'old notifications table repaired and written');
$cols = array_column($DB->query("SHOW COLUMNS FROM notifications")->fetchAll(), 'Field');
foreach (['url', 'link', 'is_read', 'read_at'] as $c) assert_true(in_array($c, $cols, true), "column $c added");
// The legacy calendar insert (link only, no is_read) still works on it.
$DB->exec("INSERT INTO notifications (company_id, user_id, type, title, message, link, created_at)
           VALUES (1, 1, 'calendar_reminder', 'x', 'y', '/calendar/event_view.php?id=1', NOW())");
assert_eq(0, (int)$DB->query("SELECT is_read FROM notifications ORDER BY id DESC LIMIT 1")->fetchColumn(), 'is_read defaults to 0');

assert_eq('/mail/?open=t1&fw_co=5#inbox', fw_notify_push_url('/mail/?open=t1#inbox', 5), 'push url keeps the fragment last');
assert_eq('/home.php?fw_co=5', fw_notify_push_url('//evil.example', 5), 'push url refuses other sites');
assert_eq('/calendar/?fw_co=2', fw_notify_push_url('/calendar/', 2), 'push url adds the company');
assert_eq('mail_reminders', fw_notify_channel('mail_sla'), 'sla channel');
assert_eq('projects', fw_notify_channel('mention'), 'mention channel');
assert_eq('calendar', fw_notify_channel('calendar_reminder'), 'calendar channel');

// ---------------------------------------------------------------- devices

assert_eq(null, fw_push_queue($DB, 1, ['title' => 'x']), 'nothing queued for a user without the app');

$s1 = make_session($DB, 1);
$tokA = fake_token('a');
fw_push_bind($DB, 1, $s1, $tokA, UA);
$dev = fw_push_devices($DB, 1);
assert_eq(1, count($dev), 'device bound to the signed-in session');
assert_eq($tokA, $dev[0]['token'], 'token stored');
$meta = $DB->query("SELECT app_version, device_label FROM push_devices")->fetch();
assert_eq('1.1', $meta['app_version'], 'app version from the user agent');
assert_true(str_contains($meta['device_label'], 'Flowwork app'), 'device label');

fw_push_bind($DB, 1, $s1, 'not a token!', UA);
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM push_devices")->fetchColumn(), 'invalid token ignored');

$tokB = fake_token('b');
fw_push_bind($DB, 1, $s1, $tokB, UA);
$dev = fw_push_devices($DB, 1);
assert_eq(1, count($dev), 'rotated token replaces the old one on the same device');
assert_eq($tokB, $dev[0]['token'], 'new token active');
assert_eq('replaced', $DB->query("SELECT last_error FROM push_devices WHERE token_hash = '" . hash('sha256', $tokA) . "'")->fetchColumn(),
          'old token disabled as replaced');

// Someone else signs in on the same phone: the token moves to them.
$s3 = make_session($DB, 3);
fw_push_bind($DB, 3, $s3, $tokB, UA);
assert_eq(0, count(fw_push_devices($DB, 1)), 'previous user no longer receives on that phone');
assert_eq(1, count(fw_push_devices($DB, 3)), 'new user receives on it');

fw_auth_revoke($DB, 3, $s3, 'signed_out');
assert_eq(0, count(fw_push_devices($DB, 3)), 'signed-out device gets no pushes');

$sOld = make_session($DB, 4, '-1 hour');
fw_push_bind($DB, 4, $sOld, fake_token('c'), UA);
assert_eq(0, count(fw_push_devices($DB, 4)), 'expired device gets no pushes');

// Fresh, two-phone setup for user 1.
$DB->exec("DELETE FROM push_devices");
$p1 = make_session($DB, 1);
$p2 = make_session($DB, 1);
$tok1 = fake_token('d');
$tok2 = fake_token('e');
fw_push_bind($DB, 1, $p1, $tok1, UA);
fw_push_bind($DB, 1, $p2, $tok2, UA);
$devs = fw_push_devices($DB, 1);
assert_eq(2, count($devs), 'two phones');
$map = fw_push_session_map($DB, 1);
assert_true(isset($map[$p1], $map[$p2]), 'session map lists both phones');

// ---------------------------------------------------------------- sending

$calls = [];
$responses = [];
$transport = function (string $url, array $headers, string $body) use (&$calls, &$responses) {
    $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    if (str_contains($url, 'oauth2.googleapis.com')) {
        return [200, json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])];
    }
    return array_shift($responses) ?? [200, '{"name":"projects/flowwork-test/messages/1"}'];
};
$fcm = new FwFcmClient(fw_push_service_account(), $transport);

$oid = fw_push_queue($DB, 1, ['ch' => 'mail', 'grp' => 'mail', 'title' => str_repeat('T', 300), 'body' => 'Quote 1042',
                              'tag' => 'mail:t5', 'url' => '/mail/?open=t5', 'app' => 'mail', 'bad key' => 'x', 'arr' => [1]],
                    ['act_key' => 't5', 'act_company' => 1]);
assert_true($oid > 0, 'queued');
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(1, $stats['sent'], 'message sent');
assert_eq(2, $stats['devices'], 'to both phones');
$o = outbox($DB, $oid);
assert_true($o['sent_at'] !== null, 'outbox row marked sent');
assert_eq(2, (int)$o['sent_count'], 'sent_count');

// OAuth: a correctly signed RS256 service-account assertion.
assert_true(str_contains($calls[0]['url'], 'oauth2.googleapis.com/token'), 'first call fetches an access token');
parse_str($calls[0]['body'], $form);
assert_eq('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type'], 'jwt-bearer grant');
$parts = explode('.', $form['assertion']);
assert_eq(1, openssl_verify($parts[0] . '.' . $parts[1], fw_push_b64url_decode($parts[2]), $publicKey, OPENSSL_ALGO_SHA256),
          'assertion signature verifies with the public key');
$claims = jwt_part($form['assertion'], 1);
assert_eq('push@flowwork-test.iam.gserviceaccount.com', $claims['iss'], 'iss');
assert_eq('https://www.googleapis.com/auth/firebase.messaging', $claims['scope'], 'scope');
assert_eq(3600, $claims['exp'] - $claims['iat'], 'one hour');
assert_eq('RS256', jwt_part($form['assertion'], 0)['alg'], 'alg');

// FCM HTTP v1 message.
$send = $calls[1];
assert_eq('https://fcm.googleapis.com/v1/projects/flowwork-test/messages:send', $send['url'], 'v1 endpoint');
assert_true(in_array('Authorization: Bearer ya29.test', $send['headers'], true), 'bearer token');
$msg = json_decode($send['body'], true)['message'];
assert_true(in_array($msg['token'], [$tok1, $tok2], true), 'addressed to a phone');
assert_eq('HIGH', $msg['android']['priority'], 'high priority for a new email');
assert_eq('86400s', $msg['android']['ttl'], 'ttl');
assert_true(!isset($msg['notification']), 'data-only message (the app draws it)');
assert_eq(140, mb_strlen($msg['data']['title']), 'title trimmed to 140');
assert_true(str_ends_with($msg['data']['title'], '…'), 'trimmed with an ellipsis');
assert_true(!isset($msg['data']['bad key']) && !isset($msg['data']['arr']), 'only scalar, well-named keys are sent');
foreach ($msg['data'] as $k => $v) assert_true(is_string($v), "data.$k is a string");
$act = fw_push_action_verify($msg['data']['act']);
assert_true($act !== null, 'action token verifies');
assert_eq(['u' => 1, 'c' => 1, 'k' => 't5'], ['u' => $act['u'], 'c' => $act['c'], 'k' => $act['k']], 'action token claims');
$devIds = array_map(fn($d) => (int)$d['id'], $devs);
assert_true(in_array($act['d'], $devIds, true), 'action token names the device');
$act2 = fw_push_action_verify(json_decode($calls[2]['body'], true)['message']['data']['act']);
assert_true($act2['d'] !== $act['d'], 'each phone gets its own action token');
assert_eq(3, count($calls), 'access token reused for the second phone');

// Uninstalled app: FCM says UNREGISTERED -> the device is forgotten.
$calls = [];
$responses = [
    [404, json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
                                   'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]])],
    [200, '{}'],
];
$oid = fw_push_queue($DB, 1, ['title' => 'Hello', 'body' => 'x']);
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(1, $stats['failed'], 'one invalid token');
assert_eq(1, (int)outbox($DB, $oid)['sent_count'], 'the other phone still got it');
assert_eq(1, count(fw_push_devices($DB, 1)), 'unregistered phone removed');
assert_true(str_contains((string)$DB->query("SELECT last_error FROM push_devices WHERE disabled_at IS NOT NULL")->fetchColumn(), 'UNREGISTERED'),
            'reason kept');

// FCM briefly unavailable for one phone: only that phone is retried, later.
$p3 = make_session($DB, 1);
$tok3 = fake_token('f');
fw_push_bind($DB, 1, $p3, $tok3, UA);
$calls = [];
$responses = [[200, '{}'], [503, '{"error":{"code":503,"status":"UNAVAILABLE","message":"try later"}}']];
$oid = fw_push_queue($DB, 1, ['title' => 'Retry me', 'body' => 'x']);
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(1, $stats['retry'], 'one retry scheduled');
assert_eq(1, (int)outbox($DB, $oid)['sent_count'], 'first phone done');
$retry = $DB->query("SELECT * FROM push_outbox WHERE sent_at IS NULL")->fetchAll();
assert_eq(1, count($retry), 'a retry row for the failed phone');
$rp = json_decode($retry[0]['payload'], true);
assert_eq(1, count($rp['only_devices']), 'retry targets one phone');
assert_eq(1, (int)$retry[0]['attempts'], 'attempt counted');
assert_true(strtotime($retry[0]['send_after'] . ' UTC') > time(), 'retry waits (backoff, UTC clock)');
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(0, $stats['claimed'], 'not sent again before the backoff');
$DB->exec("UPDATE push_outbox SET send_after = UTC_TIMESTAMP() WHERE sent_at IS NULL");
$calls = [];
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(1, $stats['sent'], 'retry delivered');
assert_eq(1, count($calls), 'only the failed phone was sent to again');

// Wrong key / FCM API disabled: nothing is lost, the message waits.
$calls = [];
$responses = [[403, '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"denied"}}']];
$oid = fw_push_queue($DB, 1, ['title' => 'Config', 'body' => 'x']);
$stats = fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(1, count($calls), 'stops after the first configuration error');
$o = outbox($DB, $oid);
assert_true($o['sent_at'] === null && (int)$o['attempts'] === 1, 'message kept for a later retry');
assert_eq(2, count(fw_push_devices($DB, 1)), 'devices are not blamed for a server problem');
$DB->exec("DELETE FROM push_outbox WHERE sent_at IS NULL");

// Expired access token: one refresh, then the send succeeds.
$calls = [];
$responses = [[401, '{"error":{"code":401,"status":"UNAUTHENTICATED"}}'], [200, '{}'], [200, '{}'], [200, '{}']];
$fcm2 = new FwFcmClient(fw_push_service_account(), $transport);
fw_push_queue($DB, 1, ['title' => 'Auth', 'body' => 'x']);
$stats = fw_push_flush($DB, 10, 5.0, $fcm2);
assert_eq(1, $stats['sent'], 'sent after refreshing the access token');
assert_eq(2, count(array_filter($calls, fn($c) => str_contains($c['url'], 'oauth2'))), 'token fetched twice');

// Dismiss: removes notifications, silently (normal priority).
$calls = [];
fw_push_dismiss($DB, 1, ['mail:t5', 'sla:t5', 'bad tag!']);
fw_push_flush($DB, 10, 5.0, $fcm);
$msg = json_decode($calls[0]['body'], true)['message'];
assert_eq('d', $msg['data']['t'], 'dismiss type');
assert_eq('mail:t5,sla:t5', $msg['data']['tags'], 'only valid tags');
assert_eq('NORMAL', $msg['android']['priority'], 'dismiss does not wake the phone');

// Expired messages are not sent.
$oid = fw_push_queue($DB, 1, ['title' => 'Old', 'body' => 'x'], ['ttl' => 60]);
$DB->exec("UPDATE push_outbox SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = $oid");
$calls = [];
fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(0, count($calls), 'expired message skipped');

// A 404 that is not UNREGISTERED (e.g. a wrong project_id) or a key of another
// Firebase project is a server problem: the phones must not be forgotten.
foreach ([
    [404, '{"error":{"code":404,"status":"NOT_FOUND","message":"Requested entity was not found."}}'],
    [403, json_encode(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch',
                                   'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]])],
] as $resp) {
    $calls = [];
    $responses = [$resp];
    $oid = fw_push_queue($DB, 1, ['title' => 'Setup', 'body' => 'x']);
    fw_push_flush($DB, 10, 5.0, $fcm);
    assert_eq(2, count(fw_push_devices($DB, 1)), 'HTTP ' . $resp[0] . ': devices kept');
    assert_eq(1, (int)outbox($DB, $oid)['attempts'], 'HTTP ' . $resp[0] . ': message kept for later');
    $DB->exec("DELETE FROM push_outbox WHERE sent_at IS NULL");
}

// FCM refuses the message itself (not a token problem): dropped, not retried 6 times.
$calls = [];
$responses = [[400, '{"error":{"code":400,"status":"INVALID_ARGUMENT","message":"Message is too big"}}']];
$oid = fw_push_queue($DB, 1, ['title' => 'Too big', 'body' => 'x']);
fw_push_flush($DB, 10, 5.0, $fcm);
$o = outbox($DB, $oid);
assert_true($o['sent_at'] !== null && (int)$o['sent_count'] === 0, 'refused message closed');
assert_true(str_contains((string)$o['last_error'], 'INVALID_ARGUMENT'), 'reason kept');
assert_eq(1, count($calls), 'not sent to the second phone either');
assert_eq(2, count(fw_push_devices($DB, 1)), 'phones kept');

// A retry sends only what is left of the lifetime.
$calls = [];
$oid = fw_push_queue($DB, 1, ['title' => 'Reminder', 'body' => 'x'], ['ttl' => 3600]);
$DB->exec("UPDATE push_outbox SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND) WHERE id = $oid");
fw_push_flush($DB, 10, 5.0, $fcm);
$ttl = (int)json_decode($calls[0]['body'], true)['message']['android']['ttl'];
assert_true($ttl > 0 && $ttl <= 120, 'ttl is what is left (' . $ttl . 's)');

// Long non-Latin text stays inside FCM's 4 KB (bytes, not characters).
$calls = [];
fw_push_queue($DB, 1, ['title' => str_repeat('漢', 140), 'body' => str_repeat('字', 400), 'big' => str_repeat('語', 1200),
                       'sub' => 'me@example.com', 'url' => '/mail/?open=t1'], ['act_key' => 't1', 'act_company' => 1]);
fw_push_flush($DB, 10, 5.0, $fcm);
$data = json_decode($calls[0]['body'], true)['message']['data'];
assert_true(strlen(json_encode($data, JSON_UNESCAPED_UNICODE)) < 4000, 'payload under 4 KB with the action token');
assert_true(mb_strlen($data['title']) > 20, 'title still meaningful');

// The test button sends just its own row, whatever else is waiting.
$calls = [];
$other = fw_push_queue($DB, 1, ['title' => 'Waiting', 'body' => 'x']);
$mine = fw_push_queue($DB, 1, ['title' => 'Test', 'body' => 'x']);
fw_push_flush($DB, 1, 5.0, $fcm, $mine);
assert_true(outbox($DB, $mine)['sent_at'] !== null, 'requested row sent');
assert_true(outbox($DB, $other)['sent_at'] === null, 'other rows left for the worker');
fw_push_flush($DB, 10, 5.0, $fcm);

// A row claimed by a process that died is picked up after 5 minutes, not before.
$calls = [];
$oid = fw_push_queue($DB, 1, ['title' => 'Stuck', 'body' => 'x']);
$DB->exec("UPDATE push_outbox SET claim_token = 'deadbeefdeadbeefdeadbeefdeadbeef', claimed_at = UTC_TIMESTAMP() WHERE id = $oid");
fw_push_flush($DB, 10, 5.0, $fcm);
assert_eq(0, count($calls), 'a live claim is respected');
$DB->exec("UPDATE push_outbox SET claimed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MINUTE) WHERE id = $oid");
fw_push_flush($DB, 10, 5.0, $fcm);
assert_true(outbox($DB, $oid)['sent_at'] !== null, 'a stale claim is taken over and sent');

// fw_notify: bell row + push with nid, company and tag.
$calls = [];
$nid = fw_notify($DB, 1, 1, 'mention', 'Anna mentioned you', '"Task": see this', '/projects/board.php?board_id=1&item=2',
                 ['ch' => 'projects', 'who' => 'Anna Smith']);
fw_push_flush($DB, 10, 5.0, $fcm);
$data = json_decode($calls[0]['body'], true)['message']['data'];
assert_eq((string)$nid, $data['nid'], 'push carries the bell id');
assert_eq('n:' . $nid, $data['tag'], 'default tag');
assert_eq('projects', $data['ch'], 'channel');
assert_eq('Anna Smith', $data['who'], 'avatar name');
assert_eq('/projects/board.php?board_id=1&item=2&fw_co=1', $data['url'], 'url with company');

// ---------------------------------------------------------------- action tokens

$t = fw_push_action_token(1, 1, 't9', 7);
assert_eq('t9', fw_push_action_verify($t)['k'], 'token round-trips');
[$body, $sig] = explode('.', $t);
$forged = fw_push_b64url(json_encode(['u' => 2, 'c' => 1, 'k' => 't9', 'd' => 7, 'x' => time() + 99])) . '.' . $sig;
assert_eq(null, fw_push_action_verify($forged), 'changed claims are rejected');
$expiredBody = fw_push_b64url(json_encode(['u' => 1, 'c' => 1, 'k' => 't9', 'd' => 7, 'x' => time() - 1]));
$expired = $expiredBody . '.' . fw_push_b64url(hash_hmac('sha256', $expiredBody, fw_push_action_key(), true));
assert_eq(null, fw_push_action_verify($expired), 'expired token rejected');
assert_eq(null, fw_push_action_verify('garbage'), 'garbage rejected');

// ---------------------------------------------------------------- new mail

// Per conversation, newest message, replacing older ones in the same thread.
$push = new ReflectionMethod('MailSyncService', 'push');
$push->setAccessible(true);
$calls = [];
$acc = ['account_id' => 9, 'company_id' => 1, 'user_id' => 1, 'email_address' => 'me@example.com'];
$rows = [
    ['email_id' => 103, 'thread_id' => 50, 'sender' => 'ann@example.com', 'sender_name' => 'Ann Lee', 'subject' => 'Re: Quote', 'body_text' => "Thanks!\nSee attached."],
    ['email_id' => 102, 'thread_id' => 51, 'sender' => 'bob@example.com', 'sender_name' => '', 'subject' => '', 'body_text' => ''],
    ['email_id' => 101, 'thread_id' => 50, 'sender' => 'ann@example.com', 'sender_name' => 'Ann Lee', 'subject' => 'Quote', 'body_text' => 'old'],
];
$push->invoke(null, $DB, $acc, $rows);
fw_push_flush($DB, 20, 5.0, $fcm);
$sent = array_map(fn($c) => json_decode($c['body'], true)['message']['data'], $calls);
$tags = array_values(array_unique(array_column($sent, 'tag')));
assert_eq(['mail:t51', 'mail:t50'], $tags, 'one notification per conversation, newest last');
$ann = array_values(array_filter($sent, fn($d) => $d['tag'] === 'mail:t50'))[0];
assert_eq('Ann Lee', $ann['title'], 'sender as title');
assert_eq('Re: Quote', $ann['body'], 'subject');
assert_eq("Re: Quote\nThanks! See attached.", $ann['big'], 'subject and preview when expanded');
assert_eq('me@example.com', $ann['sub'], 'mailbox');
assert_eq('103', $ann['eid'], 'newest message for Reply');
assert_eq('mail', $ann['app'], 'opens in FlowMail');
assert_eq('/mail/?open=t50&fw_co=1', $ann['url'], 'opens the conversation');
assert_eq('t50', fw_push_action_verify($ann['act'])['k'], 'Mark as read / Archive for that conversation');
$bob = array_values(array_filter($sent, fn($d) => $d['tag'] === 'mail:t51'))[0];
assert_eq('(no subject)', $bob['body'], 'empty subject');
assert_eq('bob@example.com', $bob['title'], 'address when there is no name');

// A burst (e.g. a mailbox catching up) becomes one notification.
$calls = [];
$burst = [];
for ($i = 0; $i < MailSyncService::PUSH_MAX + 3; $i++) {
    $burst[] = ['email_id' => 200 + $i, 'thread_id' => 300 + $i, 'sender' => "s$i@example.com", 'sender_name' => "S $i",
                'subject' => "Subject $i", 'body_text' => ''];
}
$push->invoke(null, $DB, $acc, $burst);
fw_push_flush($DB, 20, 5.0, $fcm);
$sent = array_map(fn($c) => json_decode($c['body'], true)['message']['data'], $calls);
assert_eq(['mail:batch:9'], array_values(array_unique(array_column($sent, 'tag'))), 'single summary notification');
assert_eq((MailSyncService::PUSH_MAX + 3) . ' new emails', $sent[0]['title'], 'summary title');

// Nothing left for the end-of-request sender to try against the real Firebase.
$DB->exec("DELETE FROM push_outbox");

echo 'push: OK (' . $GLOBALS['__assert_count'] . " assertions)\n";
