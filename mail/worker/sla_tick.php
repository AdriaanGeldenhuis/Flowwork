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

$prefs = $DB->query("SELECT user_id, company_id, sla_default_hours, settings_json FROM user_mail_prefs")->fetchAll(PDO::FETCH_ASSOC);
$notified = 0;
foreach ($prefs as $p) {
    $settings = json_decode((string)$p['settings_json'], true);
    if (empty($settings['sla_enabled'])) continue;
    $hours = max(1, (int)$p['sla_default_hours']);

    // Latest incoming vs latest reply per INBOX conversation.
    $stmt = $DB->prepare("
        SELECT e.thread_id, a.company_id,
               MAX(CASE WHEN e.direction = 'incoming' THEN e.sent_at END) AS last_in,
               MAX(CASE WHEN e.direction = 'outgoing' AND e.folder = 'Sent' THEN e.sent_at END) AS last_out,
               t.subject, t.sla_notified_at
          FROM emails e
          JOIN email_accounts a ON a.account_id = e.account_id
          JOIN email_threads t ON t.thread_id = e.thread_id
         WHERE a.user_id = ? AND a.company_id = ? AND e.thread_id > 0
           AND e.folder IN ('INBOX', 'Sent')
      GROUP BY e.thread_id, a.company_id, t.subject, t.sla_notified_at
        HAVING last_in IS NOT NULL
           AND last_in >= DATE_SUB(NOW(), INTERVAL 14 DAY)
           AND (last_out IS NULL OR last_out < last_in)
           AND DATE_ADD(last_in, INTERVAL ? HOUR) <= NOW()
           AND (sla_notified_at IS NULL OR sla_notified_at < last_in)
         LIMIT 50");
    $stmt->execute([(int)$p['user_id'], (int)$p['company_id'], $hours]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $DB->prepare("UPDATE email_threads SET sla_due_at = DATE_ADD(?, INTERVAL ? HOUR), sla_notified_at = NOW() WHERE thread_id = ?")
           ->execute([$t['last_in'], $hours, (int)$t['thread_id']]);
        $subject = $t['subject'] ?: '(no subject)';
        $id = fw_notify($DB, (int)$t['company_id'], (int)$p['user_id'], 'mail_sla', 'Reply overdue',
                        'No reply yet: ' . $subject, '/mail/?open=t' . (int)$t['thread_id'], [
                            'ch' => 'mail_reminders', 'grp' => 'mail_reminders', 'app' => 'mail',
                            'tag' => 'sla:t' . (int)$t['thread_id'], 'body' => $subject, 'ttl' => 3 * 86400,
                        ]);
        if ($id) $notified++;
    }
}
echo date('c') . " sla reminders=$notified\n";
