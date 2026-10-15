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

$started = time();
$maxRuntime = 270; // stay inside a 5-minute cron slot

// Send due mail first — it is time-sensitive.
$sent = MailOutbox::flushDue($DB, null, null, 50);

$stmt = $DB->query("SELECT * FROM email_accounts WHERE is_active = 1 AND imap_server <> '' ORDER BY COALESCE(last_sync_at, '1970-01-01') ASC");
$synced = 0;
$new = 0;
$errors = 0;
while ($acc = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $left = $maxRuntime - (time() - $started);
    if ($left < 15) break;
    $r = MailSyncService::syncAccount($DB, $acc, ['time_budget' => min(120, $left - 5)]);
    if (!empty($r['skipped'])) continue;
    $synced++;
    $new += $r['new'];
    if (!$r['ok']) $errors++;
}

// Anything that became due while we were syncing.
$sent += MailOutbox::flushDue($DB, null, null, 50);

echo date('c') . " accounts=$synced new=$new errors=$errors sent=" . count($sent) . "\n";
