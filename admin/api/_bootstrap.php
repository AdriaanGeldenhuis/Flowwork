<?php
// /admin/api/_bootstrap.php — shared setup for every admin JSON endpoint.
//   - authentication + CSRF (auth_gate validates X-CSRF-Token on every POST)
//   - admins of the active company only
//   - JSON / input helpers, rate limiting and one error format:
//     {ok:false, error, errors?: {field: message}}

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/../lib/AdminKit.php';
require_once __DIR__ . '/../lib/AdminSettings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

fw_require_admin('json');

$ADMIN_COMPANY_ID = (int)$_SESSION['company_id'];
$ADMIN_USER_ID = (int)$_SESSION['user_id'];
$ADMIN_ROLE = (string)($_SESSION['role'] ?? 'admin');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof AdminInvalid) admin_error($e->getMessage(), 422, ['errors' => $e->errors]);
    error_log('[admin/api] ' . basename($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    admin_error('Something went wrong. Please try again.', 500);
});

function admin_json(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function admin_require_post(): void
{
    if (!admin_is_post()) admin_error('Method not allowed', 405);
}

/** JSON body (application/json) or form fields. */
function admin_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 512 * 1024) admin_error('Request too large', 413);
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) admin_error('Invalid JSON');
    } else {
        $in = $_POST;
    }
    return $in;
}

function admin_str(array $in, string $key, int $max = 255): string
{
    $v = $in[$key] ?? '';
    if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

function admin_int(array $in, string $key): int
{
    $v = $in[$key] ?? 0;
    return is_scalar($v) ? (int)$v : 0;
}

function admin_rate_limit(string $bucket, int $max, int $window): void
{
    global $ADMIN_USER_ID;
    $wait = AdminKit::rateLimit($bucket . ':' . $ADMIN_USER_ID, $max, $window);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        admin_error('Too many requests — please wait ' . $wait . ' seconds and try again.', 429, ['retry_after' => $wait]);
    }
}

/** The member row of a person in the active company (or an error). */
function admin_member(PDO $db, int $companyId, int $userId): array
{
    $st = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.is_seat, u.company_id AS primary_company_id, uc.role
                          FROM user_companies uc JOIN users u ON u.id = uc.user_id
                         WHERE uc.user_id = ? AND uc.company_id = ?");
    $st->execute([$userId, $companyId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) admin_error('That person is not part of this company.', 404);
    return $m;
}
