<?php
// /payroll/api/_bootstrap.php — shared setup for every Payroll JSON endpoint.
//   - signed in (auth_gate also checks the CSRF token on every POST)
//   - Payroll is for admins and bookkeepers of the active company
//   - the schema is provisioned (PaySchema)
//   - errors: PayInvalid → 422 with per-field messages, PayProblem → 400/404/409,
//     posting-engine messages → 409, anything else logged and answered 500
//   - writes are rate limited per person

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Pay.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$PAY_COMPANY_ID = (int)$_SESSION['company_id'];
$PAY_USER_ID = (int)$_SESSION['user_id'];
$PAY_ROLE = strtolower((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof PayInvalid) pay_error($e->getMessage(), 422, ['errors' => $e->errors]);
    if ($e instanceof PayProblem) {
        $m = strtolower($e->getMessage());
        pay_error($e->getMessage(), str_contains($m, 'no longer exist') || str_contains($m, 'not in this pay run') ? 404 : 409);
    }
    if (in_array(get_class($e), ['Exception', 'RuntimeException'], true)) pay_error($e->getMessage(), 409);
    error_log('[payroll/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    pay_error('Something went wrong. Please try again.', 500);
});

if (!PayKit::canUse($PAY_ROLE)) pay_error('Payroll is open to admins and bookkeepers only.', 403);
PaySchema::ensure($DB);

function pay_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pay_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pay_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** JSON body, at most 512 KB. */
function pay_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if (strlen((string)$raw) > 524288) pay_error('Request too large', 413);
    $in = json_decode($raw ?: '[]', true);
    if (!is_array($in)) $in = $_POST;
    return $in;
}

function pay_op(): string
{
    $src = pay_is_post() ? (pay_input()['op'] ?? '') : ($_GET['op'] ?? '');
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$src));
}

function pay_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

function pay_require_admin(): void
{
    global $PAY_ROLE;
    if (!PayKit::canConfigure($PAY_ROLE)) pay_error('Only an admin can change payroll settings.', 403);
}

function pay_rate_limit(string $bucket, int $max, int $window): void
{
    global $PAY_USER_ID;
    $wait = PayKit::rateLimit($bucket . ':' . $PAY_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        pay_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429);
    }
}

if (pay_is_post()) pay_rate_limit('pay-write', 180, 60);
