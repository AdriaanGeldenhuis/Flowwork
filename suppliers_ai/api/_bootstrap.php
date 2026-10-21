<?php
// /suppliers_ai/api/_bootstrap.php — shared setup for every Suppliers AI JSON endpoint.
//   - signed in (auth_gate also checks the CSRF token on every POST)
//   - the active company and the person's role (SaKit decides what it allows)
//   - the schema is provisioned (SaSchema)
//   - errors: SaProblem → its code, forbidden → 403, anything else logged and 500
//   - writes are rate limited per person

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Sa.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$SA_COMPANY_ID = (int)$_SESSION['company_id'];
$SA_USER_ID = (int)$_SESSION['user_id'];
$SA_ROLE = SaKit::role((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof SaProblem) sa_error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    if ($e instanceof SaForbidden) sa_error($e->getMessage(), 403);
    error_log('[suppliers_ai/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sa_error('Something went wrong. Please try again.', 500);
});

// The database's clock in PHP's time zone, as Mail does: replies are found by comparing Mail's times with ours
$DB->exec("SET time_zone = '" . date('P') . "'");
SaSchema::ensure($DB);

function sa_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sa_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sa_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** JSON body (at most 256 KB). */
function sa_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if (strlen((string)$raw) > 262144) sa_error('Request too large', 413);
    $in = json_decode($raw ?: '[]', true);
    if (!is_array($in)) $in = $_POST;
    return $in;
}

function sa_op(): string
{
    $src = sa_is_post() ? (sa_input()['op'] ?? '') : ($_GET['op'] ?? '');
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$src));
}

function sa_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

function sa_post_only(): void
{
    if (!sa_is_post()) sa_error('Use POST for this.', 405);
}

function sa_rate_limit(string $bucket, int $max, int $window): void
{
    global $SA_USER_ID;
    $wait = SaKit::rateLimit($bucket . ':' . $SA_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        sa_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429);
    }
}

if (sa_is_post()) sa_rate_limit('sa-write', 240, 60);
