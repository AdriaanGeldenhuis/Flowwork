<?php
// crm/lib/CrmMail.php
//
// Email a customer or supplier from the CRM through Flowwork Mail: from the
// user's own mailbox (SMTP), filed in their Sent folder, linked to the account
// (so replies show up on the account), and logged as activity.
// Starting points: a blank message, the CRM's own templates (welcome, request
// for quote, friendly reminder — the company can rewrite them in settings) or
// any template from Mail. Placeholders: {first_name} {contact} {name}
// {company} {sender} {date}.

require_once __DIR__ . '/Crm.php';

final class CrmMail
{
    public const BUILTIN = [
        'welcome' => [
            'label' => 'Welcome',
            'subject' => 'Welcome to {company}',
            'body' => "Hi {first_name},\n\nThank you for choosing {company}. We are glad to be working with {name}.\n\nIf there is anything you need, simply reply to this email.\n\nKind regards\n{sender}",
        ],
        'rfq' => [
            'label' => 'Request for quote',
            'subject' => 'Request for quotation — {company}',
            'body' => "Hi {first_name},\n\nPlease send us your quotation for the following:\n\n- \n\nWe need it by {date}. Please include delivery times and payment terms.\n\nKind regards\n{sender}",
        ],
        'reminder' => [
            'label' => 'Friendly reminder',
            'subject' => 'Following up',
            'body' => "Hi {first_name},\n\nI am just following up on my previous message. Please let me know if you have any questions.\n\nKind regards\n{sender}",
        ],
    ];

    public static function mailboxes(PDO $db, int $companyId, int $userId): array
    {
        $out = [];
        try {
            require_once __DIR__ . '/../../mail/lib/autoload.php';
            foreach (MailRepo::accounts($db, $companyId, $userId, true) as $a) {
                if (trim((string)$a['smtp_server']) === '') continue;
                $out[] = ['id' => (int)$a['account_id'], 'email' => (string)$a['email_address'], 'name' => (string)($a['account_name'] ?: $a['email_address'])];
            }
        } catch (Throwable $e) {
            error_log('[crm] mailboxes: ' . $e->getMessage());
        }
        return $out;
    }

    /** The company's versions of the built-in templates. */
    public static function builtins(PDO $db, int $companyId): array
    {
        $rules = Crm::rules($db, $companyId);
        $out = [];
        foreach (self::BUILTIN as $k => $t) {
            $saved = (string)($rules['crm_email_template_' . $k] ?? '');
            $j = json_decode($saved, true);
            if (is_array($j)) {
                $t['subject'] = trim((string)($j['subject'] ?? '')) ?: $t['subject'];
                $t['body'] = trim((string)($j['body'] ?? '')) ?: $t['body'];
            } elseif (trim($saved) !== '') {
                $t['body'] = $saved;
            }
            $out[$k] = $t + ['key' => $k];
        }
        return $out;
    }

    /** Mail's own templates (company-wide), as plain text. */
    public static function mailTemplates(PDO $db, int $companyId): array
    {
        try {
            require_once __DIR__ . '/../../mail/lib/autoload.php';
            $st = $db->prepare("SELECT template_id, name, subject, body_html FROM email_templates WHERE company_id = ? ORDER BY name LIMIT 100");
            $st->execute([$companyId]);
            return array_map(fn($t) => ['key' => 'mail:' . (int)$t['template_id'], 'label' => (string)$t['name'], 'subject' => (string)$t['subject'],
                                        'body' => trim(HtmlSanitizer::toText(HtmlSanitizer::ensureHtml((string)$t['body_html'])))],
                             $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Defaults for the compose dialog. */
    public static function compose(PDO $db, int $companyId, int $userId, int $accountId, int $contactId = 0): array
    {
        $st = $db->prepare("SELECT id, name, email, type FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$accountId, $companyId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new CrmUserError('Account not found.');
        $st = $db->prepare("SELECT id, first_name, last_name, email, is_primary FROM crm_contacts WHERE company_id = ? AND account_id = ? AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, id");
        $st->execute([$companyId, $accountId]);
        $people = $st->fetchAll(PDO::FETCH_ASSOC);
        $to = [];
        $first = '';
        $contactName = '';
        foreach ($people as $p) {
            if ($contactId > 0 ? (int)$p['id'] === $contactId : !$to) {
                $to[] = (string)$p['email'];
                $first = (string)$p['first_name'];
                $contactName = trim($p['first_name'] . ' ' . $p['last_name']);
            }
        }
        if (!$to && $acc['email']) $to[] = (string)$acc['email'];
        $vars = self::vars($db, $companyId, $userId, $acc, $first, $contactName);
        $templates = [];
        foreach (self::builtins($db, $companyId) as $k => $t) {
            if ($k === 'rfq' && $acc['type'] !== 'supplier') continue;
            $templates[] = ['key' => $k, 'label' => $t['label'], 'subject' => self::fill($t['subject'], $vars), 'body' => self::fill($t['body'], $vars)];
        }
        foreach (self::mailTemplates($db, $companyId) as $t) {
            $templates[] = ['key' => $t['key'], 'label' => $t['label'], 'subject' => self::fill($t['subject'], $vars), 'body' => self::fill($t['body'], $vars)];
        }
        return [
            'to' => $to,
            'suggest' => array_values(array_unique(array_filter(array_merge(array_map(fn($p) => (string)$p['email'], $people), [(string)$acc['email']])))),
            'mailboxes' => self::mailboxes($db, $companyId, $userId),
            'templates' => $templates,
            'greeting' => 'Hi ' . ($first !== '' ? $first : 'there') . ",\n\n",
            'signoff' => "\n\nKind regards\n" . $vars['sender'],
        ];
    }

    private static function vars(PDO $db, int $companyId, int $userId, array $acc, string $first, string $contact): array
    {
        return [
            'first_name' => $first !== '' ? $first : 'there', 'contact' => $contact, 'name' => (string)$acc['name'], 'customer' => (string)$acc['name'],
            'supplier' => (string)$acc['name'], 'company' => Crm::companyName($db, $companyId), 'sender' => Crm::personName($db, $companyId, $userId),
            'date' => date('j F Y', strtotime('+7 days')),
        ];
    }

    private static function fill(string $s, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/i', fn($m) => array_key_exists(strtolower($m[1]), $vars) ? (string)$vars[strtolower($m[1])] : $m[0], $s) ?? $s;
    }

    /**
     * Send. $in: account_id, contact_id, opportunity_id, mailbox_id, to (string|array), cc, subject, body.
     * @return array{ok: bool, error: ?string, email_id: ?int, activity_id: ?int}
     */
    public static function send(PDO $db, int $companyId, int $userId, array $in): array
    {
        require_once __DIR__ . '/../../mail/lib/autoload.php';
        $accountId = (int)($in['account_id'] ?? 0);
        $st = $db->prepare("SELECT id, name, type FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$accountId, $companyId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new CrmUserError('Account not found.');
        $to = self::addresses($in['to'] ?? []);
        $cc = self::addresses($in['cc'] ?? []);
        $subject = mb_substr(trim((string)($in['subject'] ?? '')), 0, 300);
        $body = trim((string)($in['body'] ?? ''));
        if (!$to) throw new CrmUserError('Add who the email is to.');
        foreach (array_merge($to, $cc) as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new CrmUserError("“{$a}” is not an email address.");
        if (count($to) + count($cc) > 20) throw new CrmUserError('Send to at most 20 people at once.');
        if ($subject === '') throw new CrmUserError('Add a subject.');
        if ($body === '') throw new CrmUserError('Write a message.');
        if (mb_strlen($body) > 100000) throw new CrmUserError('The message is too long.');
        $boxes = self::mailboxes($db, $companyId, $userId);
        $box = null;
        foreach ($boxes as $b) if ($b['id'] === (int)($in['mailbox_id'] ?? 0) || (!(int)($in['mailbox_id'] ?? 0) && !$box)) $box = $b;
        if (!$box) throw new CrmUserError('Connect your mailbox in Mail (with an outgoing SMTP server) to send email from Flowwork.');

        $emailId = null;
        try {
            $draft = MailOutbox::saveDraft($db, $companyId, $userId, [
                'account_id' => $box['id'], 'to' => $to, 'cc' => $cc, 'subject' => $subject, 'body_html' => self::html($db, $companyId, $userId, $box['id'], $body),
            ]);
            $emailId = (int)$draft['email_id'];
            try {
                $db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at, company_id) VALUES (?, ?, ?, ?, NOW(), ?)")
                   ->execute([$emailId, $acc['type'], $accountId, $userId, $companyId]);
            } catch (Throwable $e) {
                error_log('[crm] email link: ' . $e->getMessage());
            }
            $res = MailOutbox::queue($db, $companyId, $userId, $emailId, null, 0);
        } catch (MailUserError $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'email_id' => $emailId, 'activity_id' => null];
        } catch (Throwable $e) {
            error_log('[crm] send email: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The email could not be sent. Please try again.', 'email_id' => $emailId, 'activity_id' => null];
        }
        if (empty($res['sent'])) {
            $err = rtrim((string)($res['error'] ?? 'The mail server did not accept the message'), '. ');
            return ['ok' => false, 'error' => $err . '. The message is waiting in your Outbox in Mail.', 'email_id' => $emailId, 'activity_id' => null];
        }
        // Activity on the account (the full text stays in Mail)
        $contact = (int)($in['contact_id'] ?? 0);
        if ($contact > 0) {
            $c = $db->prepare("SELECT id FROM crm_contacts WHERE id = ? AND company_id = ? AND account_id = ?");
            $c->execute([$contact, $companyId, $accountId]);
            if (!$c->fetchColumn()) $contact = 0;
        }
        $deal = (int)($in['opportunity_id'] ?? 0);
        if ($deal > 0) {
            $d = $db->prepare("SELECT id FROM crm_opportunities WHERE id = ? AND company_id = ? AND account_id = ?");
            $d->execute([$deal, $companyId, $accountId]);
            if (!$d->fetchColumn()) $deal = 0;
        }
        $db->prepare("INSERT INTO crm_interactions (company_id, account_id, contact_id, opportunity_id, type, subject, body, via, created_by, created_at)
                      VALUES (?, ?, ?, ?, 'email', ?, ?, 'mail', ?, NOW())")
           ->execute([$companyId, $accountId, $contact ?: null, $deal ?: null, mb_substr('Emailed: ' . $subject, 0, 190),
                      mb_substr('To ' . implode(', ', $to) . "\n\n" . $body, 0, 20000), $userId]);
        return ['ok' => true, 'error' => null, 'email_id' => $emailId, 'activity_id' => (int)$db->lastInsertId()];
    }

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

    private static function addresses($v): array
    {
        $list = is_array($v) ? $v : preg_split('/[,;\s]+/', (string)$v);
        return array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $list ?: []))));
    }
}
