<?php
// CLI: php notifications/worker/send_push.php
// Cron (every minute): * * * * * php /path/to/notifications/worker/send_push.php >/dev/null 2>&1
//
// Sends queued phone notifications (push_outbox) to the Flowwork Android app
// through Firebase Cloud Messaging, and retries the ones that failed. Most
// pushes already go out at the end of the request that created them; this is
// the safety net (and the only sender for jobs that queue without finishing,
// e.g. a request that ran out of time).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/notify.php';

$lock = @fopen(sys_get_temp_dir() . '/flowwork-send-push.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // the previous run is still busy
}

if (fw_push_service_account() === null) {
    echo date('c') . " push not configured: add storage/firebase/service-account.json (see includes/push.php)\n";
    exit(0);
}

$deadline = microtime(true) + 50; // finish before the next cron minute
$total = ['claimed' => 0, 'sent' => 0, 'devices' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
do {
    $r = fw_push_flush($DB, 100, max(1.0, $deadline - microtime(true)));
    foreach ($total as $k => $v) $total[$k] = $v + (int)($r[$k] ?? 0);
} while ($r['claimed'] > 0 && microtime(true) < $deadline);

if (random_int(1, 60) === 1) fw_push_cleanup($DB);

echo date('c') . ' push ' . http_build_query($total, '', ' ') . "\n";
