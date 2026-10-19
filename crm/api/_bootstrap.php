<?php
// /crm/api/_bootstrap.php — shared setup for every CRM JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-Token on every POST)
//   - schema self-provisioning (CrmSchema)
//   - roles: viewers read only; settings, merges and exports need an admin
//   - releases the session lock early so slow calls never block other tabs
//   - JSON / input helpers and a small per-user rate limiter

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$CRM_COMPANY_ID = (int)$_SESSION['company_id'];
$CRM_USER_ID    = (int)$_SESSION['user_id'];
$CRM_ROLE       = strtolower((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof CrmForbidden) crm_error($e->getMessage(), 403);
    if ($e instanceof CrmUserError) crm_error($e->getMessage(), str_contains(strtolower($e->getMessage()), 'not found') || str_contains(strtolower($e->getMessage()), 'no longer exist') ? 404 : 400);
    error_log('[crm/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    crm_error('Something went wrong. Please try again.', 500);
});

CrmSchema::ensure($DB);

function crm_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function crm_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function crm_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function crm_require_post(): void
{
    if (!crm_is_post()) crm_error('Method not allowed', 405);
}

function crm_can_write(): bool
{
    global $CRM_ROLE;
    return Crm::canWrite($CRM_ROLE);
}

function crm_is_admin(): bool
{
    global $CRM_ROLE;
    return Crm::isAdmin($CRM_ROLE);
}

function crm_require_writer(): void
{
    if (!crm_can_write()) throw new CrmForbidden('You have read-only access to the CRM.');
}

function crm_require_admin(): void
{
    if (!crm_is_admin()) throw new CrmForbidden('Only an administrator can change this.');
}

/** JSON body (application/json) or form fields. */
function crm_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1024 * 1024) crm_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) crm_error('Invalid JSON');
    } else {
        $in = $_POST;
        // multipart forms send nested data as a JSON string in "data"
        if (isset($in['data']) && is_string($in['data'])) {
            $d = json_decode($in['data'], true);
            if (is_array($d)) $in = $d + $in;
        }
    }
    return $in;
}

function crm_op(): string
{
    return preg_replace('/[^a-z_]/', '', strtolower((string)(crm_input()['op'] ?? $_GET['op'] ?? '')));
}

function crm_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

/** Per-user sliding window limit (file based, shared-host friendly). */
function crm_rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    global $CRM_USER_ID;
    $dir = dirname(__DIR__, 2) . '/storage/crm/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $file = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . $CRM_USER_ID . '.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return;
    try {
        flock($fh, LOCK_EX);
        $now = time();
        $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
        $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
        if (count($hits) >= $max) {
            $wait = max(1, $windowSeconds - ($now - min($hits)));
            header('Retry-After: ' . $wait);
            crm_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
        }
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
