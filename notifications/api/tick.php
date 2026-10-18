<?php
// GET /notifications/api/tick.php?key=FW_CRON_KEY   (or header X-Cron-Key)
//
// Background jobs for hosts whose cron may run at most every two hours (xneelo).
// A free outside scheduler (e.g. cron-job.org) calls this URL every minute.
// Each call, within about 45 seconds:
//   - sends queued phone notifications and retries failed ones,
//   - sends calendar reminders that are due, and project due-date reminders
//     (once per item per day, from 07:00),
//   - sends due scheduled / undo-window mail and syncs mailboxes that were not
//     synced in the last 90 seconds (that is what finds new mail to notify),
//   - every 15 minutes: reply-overdue reminders; hourly: housekeeping.
// It answers at once with the previous run's figures (visible in the
// scheduler's history) and does the work after the answer has gone out.
//
// Authorisation: FW_CRON_KEY, a random string of 32+ characters defined in the
// server's config.php. Without it the URL stays closed. There is no session
// or cookie involved, so CSRF does not apply; the key is the credential.

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function tick_reply(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$expected = defined('FW_CRON_KEY') ? (string)FW_CRON_KEY : (string)getenv('FW_CRON_KEY');
if (strlen($expected) < 32) {
    tick_reply(['ok' => false, 'error' => 'Not set up: define FW_CRON_KEY (32+ characters) in config.php'], 503);
}
$given = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');
if (!is_string($given) || !hash_equals($expected, $given)) {
    tick_reply(['ok' => false, 'error' => 'Forbidden'], 403);
}

// ------------------------------------------------------------------ one at a time

$dir = dirname(__DIR__, 2) . '/storage/cron'; // storage/ is not web-readable
if (!is_dir($dir)) @mkdir($dir, 0750, true);
if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
$stateFile = $dir . '/tick.json';

$lock = @fopen($dir . '/tick.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    tick_reply(['ok' => true, 'skipped' => 'previous run still busy']);
}
$state = json_decode((string)@file_get_contents($stateFile), true);
if (!is_array($state)) $state = [];
$now = time();
if ($now - (int)($state['started'] ?? 0) < 20) {
    tick_reply(['ok' => true, 'skipped' => 'ran less than 20 seconds ago', 'last' => $state['last'] ?? null]);
}
$state['started'] = $now;

function tick_save(string $file, array $state): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $file);
}
tick_save($stateFile, $state);

// ------------------------------------------------------------------ answer now, work after

$body = json_encode(['ok' => true, 'started' => date('c', $now), 'last' => $state['last'] ?? null], JSON_UNESCAPED_SLASHES);
ignore_user_abort(true);
@set_time_limit(120);
@ini_set('memory_limit', '512M');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
while (ob_get_level() > 0 && @ob_end_flush()) {
}
flush();
$detached = false;
if (function_exists('fastcgi_finish_request')) {
    $detached = fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    $detached = litespeed_finish_request();
}
// Still holding the caller's connection? Stay inside its timeout (cron-job.org: 30 s).
$budget = $detached ? 45 : 25;
$deadline = microtime(true) + $budget;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/notify.php';
require_once __DIR__ . '/../../mail/lib/autoload.php';
require_once __DIR__ . '/../../calendar/lib/reminders.php';

$stats = [];
$run = function (string $name, callable $job) use (&$stats): void {
    try {
        $stats[$name] = $job();
    } catch (Throwable $e) {
        $stats[$name] = 'error';
        error_log('[tick] ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
};
$pushed = function () use ($DB): int|string {
    $r = fw_push_flush($DB, 100, 6.0);
    return isset($r['error']) ? 'not set up' : (int)$r['sent'];
};

$run('push', $pushed);
$run('reminders', fn() => fw_calendar_send_due_reminders($DB));
$run('project_reminders', function () use ($DB) {
    // Loaded here so a problem in Projects never stops the other jobs.
    require_once __DIR__ . '/../../projects/lib/autoload.php';
    return ProjectNotify::sendDueReminders($DB);
});
$run('mail', function () use ($DB, $deadline) {
    $left = (int)floor($deadline - microtime(true)) - 6; // keep time for the last push flush
    if ($left < 12) return 'no time left';
    $DB->exec("SET time_zone = '" . date('P') . "'");
    MailSchema::ensure($DB);
    return MailWorker::syncDue($DB, $left, 90);
});
if ($now - (int)($state['sla_at'] ?? 0) >= 900) {
    $state['sla_at'] = $now;
    $run('reply_reminders', fn() => MailWorker::slaTick($DB));
}
if ($now - (int)($state['qi_recurring_at'] ?? 0) >= 900) {
    $state['qi_recurring_at'] = $now;
    $run('qi_recurring', function () use ($DB, $deadline) {
        // recurring invoices that are due (Quotes & Invoices); bounded so mail keeps its time
        require_once __DIR__ . '/../../qi/lib/QiRecurring.php';
        return QiRecurring::runDue($DB, 20, $deadline - 8);
    });
}
$run('push_after', $pushed);
if ($now - (int)($state['cleanup_at'] ?? 0) >= 3600) {
    $state['cleanup_at'] = $now;
    $run('cleanup', function () use ($DB) {
        fw_push_cleanup($DB);
        return 'done';
    });
}

$state['last'] = ['at' => date('c', $now), 'seconds' => round(microtime(true) - ($deadline - $budget), 1), 'jobs' => $stats];
tick_save($stateFile, $state);
