<?php
// /account/api/sessions.php — the signed-in user's devices.
//   GET                                   → { devices: [...] }
//   POST {"action":"revoke","id":123}     → sign out one other device
//   POST {"action":"revoke_others"}       → sign out every device except this one
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
    return array_map(fn($d) => [
        'id'          => (int)$d['id'],
        'label'       => (string)$d['device_label'],
        'is_app'      => (int)$d['is_app'] === 1,
        'remembered'  => (int)$d['remembered'] === 1,
        'ip'          => (string)$d['ip'],
        'created_ago' => max(0, (int)$d['created_ago']),
        'seen_ago'    => max(0, (int)$d['seen_ago']),
        'current'     => (int)$d['id'] === $currentId,
    ], fw_auth_devices($db, $userId));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    account_json(['ok' => true, 'devices' => account_devices($DB, $userId, $currentId)]);
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

account_json(['ok' => true, 'signed_out' => $count, 'devices' => account_devices($DB, $userId, $currentId)]);
