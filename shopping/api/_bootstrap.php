<?php
// /shopping/api/_bootstrap.php — shared setup for every signed-in Shopping JSON endpoint.
//   - signed in (auth_gate also checks the CSRF token on every POST)
//   - the active company and the person's role (ShKit decides what it allows)
//   - the schema is provisioned (ShSchema)
//   - errors: ShProblem → its code, forbidden → 403, anything else logged and 500
//   - writes are rate limited per person

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Sh.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$SH_COMPANY_ID = (int)$_SESSION['company_id'];
$SH_USER_ID = (int)$_SESSION['user_id'];
$SH_ROLE = ShKit::role((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ShProblem) sh_error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    if ($e instanceof ShForbidden) sh_error($e->getMessage(), 403);
    error_log('[shopping/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sh_error('Something went wrong. Please try again.', 500);
});

ShSchema::ensure($DB);

function sh_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sh_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header_remove('Content-Disposition');
    }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sh_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** JSON body (at most 512 KB). */
function sh_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if (strlen((string)$raw) > 524288) sh_error('Request too large', 413);
    $in = json_decode($raw ?: '[]', true);
    if (!is_array($in)) $in = $_POST;
    return $in;
}

function sh_op(): string
{
    $src = sh_is_post() ? (sh_input()['op'] ?? '') : ($_GET['op'] ?? '');
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$src));
}

function sh_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

function sh_post_only(): void
{
    if (!sh_is_post()) sh_error('Use POST for this.', 405);
}

function sh_rate_limit(string $bucket, int $max, int $window): void
{
    global $SH_USER_ID;
    $wait = ShKit::rateLimit($bucket . ':' . $SH_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        sh_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429);
    }
}

if (sh_is_post()) sh_rate_limit('sh-write', 300, 60);
