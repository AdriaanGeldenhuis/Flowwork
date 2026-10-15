<?php
// POST /mail/api/draft.php
//   {op:"save", draft_id?, account_id, to[], cc[], bcc[], subject, body_html, thread_key?, in_reply_to?,
//    references?, copy_attachments_from?}  → {draft_id}
//   {op:"discard", draft_id}
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$op = mail_str($in, 'op', 10) ?: 'save';

if ($op === 'discard') {
    $row = MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)($in['draft_id'] ?? 0));
    if ($row && ($row['folder'] === 'Drafts' || ($row['folder'] === 'Outbox' && $row['send_status'] === 'failed'))) {
        MailStore::deleteEmails($DB, [(int)$row['email_id']]);
    }
    mail_json(['counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);
}

mail_rate_limit('draft', 600, 600);
$isNew = empty($in['draft_id']);
$res = MailOutbox::saveDraft($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [
    'draft_id'    => (int)($in['draft_id'] ?? 0),
    'account_id'  => (int)($in['account_id'] ?? 0),
    'to'          => is_array($in['to'] ?? null) ? $in['to'] : [(string)($in['to'] ?? '')],
    'cc'          => is_array($in['cc'] ?? null) ? $in['cc'] : [(string)($in['cc'] ?? '')],
    'bcc'         => is_array($in['bcc'] ?? null) ? $in['bcc'] : [(string)($in['bcc'] ?? '')],
    'subject'     => (string)($in['subject'] ?? ''),
    'body_html'   => (string)($in['body_html'] ?? ''),
    'thread_key'  => (string)($in['thread_key'] ?? ''),
    'in_reply_to' => (string)($in['in_reply_to'] ?? ''),
    'references'  => (string)($in['references'] ?? ''),
]);

// Forward: carry the original's files into the new draft (once).
$copied = [];
if ($isNew && !empty($in['copy_attachments_from'])) {
    $src = MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)$in['copy_attachments_from']);
    if ($src) {
        $draft = MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $res['email_id']);
        $skip = array_map('intval', (array)($in['skip_attachments'] ?? []));
        foreach (MailRepo::attachmentsFor($DB, [(int)$src['email_id']])[(int)$src['email_id']] ?? [] as $a) {
            if ((int)$a['is_inline'] === 1 || in_array((int)$a['attachment_id'], $skip, true)) continue;
            $full = $DB->prepare("SELECT * FROM email_attachments WHERE attachment_id = ?");
            $full->execute([(int)$a['attachment_id']]);
            $newId = MailStore::copyAttachment($DB, $full->fetch(PDO::FETCH_ASSOC), $MAIL_COMPANY_ID, (int)$draft['account_id'], $res['email_id']);
            if ($newId) $copied[(int)$a['attachment_id']] = $newId;
        }
    }
}

$atts = [];
foreach (MailRepo::attachmentsFor($DB, [$res['email_id']])[$res['email_id']] ?? [] as $a) {
    if ((int)$a['is_inline'] === 1) continue;
    $atts[] = ['attachment_id' => (int)$a['attachment_id'], 'name' => $a['file_name'], 'size' => (int)$a['file_size'],
               'mime' => $a['mime_type'], 'url' => '/mail/api/attachment.php?id=' . (int)$a['attachment_id']];
}
mail_json(['draft_id' => $res['email_id'], 'thread_key' => $res['thread_key'], 'attachments' => $atts, 'copied' => $copied,
           'saved_at' => date('c')]);
