<?php
// POST /mail/api/send.php
//   {op:"send", draft_id, send_at?}  queue (undo window from prefs) or schedule (unix seconds)
//   {op:"undo"|"cancel", email_id}   back to Drafts
//   {op:"flush"}                     send whatever is due now (called after the undo window)
//   {op:"retry", email_id}           re-queue a failed send
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$op = mail_str($in, 'op', 10) ?: 'send';

switch ($op) {
    case 'send':
        mail_rate_limit('send', 60, 600);
        $prefs = MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);
        $sendAt = isset($in['send_at']) && $in['send_at'] !== '' && $in['send_at'] !== null ? (int)$in['send_at'] : null;
        $res = MailOutbox::queue($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, (int)($in['draft_id'] ?? 0), $sendAt,
                                 (int)$prefs['undo_send_seconds']);
        if (array_key_exists('sent', $res) && !$res['sent']) {
            mail_error('Not sent: ' . ($res['error'] ?? 'unknown error'), 502, ['email_id' => $res['email_id']]);
        }
        mail_json($res + ['undo_seconds' => $sendAt ? 0 : (int)$prefs['undo_send_seconds'],
                          'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);

    case 'undo':
    case 'cancel':
        $id = (int)($in['email_id'] ?? 0);
        if (!MailOutbox::cancel($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $id)) {
            mail_error($op === 'undo' ? 'Too late — the message has already been sent' : 'This message can no longer be cancelled', 409);
        }
        mail_json(['draft_id' => $id, 'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);

    case 'retry':
        $id = (int)($in['email_id'] ?? 0);
        $row = MailRepo::email($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $id);
        if (!$row || $row['folder'] !== 'Outbox' || $row['send_status'] !== 'failed') mail_error('Nothing to retry', 409);
        $DB->prepare("UPDATE emails SET send_status = 'queued', send_error = NULL, scheduled_at = NOW() WHERE email_id = ?")->execute([$id]);
        $r = MailOutbox::sendOne($DB, $id);
        if (!$r['ok']) mail_error('Not sent: ' . $r['error'], 502);
        mail_json(['sent' => true, 'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);

    case 'flush':
        $results = MailOutbox::flushDue($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, 10);
        $sent = [];
        $failed = [];
        foreach ($results as $id => $r) {
            if (!empty($r['skipped'])) continue;
            if ($r['ok']) $sent[] = $id; else $failed[] = ['email_id' => $id, 'error' => $r['error']];
        }
        mail_json(['sent' => $sent, 'failed' => $failed, 'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);
}
mail_error('Unknown operation');
