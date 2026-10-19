<?php
// CLI: php crm/cron/reminders.php
// CRM task and compliance-document reminders, for hosts with a real cron.
// Most installs do not need it: /notifications/api/tick.php runs the same
// jobs every minute.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/CrmNotify.php';

try {
    $r = CrmNotify::tick($DB, microtime(true) + 50);
    echo "CRM reminders sent: {$r['tasks']} task(s), {$r['compliance']} compliance.\n";
} catch (Throwable $e) {
    error_log('[crm/reminders] ' . $e->getMessage());
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
