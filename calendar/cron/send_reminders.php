<?php
// /calendar/cron/send_reminders.php
// Run every 5 minutes: */5 * * * * php /path/to/calendar/cron/send_reminders.php
// (Where the host allows cron only every two hours, notifications/api/tick.php
// runs this every minute instead; see notifications/README.md.)

require_once __DIR__ . '/../../init.php';

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../lib/reminders.php';

try {
    $sentCount = fw_calendar_send_due_reminders($DB);
    echo "[" . date('Y-m-d H:i:s') . "] Sent $sentCount reminders\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("Calendar reminders error: " . $e->getMessage());
}
