<?php
// CLI: php mail/worker/sync_imap.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../lib/MailSyncService.php';
$stmt = $DB->query("SELECT * FROM email_accounts WHERE is_active=1 AND imap_server<>''");
while ($acc = $stmt->fetch(PDO::FETCH_ASSOC)) {
  try { MailSyncService::syncImapAccount($DB, $acc, (int)$acc['company_id']); }
  catch (Exception $e) { /* log if needed */ }
}
echo "done\n";