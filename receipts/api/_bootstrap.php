<?php
// /receipts/api/_bootstrap.php — shared setup for every Receipts JSON endpoint.
//   - signed in (auth_gate also checks the CSRF token on every POST)
//   - the active company and the person's role (RcKit decides what it allows)
//   - the schema is provisioned (RcSchema)
//   - errors: RcProblem → its code, RcBlocked → 409 with the checks,
//     Finance's messages → 422 / 409, forbidden → 403, anything else logged and 500
//   - writes are rate limited per person

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Rc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$RC_COMPANY_ID = (int)$_SESSION['company_id'];
$RC_USER_ID = (int)$_SESSION['user_id'];
$RC_ROLE = RcKit::role((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof RcBlocked) rc_error($e->getMessage(), 409, ['checks' => $e->checks]);
    if ($e instanceof RcProblem) rc_error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    if ($e instanceof RcForbidden || $e instanceof FinForbidden) rc_error($e->getMessage(), 403);
    if ($e instanceof FinUserError) rc_error($e->getMessage(), $e->getCode() === 409 ? 409 : 422);
    // The posting engine speaks in plain exceptions ("Cannot post AP bill to locked period (…)").
    if (in_array(get_class($e), ['Exception', 'RuntimeException'], true)) rc_error($e->getMessage(), 409);
    error_log('[receipts/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    rc_error('Something went wrong. Please try again.', 500);
});

RcSchema::ensure($DB);

function rc_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rc_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header_remove('Content-Disposition');
    }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rc_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** JSON body (at most 1 MB), or the form fields of a multipart POST. */
function rc_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) return $in = $_POST;
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen((string)$raw) > 1048576) rc_error('Request too large', 413);
    $in = json_decode($raw ?: '[]', true);
    if (!is_array($in)) $in = $_POST;
    return $in;
}

function rc_op(): string
{
    $src = rc_is_post() ? (rc_input()['op'] ?? '') : ($_GET['op'] ?? '');
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$src));
}

function rc_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

function rc_rate_limit(string $bucket, int $max, int $window): void
{
    global $RC_USER_ID;
    $wait = RcKit::rateLimit($bucket . ':' . $RC_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        rc_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429);
    }
}

if (rc_is_post()) rc_rate_limit('rc-write', 240, 60);
