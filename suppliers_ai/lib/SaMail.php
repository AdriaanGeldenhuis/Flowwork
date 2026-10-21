<?php
// /suppliers_ai/lib/SaMail.php — a request for quotes goes out through
// Flowwork Mail from the person's own mailbox, one email per supplier (they
// never see each other), filed in their Sent folder and linked both to the
// supplier in the CRM (replies show up on the account) and to the request
// (email_links 'rfq'). The supplier's activity in the CRM records it. A
// reminder goes into the same conversation, so replies stay together.

require_once dirname(__DIR__, 2) . '/mail/lib/autoload.php';
require_once dirname(__DIR__, 2) . '/crm/lib/Crm.php';

final class SaMail
{
    /** Mailboxes the person can send from (with an outgoing server). */
    public static function mailboxes(PDO $db, int $companyId, int $userId): array
    {
        $out = [];
        try {
            foreach (MailRepo::accounts($db, $companyId, $userId, true) as $a) {
                if (trim((string)$a['smtp_server']) === '') continue;
                $out[] = ['id' => (int)$a['account_id'], 'email' => (string)$a['email_address'], 'name' => (string)($a['account_name'] ?: $a['email_address'])];
            }
        } catch (Throwable $e) {
            error_log('[suppliers_ai] mailboxes: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Send one email to one supplier.
     * $in: mailbox_id, to (list), subject, body, reply_to_email_id (a reminder goes into that conversation)
     * @return array{email_id: int, thread_id: ?int}
     */
    public static function send(PDO $db, int $companyId, int $userId, int $accountId, int $requestId, array $in): array
    {
        $to = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), (array)($in['to'] ?? [])))));
        $subject = mb_substr(trim((string)($in['subject'] ?? '')), 0, 300);
        $body = SaKit::lines($in['body'] ?? '', 20000);
        if (!$to) throw new SaProblem('This supplier has no email address in the CRM — add one, or send it by WhatsApp.', 422);
        foreach ($to as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new SaProblem("“{$a}” is not an email address.", 422);
        if ($subject === '') throw new SaProblem('Add a subject.', 422);
        if ($body === '') throw new SaProblem('Write the message.', 422);
        $box = null;
        foreach (self::mailboxes($db, $companyId, $userId) as $b) if ($b['id'] === (int)($in['mailbox_id'] ?? 0) || (!(int)($in['mailbox_id'] ?? 0) && !$box)) $box = $b;
        if (!$box) throw new SaProblem('Connect your mailbox in Mail (with an outgoing SMTP server) to send from Flowwork — or use WhatsApp or your own email app.', 409);

        $draft = ['account_id' => $box['id'], 'to' => $to, 'subject' => $subject, 'body_html' => self::html($db, $companyId, $userId, $box['id'], $body)];
        $replyTo = (int)($in['reply_to_email_id'] ?? 0);
        if ($replyTo > 0) {
            $st = $db->prepare("SELECT thread_id, message_id, ref_message_ids FROM emails WHERE email_id = ? AND company_id = ?");
            $st->execute([$replyTo, $companyId]);
            if ($orig = $st->fetch(PDO::FETCH_ASSOC)) {
                if ((int)$orig['thread_id'] > 0) $draft['thread_key'] = 't' . (int)$orig['thread_id'];
                if ($orig['message_id']) {
                    $draft['in_reply_to'] = (string)$orig['message_id'];
                    $draft['references'] = trim((string)$orig['ref_message_ids'] . ' ' . $orig['message_id']);
                }
            }
        }
        $emailId = null;
        try {
            $d = MailOutbox::saveDraft($db, $companyId, $userId, $draft);
            $emailId = (int)$d['email_id'];
            $link = $db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at, company_id) VALUES (?, ?, ?, ?, NOW(), ?)");
            try {
                $link->execute([$emailId, 'supplier', $accountId, $userId, $companyId]);
                $link->execute([$emailId, 'rfq', $requestId, $userId, $companyId]);
            } catch (Throwable $e) {
                error_log('[suppliers_ai] email link: ' . $e->getMessage());
            }
            $res = MailOutbox::queue($db, $companyId, $userId, $emailId, null, 0);
        } catch (MailUserError $e) {
            throw new SaProblem($e->getMessage(), 422);
        } catch (SaProblem $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[suppliers_ai] send: ' . $e->getMessage());
            throw new SaProblem('The email could not be sent. Please try again.', 502);
        }
        if (empty($res['sent'])) {
            $err = rtrim((string)($res['error'] ?? 'The mail server did not accept the message'), '. ');
            throw new SaProblem($err . '. It is waiting in your Outbox in Mail.', 502);
        }
        $st = $db->prepare("SELECT thread_id FROM emails WHERE email_id = ?");
        $st->execute([$emailId]);
        $threadId = (int)$st->fetchColumn() ?: null;
        // The supplier's activity in the CRM (the full text stays in Mail)
        try {
            $db->prepare("INSERT INTO crm_interactions (company_id, account_id, type, subject, body, via, created_by, created_at) VALUES (?, ?, 'email', ?, ?, 'mail', ?, NOW())")
               ->execute([$companyId, $accountId, mb_substr('Emailed: ' . $subject, 0, 190), mb_substr('To ' . implode(', ', $to) . "\n\n" . $body, 0, 20000), $userId]);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] crm activity: ' . $e->getMessage());
        }
        return ['email_id' => $emailId, 'thread_id' => $threadId, 'from' => $box['email']];
    }

    /** Plain text → the email's HTML, with the mailbox's signature. */
    private static function html(PDO $db, int $companyId, int $userId, int $mailboxId, string $body): string
    {
        $html = '';
        foreach (preg_split("/\n{2,}/", trim($body)) ?: [] as $p) {
            $e = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $e = preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)\]])#i', '<a href="$1">$1</a>', $e) ?? $e;
            $html .= '<p style="margin:0 0 14px">' . nl2br($e, false) . '</p>';
        }
        try {
            $st = $db->prepare("SELECT s.content_html FROM email_accounts a JOIN email_signatures s ON s.signature_id = a.default_signature_id AND s.company_id = a.company_id
                                 WHERE a.account_id = ? AND a.company_id = ? AND a.user_id = ? AND (s.user_id = a.user_id OR s.user_id IS NULL)");
            $st->execute([$mailboxId, $companyId, $userId]);
            $sig = (string)($st->fetchColumn() ?: '');
            if ($sig !== '') {
                $sig = HtmlSanitizer::sanitize(HtmlSanitizer::ensureHtml($sig), ['block_remote' => false])['html'];
                $html .= '<div class="flowwork-signature" style="margin-top:18px">' . $sig . '</div>';
            }
        } catch (Throwable $e) {
            // no signature
        }
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#222">' . $html . '</div>';
    }
}
