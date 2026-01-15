<?php
// CLI: php mail/worker/sla_tick.php
require_once __DIR__ . '/../init.php';
// compute sla_due_at if missing
$DB->exec("UPDATE email_threads t
           JOIN (SELECT company_id,user_id,sla_default_hours FROM user_mail_prefs) p
           ON p.company_id=t.company_id
           SET t.sla_due_at = DATE_ADD(t.first_incoming_at, INTERVAL p.sla_default_hours HOUR)
           WHERE t.first_incoming_at IS NOT NULL AND t.sla_due_at IS NULL");

// notify on breach
$stmt = $DB->query("SELECT thread_id,company_id,assigned_user_id FROM email_threads WHERE status IN ('open','pending') AND sla_due_at IS NOT NULL AND sla_due_at<=NOW()");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $DB->prepare("INSERT INTO notifications (company_id,user_id,type,title,message,url,created_at,is_read)
                VALUES (?,?,?,?,?,?,NOW(),0)")
     ->execute([$r['company_id'],$r['assigned_user_id'],'mail_sla','SLA breached','Thread needs attention','/mail/?thread_id='.$r['thread_id']]);
}
echo "sla done\n";