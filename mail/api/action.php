<?php
// POST /mail/api/action.php {keys:[...], action, until?, view?}
// read | unread | star | unstar | archive | inbox | trash | restore | spam | not_spam
// | delete_forever | snooze (until = unix seconds) | unsnooze
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$keys = mail_keys($in);
$action = mail_str($in, 'action', 32);
if (!in_array($action, MailActions::ACTIONS, true)) mail_error('Unknown action');
$view = mail_str($in, 'view', 20);

$args = [];
if ($action === 'snooze') {
    $until = (int)($in['until'] ?? 0);
    if ($until < time() + 60 || $until > time() + 366 * 86400) mail_error('Pick a time in the future');
    $args['until'] = date('Y-m-d H:i:s', $until);
}

// Read/unread/star act on what you can see; moves act on the whole conversation
// (each action filters by folder itself, e.g. archive only touches INBOX copies).
// In Trash, Spam, Drafts and Outbox a key means only the copies listed there —
// discarding a reply draft must not trash the conversation it belongs to.
$scope = in_array($action, ['restore', 'delete_forever'], true) ? 'all'
       : (in_array($view, ['trash', 'spam', 'drafts', 'outbox'], true) ? $view : 'visible');
if ($action === 'not_spam') $scope = 'spam';
$rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $keys, $scope);
if (!$rows) mail_error('Conversation not found', 404);

if ($action === 'delete_forever' && !in_array($view, ['trash', 'spam', 'drafts'], true)) {
    mail_error('Only messages in Trash, Spam or Drafts can be deleted forever');
}
if ($action === 'delete_forever' || $action === 'restore') {
    $want = $action === 'restore' ? ['Trash'] : ($view === 'drafts' ? ['Drafts'] : [ucfirst($view)]);
    $rows = array_values(array_filter($rows, fn($r) => in_array($r['folder'], $want, true)));
}

// Group per conversation so "star" stars each conversation's latest message.
$byKey = [];
foreach ($rows as $r) $byKey[MailRepo::keyFor($r)][] = $r;
$n = 0;
foreach ($byKey as $group) {
    $n += MailActions::apply($DB, $group, $action, $args);
}

mail_json(['affected' => $n, 'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);
