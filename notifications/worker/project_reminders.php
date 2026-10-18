<?php
// CLI: php notifications/worker/project_reminders.php
// Cron (every 15 minutes): */15 * * * * php /path/to/notifications/worker/project_reminders.php >/dev/null 2>&1
//
// Due-date reminders for Projects: everyone on an item that is due today, or
// became overdue yesterday, gets a bell + phone notification (the app's
// projects channel, FlowWork › Mentions) — once per item per day, from 07:00.
// Done items, archived boards and closed projects are skipped. Where the
// host's cron is too slow, notifications/api/tick.php runs the same job every
// minute instead.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/notify.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

$lock = @fopen(sys_get_temp_dir() . '/flowwork-project-reminders.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // the previous run is still busy
}

// Each call handles up to 400 items and marks them; repeat while a call had a
// full batch (more may be waiting) and there is time left.
$deadline = microtime(true) + 50;
$sent = 0;
try {
    do {
        $sent += ProjectNotify::sendDueReminders($DB, 400, $processed);
    } while ($processed >= 400 && microtime(true) < $deadline);
} catch (Throwable $e) {
    error_log('[project reminders] ' . $e->getMessage());
    echo date('c') . ' project reminders failed: ' . $e->getMessage() . "\n";
    exit(1);
}

echo date('c') . ' project reminders sent=' . $sent . "\n";
