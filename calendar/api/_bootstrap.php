<?php
// /calendar/api/_bootstrap.php — shared setup for every Calendar JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-TOKEN on POST)
//   - schema self-provisioning
//   - releases the session lock early so slow calls never block other tabs
//   - JSON / input helpers, rate limiting, and $CAL (CalendarRepo for this user)

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/_payload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$CAL_COMPANY_ID = (int)$_SESSION['company_id'];
$CAL_USER_ID    = (int)$_SESSION['user_id'];
$CAL_ROLE       = (string)($_SESSION['role'] ?? 'viewer');
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof CalendarUserError) {
        cal_error($e->getMessage(), 400);
    }
    error_log('[calendar/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    cal_error('Something went wrong. Please try again.', 500);
});

CalendarSchema::ensure($DB);
$CAL = new CalendarRepo($DB, $CAL_COMPANY_ID, $CAL_USER_ID, $CAL_ROLE);

function cal_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cal_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cal_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') cal_error('Method not allowed', 405);
}

/** JSON body (application/json) or form fields. */
function cal_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 1024 * 1024) cal_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) cal_error('Invalid JSON');
    } else {
        $in = $_POST;
    }
    return $in;
}

function cal_str(array $in, string $key, int $max = 1000): string
{
    $v = $in[$key] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

/** 'Y-m-d' from input, or null. */
function cal_date($v): ?string
{
    $v = is_scalar($v) ? substr(trim((string)$v), 0, 10) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return $v;
}

function cal_rate_limit(string $bucket, int $max, int $window): void
{
    global $CAL_USER_ID;
    $wait = CalendarRepo::rateLimit($bucket . ':' . $CAL_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        cal_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
    }
}
