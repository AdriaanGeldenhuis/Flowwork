<?php
// POST /mail/api/push_action.php {"token": "...", "action": "read" | "archive"}
//
// The "Mark as read" and "Archive" buttons on a FlowMail phone notification.
// The Android app calls this without a session cookie: it is authorised by
// the token in the notification, which the server signed for one conversation,
// one user and one device (includes/push.php, fw_push_action_token). There is
// no cookie to ride on, so CSRF does not apply; the token is the credential.
// It stops working when it expires (7 days) or the device is signed out.

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/push.php';
require_once __DIR__ . '/../lib/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function push_action_reply(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    push_action_reply(['ok' => false, 'error' => 'Method not allowed'], 405);
}
$raw = (string)file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode($raw, true);
if (!is_array($in)) push_action_reply(['ok' => false, 'error' => 'Bad request'], 400);

$action = (string)($in['action'] ?? '');
if (!in_array($action, ['read', 'archive'], true)) {
    push_action_reply(['ok' => false, 'error' => 'Unknown action'], 400);
}

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
if (RateLimiter::hit('push_action_ip:' . $ip, 60, 300) > 0) {
    push_action_reply(['ok' => false, 'error' => 'Too many requests'], 429);
}

$claims = fw_push_action_verify((string)($in['token'] ?? ''));
if ($claims === null) push_action_reply(['ok' => false, 'error' => 'This notification has expired'], 401);
if (RateLimiter::hit('push_action_device:' . $claims['d'], 30, 300) > 0) {
    push_action_reply(['ok' => false, 'error' => 'Too many requests'], 429);
}

try {
    $DB->exec("SET time_zone = '" . date('P') . "'");
    MailSchema::ensure($DB);

    // Still the same, active user on a device that is still signed in?
    $st = $DB->prepare("SELECT u.status FROM users u WHERE u.id = ?");
    $st->execute([$claims['u']]);
    if ($st->fetchColumn() !== 'active') push_action_reply(['ok' => false, 'error' => 'Not allowed'], 403);
    $live = array_filter(fw_push_devices($DB, $claims['u'], [$claims['d']]), fn($d) => (int)$d['id'] === $claims['d']);
    if (!$live) push_action_reply(['ok' => false, 'error' => 'This device is signed out'], 403);

    $rows = MailRepo::emailsForKeys($DB, $claims['c'], $claims['u'], [$claims['k']], 'visible');
    $n = 0;
    if ($rows) {
        $n = MailActions::apply($DB, $rows, $action);
    }
    // Clear it on the user's other phones as well (already gone on this one).
    fw_push_dismiss($DB, $claims['u'], ['mail:' . $claims['k'], 'sla:' . $claims['k']]);
    push_action_reply(['ok' => true, 'affected' => $n]);
} catch (Throwable $e) {
    error_log('[mail/push_action] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    push_action_reply(['ok' => false, 'error' => 'Something went wrong'], 500);
}
