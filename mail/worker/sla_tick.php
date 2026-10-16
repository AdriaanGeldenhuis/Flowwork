<?php
// CLI: php mail/worker/sla_tick.php
// Cron (every 15 minutes): */15 * * * * php /path/to/mail/worker/sla_tick.php >/dev/null 2>&1
//
// Response-time reminders. For users who enabled "Reply reminders" in Mail
// settings, any conversation whose latest incoming message has gone
// unanswered for longer than their target gets one in-app notification.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/autoload.php';

$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

$notified = MailWorker::slaTick($DB);
echo date('c') . " sla reminders=$notified\n";
