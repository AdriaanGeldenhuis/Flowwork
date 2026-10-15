<?php
// POST /mail/api/sync.php {force?:bool, account_id?}
// Pulls new mail for the user's accounts (time-boxed) and sends anything due.
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$force = mail_bool($in, 'force');
if ($force) mail_rate_limit('sync_force', 6, 60);
@set_time_limit(60);
@ini_set('memory_limit', '256M');

$sent = MailOutbox::flushDue($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, 10);

$accountId = (int)($in['account_id'] ?? 0);
if ($accountId) {
    $acc = MailRepo::account($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $accountId);
    if (!$acc) mail_error('Account not found', 404);
    $r = MailSyncService::syncAccount($DB, $acc, ['time_budget' => 25]);
    $res = ['new' => $r['new'], 'synced' => empty($r['skipped']) ? 1 : 0,
            'errors' => $r['ok'] ? [] : [['account_id' => $accountId, 'account' => $acc['account_name'], 'error' => $r['error']]]];
} else {
    $res = MailSyncService::syncUser($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $force, 25);
}

$accounts = [];
foreach (MailRepo::accounts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID) as $a) {
    $accounts[] = ['account_id' => (int)$a['account_id'], 'last_sync_at' => MailRepo::iso($a['last_sync_at']), 'last_error' => $a['last_error']];
}
mail_json($res + [
    'sent'     => count(array_filter($sent, fn($r) => !empty($r['ok']))),
    'counts'   => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID),
    'accounts' => $accounts,
]);
