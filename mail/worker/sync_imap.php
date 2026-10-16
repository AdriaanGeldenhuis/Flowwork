<?php
// CLI: php mail/worker/sync_imap.php
// Cron (every 5 minutes):  */5 * * * * php /path/to/mail/worker/sync_imap.php >/dev/null 2>&1
//
// Syncs every active IMAP account (new mail, flags, queued server changes)
// and sends any due scheduled / undo-window messages from the Outbox.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/autoload.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');
$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

// Up to 270 s: stays inside a 5-minute cron slot. (The web tick,
// notifications/api/tick.php, runs the same job in short slices.)
$r = MailWorker::syncDue($DB, 270);

echo date('c') . " accounts={$r['accounts']} new={$r['new']} errors={$r['errors']} sent={$r['sent']}\n";
