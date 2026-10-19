<?php
// /qi/cron/generate_recurring.php — generate the recurring invoices that are due.
// Command line only:  0 2 * * * /usr/bin/php /path/to/qi/cron/generate_recurring.php
// (On hosts without cron, the background tick — notifications/api/tick.php — does
// the same every 15 minutes.)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/QiSchema.php';
require_once __DIR__ . '/../lib/QiRecurring.php';

QiSchema::ensure($DB);
$r = QiRecurring::runDue($DB, 500);
echo 'Recurring invoices: ' . $r['generated'] . ' generated, ' . $r['failed'] . " failed.\n";
