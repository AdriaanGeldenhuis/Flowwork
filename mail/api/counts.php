<?php
// GET /mail/api/counts.php — unread counters + account sync status (polled).
require_once __DIR__ . '/_bootstrap.php';

$accounts = [];
foreach (MailRepo::accounts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID) as $a) {
    $accounts[] = [
        'account_id'   => (int)$a['account_id'],
        'last_sync_at' => MailRepo::iso($a['last_sync_at']),
        'last_error'   => $a['last_error'],
        'syncing'      => $a['sync_lock_until'] && strtotime($a['sync_lock_until']) > time(),
    ];
}
mail_json(['counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID), 'accounts' => $accounts]);
