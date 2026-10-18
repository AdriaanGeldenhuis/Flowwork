<?php
// /projects/api/_bootstrap.php — shared setup for every Projects JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-TOKEN on every POST)
//   - schema self-provisioning
//   - releases the session lock early so slow calls never block other tabs
//   - JSON / input helpers, rate limiting, and $PR (ProjectRepo for this user)

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$PR_COMPANY_ID = (int)$_SESSION['company_id'];
$PR_USER_ID    = (int)$_SESSION['user_id'];
$PR_ROLE       = (string)($_SESSION['role'] ?? 'viewer');
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ProjectForbidden) pr_error($e->getMessage(), 403);
    if ($e instanceof ProjectUserError) pr_error($e->getMessage(), str_contains($e->getMessage(), 'not found') ? 404 : 400);
    error_log('[projects/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    pr_error('Something went wrong. Please try again.', 500);
});

ProjectSchema::ensure($DB);
$PR = new ProjectRepo($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE);

function pr_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pr_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pr_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') pr_error('Method not allowed', 405);
}

function pr_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** JSON body (application/json) or form fields. */
function pr_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1024 * 1024) pr_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) pr_error('Invalid JSON');
    } else {
        $in = $_POST;
    }
    return $in;
}

function pr_str(array $in, string $key, int $max = 1000): string
{
    $v = $in[$key] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

function pr_int(array $in, string $key): int
{
    $v = $in[$key] ?? 0;
    return is_scalar($v) ? (int)$v : 0;
}

/** A list of positive ints from input (array or comma string). @return int[] */
function pr_ids($v, int $max = 2000): array
{
    if (is_string($v)) $v = explode(',', $v);
    if (!is_array($v)) return [];
    return array_slice(array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0))), 0, $max);
}

function pr_rate_limit(string $bucket, int $max, int $window): void
{
    global $PR_USER_ID;
    $wait = ProjectRepo::rateLimit($bucket . ':' . $PR_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        pr_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
    }
}

/** Remove stored files after their rows are gone (best effort). */
function pr_unlink_files(array $paths): void
{
    foreach ($paths as $p) {
        if ($full = ProjectPaths::resolveStored((string)$p)) @unlink($full);
    }
}
