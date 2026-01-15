<?php
// CLI: php mail/worker/rules_reprocess.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../lib/RulesEngine.php';
$since = date('Y-m-d H:i:s', time()-30*86400);
$stmt = $DB->prepare("SELECT email_id,company_id FROM emails WHERE sent_at>=? ORDER BY email_id DESC");
$stmt->execute([$since]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  RulesEngine::applyToMessage($DB, (int)$r['email_id'], (int)$r['company_id']);
}
echo "reprocessed\n";