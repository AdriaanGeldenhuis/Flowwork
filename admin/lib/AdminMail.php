<?php
// /admin/lib/AdminMail.php — the invitation email.
// Sent from the inviting admin's own connected mailbox when they have one
// (it arrives from a person the invitee knows and lands in their Sent
// folder's history), otherwise through Flowwork's system mail
// (shared/email_sender.php). When neither can send, the caller shows the
// link so the admin can pass it on themselves.

require_once __DIR__ . '/../../mail/lib/SecureVault.php';
require_once __DIR__ . '/../../mail/lib/SmtpSender.php';
require_once __DIR__ . '/../../shared/email_sender.php';

final class AdminMail
{
    /** Can Flowwork's own system mail send at all? (SMTP credentials in the environment) */
    public static function systemReady(): bool
    {
        return defined('SMTP_USERNAME') && defined('SMTP_PASSWORD') && SMTP_USERNAME !== '' && SMTP_PASSWORD !== '';
    }

    /** The admin's own active mailbox that can send, or null. */
    public static function mailbox(PDO $db, int $companyId, int $userId): ?array
    {
        try {
            $st = $db->prepare("SELECT account_id, email_address, display_name, smtp_server, smtp_port, smtp_encryption, username, password_encrypted
                                  FROM email_accounts
                                 WHERE company_id = ? AND user_id = ? AND is_active = 1 AND smtp_server IS NOT NULL AND smtp_server <> ''
                              ORDER BY account_id LIMIT 1");
            $st->execute([$companyId, $userId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null; // mail tables not provisioned yet
        }
    }

    /**
     * Send an invitation. $inv: email, role, link, expires_at, message (optional).
     * @return array{ok: bool, via: string, from?: string, error?: string}
     */
    public static function invite(PDO $db, int $companyId, int $userId, array $inv): array
    {
        $st = $db->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = (string)($st->fetchColumn() ?: 'our company');
        $st = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $st->execute([$userId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'email' => ''];
        $from = trim($me['first_name'] . ' ' . $me['last_name']) ?: 'Your colleague';
        $role = AdminKit::ROLES[$inv['role']][0] ?? 'Member';
        $until = date('j F Y', strtotime((string)$inv['expires_at']));

        $subject = $from . ' invited you to ' . $company . ' on Flowwork';
        $html = self::html($company, $from, $role, (string)$inv['link'], $until, trim((string)($inv['message'] ?? '')));
        $text = "Hi,\n\n{$from} invited you to join {$company} on Flowwork as {$role}.\n\n"
              . (trim((string)($inv['message'] ?? '')) !== '' ? '"' . trim((string)$inv['message']) . "\"\n\n" : '')
              . "Accept the invitation and choose your password here:\n{$inv['link']}\n\nThe link works until {$until}.\n\nFlowwork";

        $box = self::mailbox($db, $companyId, $userId);
        if ($box) {
            $res = SmtpSender::send([
                'host' => $box['smtp_server'], 'port' => (int)$box['smtp_port'], 'encryption' => $box['smtp_encryption'],
                'username' => $box['username'] ?: $box['email_address'], 'password' => SecureVault::decrypt($box['password_encrypted'] ?? ''),
                'from' => $box['email_address'], 'from_name' => $box['display_name'] ?: $from,
                'to' => [$inv['email']], 'subject' => $subject, 'html' => $html, 'text' => $text,
            ]);
            if (!empty($res['ok'])) return ['ok' => true, 'via' => 'mailbox', 'from' => (string)$box['email_address']];
            $mailboxError = (string)($res['error'] ?? 'the mailbox did not accept the message');
            error_log('[admin] invite via mailbox failed: ' . $mailboxError);
        }
        if (self::systemReady()) {
            if ((new EmailSender())->send($inv['email'], $subject, $html, true)) return ['ok' => true, 'via' => 'system'];
        }
        return ['ok' => false, 'via' => 'none',
                'error' => isset($mailboxError) ? 'Your mailbox could not send it (' . $mailboxError . ').'
                                                : 'Connect your mailbox in Mail to have Flowwork email invitations for you.'];
    }

    private static function html(string $company, string $from, string $role, string $link, string $until, string $message): string
    {
        $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $note = $message !== '' ? '<p style="margin:0 0 22px;padding:14px 18px;border-left:3px solid #b08d57;background:#faf6ef;color:#4a3e37;font-style:italic;white-space:pre-line">' . $e($message) . '</p>' : '';
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f2ece3;font-family:Georgia,\'Times New Roman\',serif;color:#1d1613">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2ece3;padding:32px 12px"><tr><td align="center">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;background:#fffdf9;border-radius:18px;border:1px solid #e6dccd">'
             . '<tr><td style="padding:34px 36px 8px">'
             . '<p style="margin:0 0 6px;font:600 10px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.24em;text-transform:uppercase;color:#87672f">Flowwork · Invitation</p>'
             . '<h1 style="margin:0 0 18px;font-weight:400;font-size:28px;line-height:1.2">Join <em style="color:#7a1f30">' . $e($company) . '</em></h1>'
             . '<p style="margin:0 0 18px;font:15px/1.6 Helvetica,Arial,sans-serif;color:#4a3e37">' . $e($from) . ' has invited you to work together in Flowwork as <b>' . $e($role) . '</b>.</p>'
             . $note
             . '<p style="margin:0 0 26px"><a href="' . $e($link) . '" style="display:inline-block;padding:13px 26px;border-radius:999px;background:#7a1f30;color:#fff8f0;font:600 14px Helvetica,Arial,sans-serif;text-decoration:none">Accept the invitation</a></p>'
             . '<p style="margin:0 0 6px;font:12.5px/1.6 Helvetica,Arial,sans-serif;color:#85766b">The link works until ' . $e($until) . '. If the button does not work, paste this into your browser:</p>'
             . '<p style="margin:0 0 30px;font:12px/1.5 Menlo,monospace;color:#87672f;word-break:break-all">' . $e($link) . '</p>'
             . '</td></tr><tr><td style="padding:16px 36px 26px;border-top:1px solid #efe6d8;font:11.5px/1.6 Helvetica,Arial,sans-serif;color:#b3a597">Not expecting this? You can ignore this email — nothing happens until you accept.</td></tr>'
             . '</table></td></tr></table></body></html>';
    }
}
