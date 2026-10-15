<?php
// CLI: php mail/worker/rules_reprocess.php [days=30]
// Re-applies inbox rules and smart labels to recent incoming mail
// (the same thing Settings → Rules → "Run on inbox" does for one user).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/autoload.php';

$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

$days = max(1, (int)($argv[1] ?? 30));
$stmt = $DB->prepare("SELECT email_id FROM emails WHERE direction = 'incoming' AND folder = 'INBOX' AND sent_at >= ? ORDER BY email_id DESC");
$stmt->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
$n = 0;
while ($id = $stmt->fetchColumn()) {
    RulesEngine::applyToEmail($DB, (int)$id);
    $n++;
}
echo "reprocessed $n messages\n";
