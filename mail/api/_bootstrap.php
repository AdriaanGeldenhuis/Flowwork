<?php
// /mail/api/_bootstrap.php — shared setup for every Mail JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-TOKEN on POST)
//   - schema self-provisioning, DB session time zone = PHP time zone
//   - releases the session lock early so a long sync never blocks other calls
//   - JSON helpers and ownership helpers

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$MAIL_COMPANY_ID = (int)$_SESSION['company_id'];
$MAIL_USER_ID    = (int)$_SESSION['user_id'];
$MAIL_ROLE       = (string)($_SESSION['role'] ?? 'viewer');
session_write_close();

set_exception_handler(function (Throwable $e) {
    if ($e instanceof MailUserError) {
        mail_error($e->getMessage(), 400);
    }
    error_log('[mail/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    mail_error('Something went wrong. Please try again.', 500);
});

$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

function mail_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mail_error(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mail_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') mail_error('Method not allowed', 405);
}

/** JSON body (application/json) or form fields. */
function mail_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 8 * 1024 * 1024) mail_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) mail_error('Invalid JSON');
    } else {
        $in = $_POST;
    }
    return $in;
}

function mail_str(array $in, string $key, int $max = 1000): string
{
    $v = $in[$key] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

function mail_bool(array $in, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $in)) return $default;
    $v = $in[$key];
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

/** Conversation keys from input, validated. */
function mail_keys(array $in): array
{
    $keys = $in['keys'] ?? ($in['key'] ?? []);
    if (!is_array($keys)) $keys = [$keys];
    $out = [];
    foreach ($keys as $k) {
        if (is_string($k) && MailRepo::parseKey($k)) $out[] = $k;
    }
    if (!$out) mail_error('Nothing selected');
    if (count($out) > 500) mail_error('Too many conversations selected at once');
    return array_values(array_unique($out));
}

function mail_rate_limit(string $bucket, int $max, int $window): void
{
    global $MAIL_USER_ID;
    $wait = RateLimiter::hit($bucket . ':' . $MAIL_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        mail_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
    }
}

/**
 * Server hostname check for user-supplied IMAP/SMTP hosts: blocks internal
 * addresses (SSRF) unless MAIL_ALLOW_PRIVATE_HOSTS is set (local testing).
 */
function mail_host_problem(string $host): ?string
{
    if ($host === '') return null;
    if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $host)) return 'Server names may only contain letters, digits, dots and hyphens';
    if (getenv('MAIL_ALLOW_PRIVATE_HOSTS')) return null;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) return 'Could not find server "' . $host . '" — check the spelling';
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Server "' . $host . '" points to a private network address';
        }
    }
    return null;
}

function mail_user_name(PDO $db, int $userId): array
{
    $st = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'email' => ''];
}

function mail_audit(PDO $db, string $action, array $details): void
{
    global $MAIL_COMPANY_ID, $MAIL_USER_ID;
    try {
        $db->prepare("INSERT INTO audit_log (company_id, user_id, action, details, timestamp) VALUES (?, ?, ?, ?, NOW())")
           ->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID, $action, json_encode($details)]);
    } catch (Throwable $e) { /* audit is best-effort */ }
}
