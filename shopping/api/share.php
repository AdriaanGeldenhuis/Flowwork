<?php
// /shopping/api/share.php — a shared list, for someone with the link (no Flowwork account).
//   GET  op=get&t=                   the list (no money)
//   GET  op=version&t=               its change stamp
//   POST op=toggle {t, id, bought, name}   tick off (when the link allows ticking)
//   POST op=add {t, text, name}           add items (when the link allows adding)
// The link's token is the key. POSTs also carry the CSRF token of the share
// page (the visitor's own session), and everything is rate limited by address.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/Sh.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) Csrf::validate();
session_write_close();

$fail = function (string $msg, int $code) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
set_exception_handler(function (Throwable $e) use ($fail) {
    if ($e instanceof ShProblem) $fail($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    if ($e instanceof ShForbidden) $fail($e->getMessage(), 403);
    error_log('[shopping/share] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $fail('Something went wrong. Please try again.', 500);
});

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (($wait = ShKit::rateLimit('share:' . ($post ? 'w:' : 'r:') . $ip, $post ? 120 : 400, 60)) > 0) {
    header('Retry-After: ' . $wait);
    $fail('Too many requests — wait a moment and try again.', 429);
}
ShSchema::ensure($DB);

$in = $post ? (json_decode((string)file_get_contents('php://input', false, null, 0, 65537), true) ?: []) : $_GET;
$share = ShShare::resolve($DB, (string)($in['t'] ?? ''));
if (!$share) $fail('This link has run out or was stopped. Ask whoever sent it for a new one.', 404);
$op = preg_replace('/[^a-z]/', '', (string)($in['op'] ?? 'get'));
$guest = (string)($in['name'] ?? '');

$ok = function (array $data) {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

switch ($op) {
    case 'get':
        $ok(ShShare::view($DB, $share));
    case 'version':
        $ok(['version' => ShLists::version($DB, (int)$share['list']['id'])]);
    case 'toggle':
        if (!$post) $fail('Use POST for this.', 405);
        ShShare::toggle($DB, $share, is_scalar($in['id'] ?? null) ? (int)$in['id'] : 0, !empty($in['bought']), $guest);
        $ok(ShShare::view($DB, $share));
    case 'add':
        if (!$post) $fail('Use POST for this.', 405);
        ShShare::add($DB, $share, mb_substr((string)($in['text'] ?? ''), 0, 4000), $guest);
        $ok(ShShare::view($DB, $share));
}
$fail('Unknown request.', 400);
