<?php
/**
 * The web tick (notifications/api/tick.php) over HTTP with PHP's built-in
 * server and the test database: key check, quick answer, calendar reminder
 * sent with bell + phone notification, project due-date reminder (from 07:00),
 * overlap/rate guard, run summary.
 *
 *   php tests/notifications/test_tick_http.php
 *
 * Needs the test database (tests/db/setup.sh).
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
require FLOWWORK_ROOT . '/projects/lib/autoload.php';

$cronKey = bin2hex(random_bytes(24));
$stateFile = FLOWWORK_ROOT . '/storage/cron/tick.json';
@unlink($stateFile);

// ---------------------------------------------------------------- fixtures

foreach (['push_outbox', 'push_devices', 'notifications'] as $t) $DB->exec("DROP TABLE IF EXISTS `$t`");
fw_push_install($DB);
fw_notify_install($DB);
$DB->exec("DELETE FROM user_sessions");
// A phone for user 1, so the reminder is also queued as a push.
fw_auth_db($DB, fn() => $DB->exec("INSERT INTO user_sessions (user_id, device_label, is_app, expires_at)
                                   VALUES (1, 'test', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))"));
fw_push_bind($DB, 1, (int)$DB->lastInsertId(), str_repeat('t', 22) . ':APA91b' . str_repeat('tx_-', 40), 'FlowWorkApp/1.2');

$DB->exec("DELETE FROM calendar_event_reminders");
// Event times are wall-clock times in the company's calendar time zone (Africa/Johannesburg by default).
$calTz = new DateTimeZone('Africa/Johannesburg');
$eventStart = (new DateTimeImmutable('+10 minutes', $calTz))->format('Y-m-d H:i:s');
$DB->prepare("INSERT INTO calendar_events (company_id, calendar_id, title, start_datetime, end_datetime, created_by)
              VALUES (1, 1, 'Site visit', ?, ?, 1)")
   ->execute([$eventStart, (new DateTimeImmutable('+60 minutes', $calTz))->format('Y-m-d H:i:s')]);
$eventId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel) VALUES (?, 1, 15, 'in_app')")
   ->execute([$eventId]);
$reminderId = (int)$DB->lastInsertId();

// A project item due today with user 1 on it. Reminders go out once per item per day, from 07:00.
ProjectSchema::ensure($DB);
$today = date('Y-m-d');
$remindNow = (int)date('G') >= 7;
$DB->exec("INSERT INTO projects (company_id, name, status, created_by) VALUES (1, 'TickTest project', 'active', 1)");
$projectId = (int)$DB->lastInsertId();
$DB->exec("INSERT INTO project_boards (company_id, project_id, title) VALUES (1, $projectId, 'TickTest board')");
$boardId = (int)$DB->lastInsertId();
$DB->exec("INSERT INTO board_groups (board_id, name) VALUES ($boardId, 'TickTest group')");
$groupId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, due_date, progress, created_by)
              VALUES (?, 1, ?, 'TickTest item', ?, 0, 1)")->execute([$boardId, $groupId, $today]);
$itemId = (int)$DB->lastInsertId();
$DB->exec("INSERT INTO board_item_people (item_id, user_id, company_id, board_id) VALUES ($itemId, 1, 1, $boardId)");
register_shutdown_function(function () use ($DB, $projectId, $boardId, $itemId) {
    $DB->exec("DELETE FROM board_item_people WHERE item_id = $itemId");
    $DB->exec("DELETE FROM board_items WHERE id = $itemId");
    $DB->exec("DELETE FROM board_groups WHERE board_id = $boardId");
    $DB->exec("DELETE FROM project_boards WHERE board_id = $boardId");
    $DB->exec("DELETE FROM projects WHERE project_id = $projectId");
});

// ---------------------------------------------------------------- server

$port = 19000 + random_int(0, 999);
$env = array_merge(getenv(), [
    'APP_ENV' => 'testing', 'FCM_SERVICE_ACCOUNT_FILE' => $saFile, 'FW_CRON_KEY' => $cronKey,
    'DB_HOST' => getenv('DB_HOST'), 'DB_NAME' => getenv('DB_NAME'), 'DB_USER' => getenv('DB_USER'), 'DB_PASS' => getenv('DB_PASS'),
]);
$log = tempnam(sys_get_temp_dir(), 'fwsrv');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', FLOWWORK_ROOT], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
                    $pipes, FLOWWORK_ROOT, $env);
register_shutdown_function(function () use ($server, $saFile, $log, $stateFile) {
    proc_terminate($server);
    @unlink($saFile);
    @unlink($log);
    @unlink($stateFile);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$url = 'http://127.0.0.1:' . $port . '/notifications/api/tick.php';

function get(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $headers]);
    $t = microtime(true);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($body, true), 'body' => $body, 'secs' => microtime(true) - $t];
}

function wait_for(callable $done, float $seconds = 30): bool
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        if ($done()) return true;
        usleep(200000);
    }
    return false;
}

// ---------------------------------------------------------------- tests

$r = get($url);
assert_eq(403, $r['code'], 'no key: forbidden');
$r = get($url . '?key=' . str_repeat('x', 48));
assert_eq(403, $r['code'], 'wrong key: forbidden');
$r = get($url . '?key[]=' . $cronKey);
assert_eq(403, $r['code'], 'array key rejected');
assert_true(!str_contains($r['body'], 'Warning'), 'no PHP warning');

$r = get($url . '?key=' . $cronKey);
assert_eq(200, $r['code'], 'right key: accepted');
assert_true($r['json']['ok'] === true && isset($r['json']['started']), 'answers ok + started');
assert_true($r['secs'] < 5, 'answers quickly (' . round($r['secs'], 2) . ' s)');

$sent = wait_for(fn() => $DB->query("SELECT sent_at FROM calendar_event_reminders WHERE id = $reminderId")->fetchColumn() !== null);
assert_true($sent, 'calendar reminder sent by the tick');
$n = $DB->query("SELECT * FROM notifications WHERE type = 'calendar_reminder' ORDER BY id DESC LIMIT 1")->fetch();
assert_true((bool)$n, 'bell notification stored');
assert_eq('Site visit', $n['title'], 'reminder title');
assert_eq('/calendar/?event=' . $eventId . '&date=' . substr($eventStart, 0, 10), $n['link'], 'reminder link');
$push = $DB->query("SELECT payload FROM push_outbox WHERE payload LIKE '%cal:r$reminderId%'")->fetchColumn();
assert_true((bool)$push, 'phone notification queued for the reminder');

assert_true(wait_for(fn() => is_array(json_decode((string)@file_get_contents($stateFile), true)['last'] ?? null)), 'run summary written');
$state = json_decode((string)file_get_contents($stateFile), true);
assert_eq(1, $state['last']['jobs']['reminders'], 'summary: 1 reminder');
assert_true(is_array($state['last']['jobs']['mail']), 'summary: mail sync ran');
assert_true(isset($state['last']['jobs']['reply_reminders']), 'summary: reply reminders ran');

$dueQuery = $DB->prepare("SELECT * FROM notifications WHERE type = 'project_due' AND user_id = 1 AND link = ?");
$dueQuery->execute([ProjectLink::itemUrl($boardId, $itemId)]);
$due = $dueQuery->fetchAll();
if ($remindNow) {
    assert_true($state['last']['jobs']['project_reminders'] >= 1, 'summary: project reminders sent');
    assert_eq(1, count($due), 'project due-date reminder stored');
    assert_eq('Due today: TickTest item', $due[0]['title'], 'project reminder title');
    assert_eq('TickTest board · TickTest project', $due[0]['message'], 'project reminder text');
    assert_eq($today, $DB->query("SELECT reminded_on FROM board_items WHERE id = $itemId")->fetchColumn(), 'item marked as reminded today');
    $push = $DB->query("SELECT payload FROM push_outbox WHERE payload LIKE '%TickTest item%'")->fetchColumn();
    assert_true((bool)$push, 'phone notification queued for the project reminder');
    assert_eq('projects', json_decode($push, true)['data']['ch'], 'project reminder uses the projects channel');
} else {
    assert_eq(0, $state['last']['jobs']['project_reminders'], 'summary: no project reminders before 07:00');
    assert_eq(0, count($due), 'no project reminder before 07:00');
}

$r = get($url . '?key=' . $cronKey);
assert_eq(200, $r['code'], 'second call ok');
assert_true(str_contains((string)($r['json']['skipped'] ?? ''), '20 seconds'), 'not run twice within 20 s');
assert_eq(1, $r['json']['last']['jobs']['reminders'], 'reports the previous run');

$state['started'] = 0;
$state['last'] = null;
file_put_contents($stateFile, json_encode($state));
$r = get($url, ['X-Cron-Key: ' . $cronKey]);
assert_true($r['json']['ok'] && isset($r['json']['started']), 'key also accepted as X-Cron-Key header');
assert_true(wait_for(fn() => is_array(json_decode((string)@file_get_contents($stateFile), true)['last'] ?? null)),
            'second run finished');
$state2 = json_decode((string)file_get_contents($stateFile), true);
assert_eq(0, $state2['last']['jobs']['reminders'], 'a reminder is sent only once');
assert_eq(0, $state2['last']['jobs']['project_reminders'], 'a project reminder is sent once a day');
$dueQuery->execute([ProjectLink::itemUrl($boardId, $itemId)]);
assert_eq($remindNow ? 1 : 0, count($dueQuery->fetchAll()), 'no second project reminder');
assert_true(!isset($state2['last']['jobs']['reply_reminders']), 'reply reminders only every 15 minutes');

$DB->exec("DELETE FROM push_outbox");
echo 'tick http: OK (' . $GLOBALS['__assert_count'] . " assertions)\n";
