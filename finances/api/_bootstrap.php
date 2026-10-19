<?php
// /finances/api/_bootstrap.php — shared setup for every Finance JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-Token on every POST)
//   - schema self-provisioning (FinSchema) and the company's finance
//     foundation (tax codes, chart, mappings — FinanceBootstrap)
//   - roles: viewers read; bookkeepers and admins capture, post and file;
//     company settings, unlocks and year-end need an admin; members only read
//     when the company allows it
//   - releases the session lock early so slow reports never block other tabs
//   - JSON / input helpers and a small per-user rate limiter

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Fin.php';
require_once __DIR__ . '/../lib/FinanceBootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$FIN_COMPANY_ID = (int)$_SESSION['company_id'];
$FIN_USER_ID    = (int)$_SESSION['user_id'];
$FIN_ROLE       = strtolower((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof FinForbidden) fin_error($e->getMessage(), 403);
    if ($e instanceof FinUserError) {
        $m = strtolower($e->getMessage());
        fin_error($e->getMessage(), str_contains($m, 'not found') || str_contains($m, 'no longer exist') ? 404 : ($e->getCode() === 409 ? 409 : 400));
    }
    // The posting engine raises plain Exceptions for business rules (locked
    // period, unbalanced journal, unknown account): those messages are meant
    // for people. Anything else (SQL, engine errors) is logged, never shown.
    if (get_class($e) === 'Exception' || (get_class($e) === 'RuntimeException')) {
        fin_error($e->getMessage(), 409);
    }
    error_log('[finance/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fin_error('Something went wrong. Please try again.', 500);
});

FinSchema::ensure($DB);
Audit::setPdo($DB);
$FIN_CONFIG = Fin::config($DB, $FIN_COMPANY_ID);
if (!Fin::canSee($FIN_ROLE, $FIN_CONFIG)) fin_error('You do not have access to Finance.', 403);

function fin_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fin_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fin_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function fin_require_post(): void
{
    if (!fin_is_post()) fin_error('Method not allowed', 405);
}

function fin_can_write(): bool
{
    global $FIN_ROLE;
    return Fin::canWrite($FIN_ROLE);
}

function fin_is_admin(): bool
{
    global $FIN_ROLE;
    return Fin::isAdmin($FIN_ROLE);
}

function fin_require_writer(): void
{
    if (!fin_can_write()) throw new FinForbidden('You have read-only access to Finance.');
}

function fin_require_admin(): void
{
    if (!fin_is_admin()) throw new FinForbidden('Only an administrator can do this.');
}

/** A part of Finance the company switched off answers like it is not there. */
function fin_require_module(string $module): void
{
    global $FIN_CONFIG;
    if (empty($FIN_CONFIG['modules'][$module])) fin_error('This part of Finance is switched off for your company.', 404);
}

/** JSON body (application/json) or form fields (multipart sends nested data as JSON in "data"). */
function fin_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1024 * 1024) fin_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) fin_error('Invalid JSON');
    } else {
        $in = $_POST;
        if (isset($in['data']) && is_string($in['data'])) {
            $d = json_decode($in['data'], true);
            if (is_array($d)) $in = $d + $in;
        }
    }
    return $in;
}

function fin_op(): string
{
    return preg_replace('/[^a-z_]/', '', strtolower((string)(fin_input()['op'] ?? $_GET['op'] ?? '')));
}

function fin_int($v): int
{
    return is_scalar($v) ? (int)$v : 0;
}

function fin_str($v, int $max = 255): string
{
    return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : '';
}

/** A query/body parameter from GET (reads) or the body (writes). */
function fin_param(string $key, $default = null)
{
    $in = fin_is_post() ? fin_input() : $_GET;
    return $in[$key] ?? $default;
}

/** Per-user sliding window limit (file based, shared-host friendly). */
function fin_rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    global $FIN_USER_ID;
    $dir = FinSchema::storageDir();
    if ($dir === null) return;
    $dir .= '/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $file = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . $FIN_USER_ID . '.json';
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
            fin_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
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

/**
 * "Save, then approve / post" in one request: the save is kept even when the
 * next step is refused, and the answer says so (saved_id) so the screen keeps
 * editing that draft instead of capturing it a second time.
 */
function fin_then(int $savedId, callable $step): void
{
    try {
        $step();
    } catch (FinForbidden $e) {
        throw $e;
    } catch (Throwable $e) {
        $human = $e instanceof FinUserError || in_array(get_class($e), ['Exception', 'RuntimeException'], true);
        if (!$human) error_log('[finance/api] then-step: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        fin_error('Saved as a draft, but not ' . ($GLOBALS['FIN_THEN_VERB'] ?? 'posted') . ': ' . ($human ? $e->getMessage() : 'something went wrong.'), 409, ['saved_id' => $savedId]);
    }
}

/** Writes are rate limited per person (120 a minute is far above human speed). */
if (fin_is_post()) fin_rate_limit('write', 120, 60);
