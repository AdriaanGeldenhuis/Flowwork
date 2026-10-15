<?php
// GET /mail/api/compose.php — prefill for the composer.
//   ?mode=new[&template_id=&to=]
//   ?mode=reply|reply_all|forward&email_id=…   (or &key=t123 → latest message)
//   ?mode=draft&email_id=…                     (resume a draft / failed or scheduled send)
require_once __DIR__ . '/_bootstrap.php';

$mode = (string)($_GET['mode'] ?? 'new');
$accounts = MailRepo::accounts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, true);
if (!$accounts) mail_error('Add an email account in Mail settings before composing', 400);
$mine = array_map(fn($a) => strtolower($a['email_address']), $accounts);
$activeIds = array_map(fn($a) => (int)$a['account_id'], $accounts);

$out = [
    'mode' => $mode, 'account_id' => (int)$accounts[0]['account_id'], 'to' => [], 'cc' => [], 'bcc' => [],
    'subject' => '', 'body_html' => '', 'in_reply_to' => null, 'references' => null, 'thread_key' => null,
    'draft_id' => null, 'attachments' => [], 'copy_attachments_from' => null, 'quote' => '',
    'scheduled_at' => null, 'send_error' => null,
];

$attList = function (int $emailId) use ($DB): array {
    $list = [];
    foreach (MailRepo::attachmentsFor($DB, [$emailId])[$emailId] ?? [] as $a) {
        if ((int)$a['is_inline'] === 1) continue;
        $list[] = ['attachment_id' => (int)$a['attachment_id'], 'name' => $a['file_name'], 'size' => (int)$a['file_size'],
                   'mime' => $a['mime_type'], 'url' => '/mail/api/attachment.php?id=' . (int)$a['attachment_id']];
    }
    return $list;
};

$source = null;
if (in_array($mode, ['reply', 'reply_all', 'forward', 'draft'], true)) {
    $emailId = (int)($_GET['email_id'] ?? 0);
    if (!$emailId && !empty($_GET['key'])) {
        $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [(string)$_GET['key']], 'visible');
        $rows = array_values(array_filter($rows, fn($r) => $r['folder'] !== 'Drafts'));
        if ($rows) $emailId = (int)end($rows)['email_id'];
    }
    $source = $emailId ? MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $emailId) : null;
    if (!$source) mail_error('Message not found', 404);
}

if ($mode === 'draft') {
    if (!in_array($source['folder'], ['Drafts', 'Outbox'], true)) mail_error('That message is not a draft');
    $out['draft_id'] = (int)$source['email_id'];
    $out['account_id'] = (int)$source['account_id'];
    $out['to'] = MimeParser::parseAddressList((string)$source['to_recipients']);
    $out['cc'] = MimeParser::parseAddressList((string)$source['cc_recipients']);
    $out['bcc'] = MimeParser::parseAddressList((string)$source['bcc_recipients']);
    $out['subject'] = (string)$source['subject'];
    $out['body_html'] = HtmlSanitizer::sanitize((string)$source['body_html'], ['block_remote' => false, 'allow_local' => true, 'for_editor' => true])['html'];
    $out['in_reply_to'] = $source['in_reply_to'];
    $out['references'] = $source['ref_message_ids'];
    $out['thread_key'] = (int)$source['thread_id'] > 0 ? 't' . (int)$source['thread_id'] : null;
    $out['attachments'] = $attList((int)$source['email_id']);
    $out['scheduled_at'] = MailRepo::iso($source['scheduled_at']);
    $out['send_error'] = $source['send_error'];
    $out['outbox_status'] = $source['folder'] === 'Outbox' ? $source['send_status'] : null;
    mail_json($out);
}

if ($source) {
    if (in_array((int)$source['account_id'], $activeIds, true)) $out['account_id'] = (int)$source['account_id'];

    $from = MimeParser::parseAddressList((string)$source['sender']);
    if (!$from && $source['sender']) $from = [['email' => strtolower((string)$source['sender']), 'name' => '']];
    if ($from && $from[0]['name'] === '' && !empty($source['sender_name'])) $from[0]['name'] = (string)$source['sender_name'];
    $fromName = MailRepo::displayName($source['sender_name'], $source['sender']);
    $to = MimeParser::parseAddressList((string)$source['to_recipients']);
    $cc = MimeParser::parseAddressList((string)$source['cc_recipients']);
    $replyTo = MimeParser::parseAddressList((string)$source['reply_to']);
    $outgoing = $source['direction'] === 'outgoing';
    $notMine = fn(array $list) => array_values(array_filter($list, fn($a) => !in_array($a['email'], $mine, true)));

    $subject = (string)$source['subject'];
    $date = $source['sent_at'] ? date('D, j M Y \a\t H:i', strtotime($source['sent_at'])) : '';

    // Body of the original, sanitised (images kept so the quote looks right).
    $cidMap = [];
    foreach (MailRepo::attachmentsFor($DB, [(int)$source['email_id']])[(int)$source['email_id']] ?? [] as $a) {
        if ($a['content_id']) $cidMap[strtolower($a['content_id'])] = '/mail/api/attachment.php?id=' . (int)$a['attachment_id'] . '&inline=1';
    }
    $origHtml = (string)$source['body_html'] !== ''
        ? HtmlSanitizer::sanitize((string)$source['body_html'], ['block_remote' => false, 'cid_map' => $cidMap, 'for_editor' => true])['html']
        : HtmlSanitizer::fromText((string)$source['body_text']);

    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $fromLabel = $e($fromName) . ($from ? ' &lt;' . $e($from[0]['email']) . '&gt;' : '');

    if ($mode === 'forward') {
        $out['subject'] = preg_match('/^\s*(fwd?|fw)\s*:/i', $subject) ? $subject : 'Fwd: ' . $subject;
        $out['quote'] = '<div data-fw-quote="1"><p>---------- Forwarded message ---------<br>'
            . 'From: ' . $fromLabel . '<br>'
            . 'Date: ' . $e($date) . '<br>'
            . 'Subject: ' . $e($subject) . '<br>'
            . 'To: ' . $e(MimeParser::formatAddressList($to)) . '</p>' . $origHtml . '</div>';
        $out['copy_attachments_from'] = (int)$source['email_id'];
        $out['attachments'] = $attList((int)$source['email_id']);
    } else {
        $out['subject'] = preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: ' . $subject;
        if ($outgoing) {
            $primary = $to;                         // replying to my own message → same recipients
        } else {
            $primary = $replyTo ?: $from;
        }
        $out['to'] = $notMine($primary) ?: $primary;
        if ($mode === 'reply_all') {
            $already = array_column($out['to'], 'email');
            $extra = $outgoing ? $cc : array_merge($to, $cc);
            $extra = array_values(array_filter($notMine($extra), fn($a) => !in_array($a['email'], $already, true)));
            $seen = [];
            $out['cc'] = array_values(array_filter($extra, function ($a) use (&$seen) {
                if (isset($seen[$a['email']])) return false;
                return $seen[$a['email']] = true;
            }));
        }
        $out['quote'] = '<div data-fw-quote="1"><p>On ' . $e($date) . ', ' . $fromLabel . ' wrote:</p>'
            . '<blockquote style="margin:0 0 0 .8ex;border-left:2px solid #d6d0ca;padding-left:1ex;color:#55504b">'
            . $origHtml . '</blockquote></div>';
    }
    $refs = trim(((string)$source['ref_message_ids']) . ' ' . ($source['message_id'] ? '<' . $source['message_id'] . '>' : ''));
    $out['in_reply_to'] = $source['message_id'];
    $out['references'] = $refs !== '' ? $refs : null;
    $out['thread_key'] = (int)$source['thread_id'] > 0 ? 't' . (int)$source['thread_id'] : null;
}

// New message from a template or a mailto-style "to".
if ($mode === 'new') {
    if (!empty($_GET['to'])) {
        $out['to'] = MimeBuilder::normalizeList([(string)$_GET['to']]);
    }
    if (!empty($_GET['subject'])) $out['subject'] = mb_substr((string)$_GET['subject'], 0, 500);
    $tplId = (int)($_GET['template_id'] ?? 0);
    if ($tplId) {
        $st = $DB->prepare("SELECT subject, body_html FROM email_templates WHERE template_id = ? AND company_id = ?");
        $st->execute([$tplId, $MAIL_COMPANY_ID]);
        if ($t = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['subject'] = (string)$t['subject'];
            $out['body_html'] = HtmlSanitizer::sanitize(HtmlSanitizer::ensureHtml((string)$t['body_html']), ['block_remote' => false, 'for_editor' => true])['html'];
            $out['template_id'] = $tplId;
        }
    }
}

mail_json($out);
