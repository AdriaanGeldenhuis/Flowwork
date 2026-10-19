<?php
/**
 * QiMail — emails a quote, invoice, credit note or payment reminder to the
 * customer through Flowwork Mail: from the sending user's own mailbox (SMTP),
 * with the PDF attached, filed in their Sent folder and linked to the
 * document, so replies thread in Mail. The result written to qi_email_log is
 * what really happened (sent, or failed with the reason).
 *
 * compose() gives the editable defaults (recipients, subject, message) from
 * the company's email templates (qi_email_templates) or built-in wording.
 * Placeholders: {customer} {first_name} {number} {total} {balance} {due_date}
 * {valid_until} {company} {sender} {link}
 */

require_once __DIR__ . '/../../mail/lib/autoload.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/QiMoney.php';
require_once __DIR__ . '/../lib/QiActivity.php';
require_once __DIR__ . '/DocumentPdfService.php';

final class QiMail
{
    public const KINDS = ['quote', 'invoice', 'credit_note', 'reminder'];

    private const DEFAULTS = [
        'quote' => [
            'subject' => 'Quote {number} from {company}',
            'body' => "Hi {first_name},\n\nThank you for the opportunity. Please find our quote {number} for {total} attached — it is valid until {valid_until}.\n\nYou can view and accept it online: {link}\n\nKind regards\n{sender}",
        ],
        'invoice' => [
            'subject' => 'Invoice {number} from {company}',
            'body' => "Hi {first_name},\n\nPlease find invoice {number} for {total} attached, due on {due_date}.\n\nYou can view it online: {link}\n\nThank you for your business.\n\nKind regards\n{sender}",
        ],
        'credit_note' => [
            'subject' => 'Credit note {number} from {company}',
            'body' => "Hi {first_name},\n\nPlease find credit note {number} for {total} attached.\n\nKind regards\n{sender}",
        ],
        'reminder' => [
            'subject' => 'Reminder: invoice {number} — {balance} outstanding',
            'body' => "Hi {first_name},\n\nThis is a friendly reminder that invoice {number} for {balance} was due on {due_date}. If you have already paid, thank you and please ignore this message.\n\nYou can view it online: {link}\n\nKind regards\n{sender}",
        ],
    ];

    /** The user's mailboxes that can send. */
    public static function accounts(PDO $db, int $companyId, int $userId): array
    {
        $out = [];
        try {
            foreach (MailRepo::accounts($db, $companyId, $userId, true) as $a) {
                if (trim((string)$a['smtp_server']) === '') continue;
                $out[] = ['id' => (int)$a['account_id'], 'email' => (string)$a['email_address'], 'name' => (string)($a['account_name'] ?: $a['email_address'])];
            }
        } catch (Throwable $e) {
            error_log('[qi] mail accounts: ' . $e->getMessage());
        }
        return $out;
    }

    /** Editable defaults for the send dialog. */
    public static function compose(PDO $db, int $companyId, int $userId, string $kind, int $docId): ?array
    {
        if (!in_array($kind, self::KINDS, true)) return null;
        $type = $kind === 'reminder' ? 'invoice' : $kind;
        $m = QiDocument::load($db, $companyId, $type, $docId);
        if (!$m) return null;
        $tpl = self::template($db, $companyId, $kind);
        $vars = self::vars($db, $userId, $m);
        $to = [];
        if ($m['customer']['email'] !== '') $to[] = $m['customer']['email'];
        // the account's billing address too, when the attention contact has their own
        $st = $db->prepare("SELECT email FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([(int)$m['customer']['id'], $companyId]);
        $acct = trim((string)$st->fetchColumn());
        if ($acct !== '' && !in_array(strtolower($acct), array_map('strtolower', $to), true)) $to[] = $acct;
        return [
            'kind' => $kind,
            'to' => array_slice($to, 0, 1),
            'suggestions' => self::recipientSuggestions($db, $companyId, (int)$m['customer']['id']),
            'subject' => self::fill($tpl['subject'], $vars),
            'body' => self::fill($tpl['body'], $vars),
            'accounts' => self::accounts($db, $companyId, $userId),
            'filename' => DocumentPdfService::safeCode($m['number']) . '.pdf',
            'number' => $m['number'],
        ];
    }

    /**
     * Send. $opts: to (string|array), cc, subject, body (plain text), account_id, attach (default true).
     * Empty subject/body use the defaults. Never throws for delivery problems — returns ok=false with the reason.
     * @return array{ok:bool, error:?string, email_id:?int, to:array}
     */
    public static function sendDocument(PDO $db, int $companyId, int $userId, string $kind, int $docId, array $opts): array
    {
        $type = $kind === 'reminder' ? 'invoice' : $kind;
        $compose = self::compose($db, $companyId, $userId, $kind, $docId);
        if ($compose === null) return ['ok' => false, 'error' => 'Document not found', 'email_id' => null, 'to' => []];

        $to = self::addresses($opts['to'] ?? $compose['to']);
        $cc = self::addresses($opts['cc'] ?? []);
        $subject = trim((string)($opts['subject'] ?? '')) ?: $compose['subject'];
        $body = trim((string)($opts['body'] ?? '')) ?: $compose['body'];
        $logKind = $kind;
        if (!$to) return self::fail($db, $companyId, $logKind, $docId, '', $subject, $body, 'Add the customer\'s email address.');
        if (count($to) + count($cc) > 20) return self::fail($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body, 'Send to at most 20 people at once.');

        $accounts = self::accounts($db, $companyId, $userId);
        $accountId = (int)($opts['account_id'] ?? 0);
        $account = null;
        foreach ($accounts as $a) if ($a['id'] === $accountId || ($accountId === 0 && $account === null)) $account = $a;
        if (!$account) {
            return self::fail($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body,
                'Connect your mailbox in Mail (with an outgoing SMTP server) to email documents from Flowwork.');
        }

        try {
            $pdf = null;
            if (($opts['attach'] ?? true) !== false) {
                $pdf = DocumentPdfService::render($db, $companyId, $type, $docId);
                if ($pdf === null || empty($pdf['bytes'])) throw new RuntimeException('The PDF could not be produced.');
            }
            $html = self::html($db, $companyId, $userId, $account['id'], $body);
            $draft = MailOutbox::saveDraft($db, $companyId, $userId, [
                'account_id' => $account['id'], 'to' => $to, 'cc' => $cc, 'subject' => $subject, 'body_html' => $html,
            ]);
            $emailId = (int)$draft['email_id'];
            if ($pdf) {
                MailStore::storeAttachment($db, $companyId, $account['id'], $emailId, $pdf['filename'], 'application/pdf', $pdf['bytes']);
                $db->prepare("UPDATE emails SET has_attachments = 1 WHERE email_id = ?")->execute([$emailId]);
            }
            try {
                $db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at, company_id) VALUES (?, ?, ?, ?, NOW(), ?)")
                   ->execute([$emailId, $type, $docId, $userId, $companyId]);
            } catch (Throwable $e) {
                error_log('[qi] email link: ' . $e->getMessage());
            }
            $res = MailOutbox::queue($db, $companyId, $userId, $emailId, null, 0);
            if (empty($res['sent'])) {
                $err = (string)($res['error'] ?? 'The mail server did not accept the message.');
                self::log($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body, 'failed', $err);
                return ['ok' => false, 'error' => rtrim($err, '. ') . '. The message is waiting in your Outbox in Mail.', 'email_id' => $emailId, 'to' => $to];
            }
        } catch (MailUserError $e) {
            return self::fail($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[qi] send ' . $kind . ' #' . $docId . ': ' . $e->getMessage());
            return self::fail($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body, 'The email could not be sent. Please try again.');
        }
        self::log($db, $companyId, $logKind, $docId, implode(', ', $to), $subject, $body, 'sent', null);
        return ['ok' => true, 'error' => null, 'email_id' => $emailId, 'to' => $to];
    }

    /** Emails sent for a document, newest first. */
    public static function history(PDO $db, int $companyId, string $type, int $docId): array
    {
        $kinds = $type === 'invoice' ? ['invoice', 'reminder'] : [$type];
        $in = implode(',', array_fill(0, count($kinds), '?'));
        try {
            $st = $db->prepare("SELECT to_email, subject, sent_at, status, error_message, entity_type FROM qi_email_log
                                 WHERE company_id = ? AND entity_id = ? AND entity_type IN ($in) ORDER BY id DESC LIMIT 50");
            $st->execute(array_merge([$companyId, $docId], $kinds));
            return array_map(fn($r) => ['to' => (string)$r['to_email'], 'subject' => (string)$r['subject'], 'at' => (string)$r['sent_at'],
                                         'status' => (string)$r['status'], 'error' => (string)($r['error_message'] ?? ''), 'reminder' => $r['entity_type'] === 'reminder'],
                             $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------

    private static function template(PDO $db, int $companyId, string $kind): array
    {
        $tpl = self::DEFAULTS[$kind];
        $type = ['quote' => 'quote', 'invoice' => 'invoice', 'reminder' => 'payment_reminder'][$kind] ?? null;
        if ($type === null) return $tpl;
        try {
            $st = $db->prepare("SELECT subject, body_plain, body_html FROM qi_email_templates WHERE company_id = ? AND template_type = ? AND active = 1 ORDER BY id DESC LIMIT 1");
            $st->execute([$companyId, $type]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $body = trim((string)($r['body_plain'] ?? ''));
                if ($body === '' && trim((string)$r['body_html']) !== '') $body = trim(HtmlSanitizer::toText((string)$r['body_html']));
                if (trim((string)$r['subject']) !== '') $tpl['subject'] = (string)$r['subject'];
                if ($body !== '') $tpl['body'] = $body;
            }
        } catch (Throwable $e) {
            // table missing: built-in wording
        }
        return $tpl;
    }

    private static function vars(PDO $db, int $userId, array $m): array
    {
        $st = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $sender = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $m['company']['name'];
        $person = $m['customer']['contact'] !== '' ? $m['customer']['contact'] : '';
        $first = $person !== '' ? strtok($person, ' ') : 'there';
        $date = fn($d) => $d ? date('j F Y', strtotime((string)$d)) : '';
        $t = $m['totals'];
        if (empty($m['links']['public']) && $m['id'] > 0 && in_array($m['type'], ['quote', 'invoice'], true)) {
            $m['links']['public'] = self::ensurePublicLink($db, (int)$m['company']['id'], $m['type'], $m['id']);
        }
        $total = QiMoney::format((float)$t['total'], $m['symbol']);
        $balance = QiMoney::format((float)($t['balance'] ?? $t['total']), $m['symbol']);
        $link = (string)($m['links']['public'] ?? '');
        $bank = $m['company']['bank'] ?? [];
        $v = [
            '{customer}' => $m['customer']['name'],
            '{first_name}' => $first,
            '{number}' => $m['number'],
            '{total}' => $total,
            '{balance}' => $balance,
            '{due_date}' => $date($m['due_date'] ?? null),
            '{valid_until}' => $date($m['expiry_date'] ?? null),
            '{company}' => $m['company']['name'],
            '{sender}' => $sender,
            '{link}' => $link,
        ];
        // the placeholders of templates written for the previous editor
        $v += [
            '{QUOTE_NUMBER}' => $m['number'], '{INVOICE_NUMBER}' => $m['number'], '{CREDIT_NOTE_NUMBER}' => $m['number'], '{DOCUMENT_NUMBER}' => $m['number'],
            '{COMPANY_NAME}' => $m['company']['name'], '{CUSTOMER_NAME}' => $m['customer']['name'], '{CONTACT_NAME}' => $person !== '' ? $person : $m['customer']['name'],
            '{AMOUNT}' => $m['type'] === 'invoice' ? $balance : $total, '{TOTAL}' => $total, '{BALANCE}' => $balance,
            '{EXPIRY_DATE}' => $v['{valid_until}'], '{VALID_UNTIL}' => $v['{valid_until}'], '{DUE_DATE}' => $v['{due_date}'], '{ISSUE_DATE}' => $date($m['issue_date'] ?? null),
            '{BANK_NAME}' => (string)($bank['name'] ?? ''), '{BANK_ACCOUNT}' => (string)($bank['account'] ?? ''), '{BANK_BRANCH}' => (string)($bank['branch'] ?? ''),
            '{BANK_HOLDER}' => (string)($bank['holder'] ?? ''), '{LINK}' => $link, '{PUBLIC_LINK}' => $link, '{VIEW_LINK}' => $link, '{SENDER_NAME}' => $sender,
        ];
        return $v;
    }

    /** A public link token for a quote or invoice (created on first use). */
    public static function ensurePublicLink(PDO $db, int $companyId, string $type, int $id): ?string
    {
        if (!in_array($type, ['quote', 'invoice'], true)) return null;
        $table = $type === 'quote' ? 'quotes' : 'invoices';
        $st = $db->prepare("SELECT public_token FROM `$table` WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $token = $st->fetchColumn();
        if ($token === false) return null;
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $db->prepare("UPDATE `$table` SET public_token = ?, updated_at = updated_at WHERE id = ? AND company_id = ? AND (public_token IS NULL OR public_token = '')")
               ->execute([$token, $id, $companyId]);
            $st->execute([$id, $companyId]);
            $token = (string)$st->fetchColumn();
        }
        return QiDocument::url('/qi/p/' . $type . '.php?token=' . rawurlencode((string)$token));
    }

    private static function fill(string $s, array $vars): string
    {
        // a line built around a value that is empty ("Bank: ", "View it online: ") is dropped
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $s)) as $line) {
            $filled = strtr($line, $vars);
            $usedEmpty = false;
            foreach ($vars as $k => $val) {
                if ($val === '' && str_contains($line, $k)) { $usedEmpty = true; break; }
            }
            if ($usedEmpty && (preg_match('/:\s*$/', $filled) || trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $filled) ?? '') === '' || preg_match('/\{(link|LINK|PUBLIC_LINK|VIEW_LINK)\}/', $line))) continue;
            $lines[] = $filled;
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
    }

    /** Plain-text message → simple, safe HTML (links clickable), then the user's signature. */
    private static function html(PDO $db, int $companyId, int $userId, int $accountId, string $body): string
    {
        $paras = preg_split("/\n{2,}/", trim($body)) ?: [];
        $html = '';
        foreach ($paras as $p) {
            $e = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $e = preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)\]])#i', '<a href="$1">$1</a>', $e) ?? $e;
            $html .= '<p style="margin:0 0 14px">' . nl2br($e, false) . '</p>';
        }
        try {
            $st = $db->prepare("SELECT s.content_html FROM email_accounts a JOIN email_signatures s ON s.signature_id = a.default_signature_id AND s.company_id = a.company_id
                                 WHERE a.account_id = ? AND a.company_id = ? AND a.user_id = ? AND (s.user_id = a.user_id OR s.user_id IS NULL)");
            $st->execute([$accountId, $companyId, $userId]);
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
        $out = [];
        foreach ($list ?: [] as $a) {
            $a = trim((string)$a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($a), array_map('strtolower', $out), true)) $out[] = $a;
        }
        return $out;
    }

    private static function recipientSuggestions(PDO $db, int $companyId, int $customerId): array
    {
        $out = [];
        $st = $db->prepare("SELECT email, name FROM crm_accounts WHERE id = ? AND company_id = ? AND email IS NOT NULL AND email <> ''");
        $st->execute([$customerId, $companyId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = ['email' => $r['email'], 'name' => $r['name']];
        $st = $db->prepare("SELECT email, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, role_title FROM crm_contacts
                             WHERE account_id = ? AND company_id = ? AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, first_name LIMIT 20");
        $st->execute([$customerId, $companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array(strtolower($r['email']), array_map(fn($x) => strtolower($x['email']), $out), true)) {
                $out[] = ['email' => $r['email'], 'name' => $r['name'] . ($r['role_title'] ? ' — ' . $r['role_title'] : '')];
            }
        }
        return $out;
    }

    private static function fail(PDO $db, int $companyId, string $kind, int $docId, string $to, string $subject, string $body, string $error): array
    {
        self::log($db, $companyId, $kind, $docId, $to, $subject, $body, 'failed', $error);
        return ['ok' => false, 'error' => $error, 'email_id' => null, 'to' => $to === '' ? [] : explode(', ', $to)];
    }

    private static function log(PDO $db, int $companyId, string $kind, int $docId, string $to, string $subject, string $body, string $status, ?string $error): void
    {
        try {
            $db->prepare("INSERT INTO qi_email_log (company_id, entity_type, entity_id, to_email, subject, body_preview, sent_at, status, error_message)
                          VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)")
               ->execute([$companyId, $kind, $docId, mb_substr($to !== '' ? $to : '—', 0, 190), mb_substr($subject, 0, 255), mb_substr($body, 0, 500), $status, $error]);
        } catch (Throwable $e) {
            error_log('[qi] email log: ' . $e->getMessage());
        }
    }
}
