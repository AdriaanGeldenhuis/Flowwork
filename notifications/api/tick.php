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
//   - CRM task reminders that are due; hourly: CRM compliance-document
//     reminders; daily: supplier delivery figures,
//   - every 15 minutes: reply-overdue reminders and due recurring invoices
//     (Quotes & Invoices); hourly: housekeeping and the audit-trail
//     retention each company chose in Admin › Data & privacy.
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
// Recurring invoices that are due (Quotes & Invoices): before mail, so a long
// mailbox sync can never starve it, and bounded so mail keeps most of the time.
// Every 15 minutes, or on the next tick while a backlog remains.
if ($now - (int)($state['qi_recurring_at'] ?? 0) >= 900 || !empty($state['qi_recurring_more'])) {
    $state['qi_recurring_at'] = $now;
    $run('qi_recurring', function () use ($DB, $deadline, &$state) {
        require_once __DIR__ . '/../../qi/lib/QiSchema.php';
        require_once __DIR__ . '/../../qi/lib/QiRecurring.php';
        QiSchema::ensure($DB);
        $r = QiRecurring::runDue($DB, 20, min($deadline - 20, microtime(true) + 12));
        $state['qi_recurring_more'] = !empty($r['more']);
        return $r;
    });
}
// CRM: task reminders every tick (a cheap indexed look-up), compliance
// document reminders hourly, supplier delivery figures once a day.
$run('crm_tasks', function () use ($DB, $deadline) {
    require_once __DIR__ . '/../../crm/lib/CrmNotify.php';
    CrmSchema::ensure($DB);
    return CrmNotify::tasks($DB, min($deadline - 20, microtime(true) + 5));
});
if ($now - (int)($state['crm_compliance_at'] ?? 0) >= 3600) {
    $state['crm_compliance_at'] = $now;
    $run('crm_compliance', function () use ($DB, $deadline) {
        require_once __DIR__ . '/../../crm/lib/CrmNotify.php';
        return CrmNotify::compliance($DB, min($deadline - 20, microtime(true) + 8));
    });
}
if (date('Y-m-d', (int)($state['crm_kpis_at'] ?? 0)) !== date('Y-m-d', $now) && (int)date('G', $now) >= 2) {
    $state['crm_kpis_at'] = $now;
    $run('crm_supplier_kpis', function () use ($DB) {
        require_once __DIR__ . '/../../crm/lib/CrmNotify.php';
        return CrmNotify::supplierKpis($DB);
    });
}
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
$run('push_after', $pushed);
if ($now - (int)($state['cleanup_at'] ?? 0) >= 3600) {
    $state['cleanup_at'] = $now;
    $run('cleanup', function () use ($DB) {
        fw_push_cleanup($DB);
        return 'done';
    });
    // Audit trail kept only as long as each company chose (Admin › Data & privacy)
    $run('audit_retention', function () use ($DB) {
        require_once __DIR__ . '/../../includes/audit_retention.php';
        return fw_audit_retention_cleanup($DB);
    });
}

$state['last'] = ['at' => date('c', $now), 'seconds' => round(microtime(true) - ($deadline - $budget), 1), 'jobs' => $stats];
tick_save($stateFile, $state);
