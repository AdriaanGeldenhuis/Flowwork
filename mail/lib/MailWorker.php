<?php
// mail/lib/MailWorker.php
//
// The background jobs of Mail, shared by the CLI workers (mail/worker/*.php)
// and the web tick (notifications/api/tick.php), which an outside scheduler
// calls every minute because the host's cron runs at most every two hours.

class MailWorker
{
    /**
     * Send due Outbox mail, then sync active accounts, least recently synced
     * first, until the time budget runs out.
     *
     * @param int $minAge only sync accounts last synced at least this many seconds ago
     * @return array{accounts:int,new:int,errors:int,sent:int}
     */
    public static function syncDue(PDO $db, int $budget, int $minAge = 0): array
    {
        $started = time();
        // Send due mail first — it is time-sensitive.
        $sent = count(MailOutbox::flushDue($db, null, null, 50));

        $st = $db->prepare("SELECT * FROM email_accounts
                             WHERE is_active = 1 AND imap_server <> ''
                               AND (last_sync_at IS NULL OR last_sync_at <= DATE_SUB(NOW(), INTERVAL ? SECOND))
                          ORDER BY COALESCE(last_sync_at, '1970-01-01') ASC");
        $st->execute([max(0, $minAge)]);
        $accounts = 0;
        $new = 0;
        $errors = 0;
        while ($acc = $st->fetch(PDO::FETCH_ASSOC)) {
            $left = $budget - (time() - $started);
            if ($left < 10) break;
            $r = MailSyncService::syncAccount($db, $acc, ['time_budget' => min(120, $left - 5)]);
            if (!empty($r['skipped'])) continue;
            $accounts++;
            $new += (int)($r['new'] ?? 0);
            if (empty($r['ok'])) $errors++;
        }

        // Anything that became due while we were syncing.
        $sent += count(MailOutbox::flushDue($db, null, null, 50));
        return ['accounts' => $accounts, 'new' => $new, 'errors' => $errors, 'sent' => $sent];
    }

    /**
     * Reply reminders. For users who enabled "Reply reminders" in Mail
     * settings, any conversation whose latest incoming message has gone
     * unanswered for longer than their target gets one notification.
     *
     * @return int notifications created
     */
    public static function slaTick(PDO $db): int
    {
        require_once dirname(__DIR__, 2) . '/includes/notify.php';
        $prefs = $db->query("SELECT user_id, company_id, sla_default_hours, settings_json FROM user_mail_prefs")->fetchAll(PDO::FETCH_ASSOC);
        $notified = 0;
        foreach ($prefs as $p) {
            $settings = json_decode((string)$p['settings_json'], true);
            if (empty($settings['sla_enabled'])) continue;
            $hours = max(1, (int)$p['sla_default_hours']);

            // Latest incoming vs latest reply per INBOX conversation.
            $stmt = $db->prepare("
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
                $db->prepare("UPDATE email_threads SET sla_due_at = DATE_ADD(?, INTERVAL ? HOUR), sla_notified_at = NOW() WHERE thread_id = ?")
                   ->execute([$t['last_in'], $hours, (int)$t['thread_id']]);
                $subject = $t['subject'] ?: '(no subject)';
                $id = fw_notify($db, (int)$t['company_id'], (int)$p['user_id'], 'mail_sla', 'Reply overdue',
                                'No reply yet: ' . $subject, '/mail/?open=t' . (int)$t['thread_id'], [
                                    'ch' => 'mail_reminders', 'grp' => 'mail_reminders', 'app' => 'mail',
                                    'tag' => 'sla:t' . (int)$t['thread_id'], 'body' => $subject, 'ttl' => 3 * 86400,
                                ]);
                if ($id) $notified++;
            }
        }
        return $notified;
    }
}
