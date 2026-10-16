<?php
// /account/api/sessions.php — the signed-in user's devices.
//   GET                                   → { devices: [...] }
//   POST {"action":"revoke","id":123}     → sign out one other device
//   POST {"action":"revoke_others"}       → sign out every device except this one
//   POST {"action":"test_push"}           → send a test notification to the user's phones now
// POST needs the X-CSRF-Token header (checked by auth_gate.php).
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$userId = (int)$_SESSION['user_id'];
$currentId = (int)($_SESSION['auth_sid'] ?? 0);

function account_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function account_devices(PDO $db, int $userId, int $currentId): array
{
    $push = fw_push_session_map($db, $userId); // devices that receive phone notifications
    return array_map(fn($d) => [
        'id'          => (int)$d['id'],
        'label'       => (string)$d['device_label'],
        'is_app'      => (int)$d['is_app'] === 1,
        'remembered'  => (int)$d['remembered'] === 1,
        'ip'          => (string)$d['ip'],
        'created_ago' => max(0, (int)$d['created_ago']),
        'seen_ago'    => max(0, (int)$d['seen_ago']),
        'current'     => (int)$d['id'] === $currentId,
        'push'        => isset($push[(int)$d['id']]),
    ], fw_auth_devices($db, $userId));
}

function account_payload(PDO $db, int $userId, int $currentId): array
{
    return ['ok' => true, 'devices' => account_devices($db, $userId, $currentId),
            'push_ready' => fw_push_service_account() !== null];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    account_json(account_payload($DB, $userId, $currentId));
}
if ($method !== 'POST') account_json(['ok' => false, 'error' => 'Method not allowed'], 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = (string)($in['action'] ?? '');

if ($action === 'revoke') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) account_json(['ok' => false, 'error' => 'Choose a device'], 400);
    if ($id === $currentId) account_json(['ok' => false, 'error' => 'Use Sign out to sign out of this device'], 400);
    if (!fw_auth_revoke($DB, $userId, $id, 'signed_out')) {
        account_json(['ok' => false, 'error' => 'That device is already signed out'], 404);
    }
    $count = 1;
} elseif ($action === 'revoke_others') {
    $count = fw_auth_revoke_all($DB, $userId, 'signed_out', $currentId);
} elseif ($action === 'test_push') {
    account_test_push($DB, $userId);
} else {
    account_json(['ok' => false, 'error' => 'Unknown action'], 400);
}

try {
    $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip, created_at)
                  VALUES (?, ?, 'devices_signed_out', ?, ?, NOW())")
       ->execute([(int)$_SESSION['company_id'], $userId, json_encode(['action' => $action, 'count' => $count]), fw_auth_ip()]);
} catch (Throwable $e) {
    error_log('[account] audit: ' . $e->getMessage());
}

account_json(['signed_out' => $count] + account_payload($DB, $userId, $currentId));

/** Send a test push to every phone of this user right away and report what happened. */
function account_test_push(PDO $db, int $userId): void
{
    if (fw_push_service_account() === null) {
        account_json(['ok' => false, 'error' => "Phone notifications aren't set up on the server yet (the Firebase key is missing)."], 503);
    }
    $wait = 15 - (time() - (int)($_SESSION['push_test_at'] ?? 0));
    if ($wait > 0) account_json(['ok' => false, 'error' => 'Please wait ' . $wait . ' seconds before sending another test.'], 429);
    $_SESSION['push_test_at'] = time();

    $devices = count(fw_push_devices($db, $userId));
    if ($devices === 0) {
        account_json(['ok' => false, 'error' => 'No phone is ready yet. Open the Flowwork app on your phone, sign in and allow notifications.'], 404);
    }
    $id = fw_push_queue($db, $userId, [
        'ch' => 'general', 'grp' => 'general', 'tag' => 'test',
        'title' => 'Notifications are working',
        'body' => "This test came from Signed-in devices. New email, event reminders and mentions arrive like this.",
        'url' => '/account/',
    ], ['ttl' => 600]);
    fw_push_flush($db, 20, 15.0);

    $st = $db->prepare("SELECT sent_at, sent_count, last_error FROM push_outbox WHERE id = ?");
    $st->execute([(int)$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $sent = (int)($row['sent_count'] ?? 0);
    if ($sent > 0) {
        account_json(['ok' => true, 'sent' => $sent, 'devices' => $devices]);
    }
    error_log('[account] test push not delivered: ' . ($row['last_error'] ?? 'unknown'));
    account_json(['ok' => false, 'error' => 'Firebase did not accept the notification. It will be retried; check the server log ([push]) if it never arrives.'], 502);
}
