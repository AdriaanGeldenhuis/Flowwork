<?php
// /qi/api/_bootstrap.php — shared setup for every Quotes & Invoices JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-Token on every POST)
//   - schema self-provisioning (QiSchema)
//   - roles: viewers read only; settings, banking and custom CSS need an admin
//   - releases the session lock early so slow calls never block other tabs
//   - JSON / input helpers and a small per-user rate limiter

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/QiSchema.php';
require_once __DIR__ . '/../lib/QiTheme.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$QI_COMPANY_ID = (int)$_SESSION['company_id'];
$QI_USER_ID    = (int)$_SESSION['user_id'];
$QI_ROLE       = strtolower((string)($_SESSION['role'] ?? 'viewer'));
session_write_close();

final class QiUserError extends RuntimeException {}
final class QiForbidden extends RuntimeException {}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof QiForbidden) qi_error($e->getMessage(), 403);
    if ($e instanceof QiUserError) qi_error($e->getMessage(), str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 400);
    error_log('[qi/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    qi_error('Something went wrong. Please try again.', 500);
});

QiSchema::ensure($DB);

function qi_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function qi_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function qi_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function qi_require_post(): void
{
    if (!qi_is_post()) qi_error('Method not allowed', 405);
}

/** Viewers are read-only everywhere in Quotes & Invoices. */
function qi_can_write(): bool
{
    global $QI_ROLE;
    return $QI_ROLE !== 'viewer';
}

function qi_is_admin(): bool
{
    global $QI_ROLE;
    return in_array($QI_ROLE, ['admin', 'bookkeeper'], true);
}

function qi_require_writer(): void
{
    if (!qi_can_write()) throw new QiForbidden('You have read-only access to Quotes & Invoices.');
}

function qi_require_admin(): void
{
    if (!qi_is_admin()) throw new QiForbidden('Only an administrator can change this.');
}

/** JSON body (application/json) or form fields. */
function qi_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1024 * 1024) qi_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) qi_error('Invalid JSON');
    } else {
        $in = $_POST;
    }
    return $in;
}

function qi_str(array $in, string $key, int $max = 1000): string
{
    $v = $in[$key] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

function qi_int(array $in, string $key): int
{
    $v = $in[$key] ?? 0;
    return is_scalar($v) ? (int)$v : 0;
}

/** Per-user sliding window limit (file based, shared-host friendly). */
function qi_rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    global $QI_USER_ID;
    $dir = dirname(__DIR__, 2) . '/storage/qi/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $file = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $bucket) . '_' . $QI_USER_ID . '.json';
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
            qi_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
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

/** The company row (letterhead, VAT number …). */
function qi_company(): array
{
    global $DB, $QI_COMPANY_ID;
    static $row = null;
    if ($row !== null) return $row;
    $st = $DB->prepare("SELECT * FROM companies WHERE id = ?");
    $st->execute([$QI_COMPANY_ID]);
    return $row = ($st->fetch(PDO::FETCH_ASSOC) ?: []);
}
