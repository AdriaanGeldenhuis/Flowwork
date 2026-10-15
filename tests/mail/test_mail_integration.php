<?php
/**
 * Mail end-to-end test against a real IMAP server (Dovecot) and an SMTP sink.
 *
 * Requires (see tests/mail/README.md):
 *   - the flowwork_test database (tests/db/setup.sh)
 *   - Dovecot on 127.0.0.1:1993 (IMAPS, self-signed) with user test@example.com / secret
 *     and an EMPTY mailbox, special-use folders Sent/Trash/Junk/Archive/Drafts
 *   - tests/mail/fake_smtp.py on 127.0.0.1:2525 writing to $MAIL_TEST_SMTP_OUT
 *
 * Usage: MAIL_TEST_SMTP_OUT=/path php tests/mail/test_mail_integration.php
 */

require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/mail/lib/autoload.php';

$smtpOut = getenv('MAIL_TEST_SMTP_OUT') ?: '/tmp/fake_smtp';
$imapPort = (int)(getenv('MAIL_TEST_IMAP_PORT') ?: 1993);

foreach ([[$imapPort, 'IMAP'], [2525, 'SMTP']] as [$port, $what]) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 2);
    if (!$s) { echo "SKIP: $what test server not running on $port\n"; exit(0); }
    fclose($s);
}

$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

// ---------------------------------------------------------------- schema
$cols = $DB->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['imap_folder', 'sender_name', 'snoozed_until', 'scheduled_at', 'send_status', 'list_unsubscribe'] as $c) {
    assert_true(in_array($c, $cols, true), "emails.$c provisioned");
}
$idx = $DB->query("SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails' GROUP BY INDEX_NAME, NON_UNIQUE")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_true(!isset($idx['uq_emails_message_id']), 'global unique message_id dropped');
assert_true(isset($idx['uq_emails_account_msgid']) && (int)$idx['uq_emails_account_msgid'] === 0, 'per-account unique message_id');
assert_true(isset($idx['uq_emails_imap']), 'unique (account, imap_folder, uid)');
$lcols = $DB->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_links'")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('company_id', $lcols, true), 'email_links.company_id provisioned');

// ---------------------------------------------------------------- clean slate
foreach (['emails', 'email_threads', 'email_attachments', 'email_tag_map', 'email_links', 'email_folder_state',
          'email_imap_ops', 'email_contacts', 'email_send_log', 'email_sync_log'] as $t) {
    $DB->exec("DELETE FROM $t");
}
$DB->exec("DELETE FROM email_accounts");
$DB->exec("DELETE FROM email_rules WHERE company_id = " . TEST_COMPANY_ID);
foreach (glob($smtpOut . '/*') ?: [] as $f) @unlink($f);

$DB->prepare("INSERT INTO email_accounts (company_id, user_id, account_name, display_name, email_address, imap_server, imap_port,
              imap_encryption, smtp_server, smtp_port, smtp_encryption, username, password_encrypted, tls_verify, is_active)
              VALUES (?, ?, 'Test', 'Test User', 'test@example.com', '127.0.0.1', ?, 'ssl', '127.0.0.1', 2525, 'none',
                      'test@example.com', ?, 0, 1)")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID, $imapPort, SecureVault::encrypt('secret')]);
$accountId = (int)$DB->lastInsertId();
$account = MailRepo::account($DB, TEST_COMPANY_ID, TEST_USER_ID, $accountId);

// A user rule: anything from bob@ → label "Friends" + star.
$DB->prepare("INSERT INTO email_rules (company_id, user_id, name, priority, conditions_json, actions_json, stop_processing, is_active)
              VALUES (?, ?, 'Bob', 10, ?, ?, 0, 1)")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID,
              json_encode(['match' => 'all', 'items' => [['field' => 'from', 'operator' => 'contains', 'value' => 'bob@']]]),
              json_encode([['type' => 'label', 'value' => 'Friends'], ['type' => 'star', 'value' => '']])]);

// ---------------------------------------------------------------- seed server mailbox
$imap = new ImapClient('127.0.0.1', $imapPort, 'ssl', false);
$imap->connect();
$imap->login('test@example.com', 'secret');
$first = MimeBuilder::build([
    'from' => '"Alice Smith" <alice@acme.com>', 'to' => ['test@example.com'], 'subject' => 'Quote request RFQ-123',
    'html' => '<p>Hi, please quote <b>500</b> units.</p><img src="cid:logo"><img src="https://track.er/x.gif">',
    'attachments' => [
        ['filename' => 'spec.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 test'],
        ['filename' => 'logo.png', 'mime' => 'image/png', 'content' => 'PNG', 'cid' => 'logo', 'inline' => true],
    ],
]);
$imap->append('INBOX', $first['raw'], []);
$imap->append('INBOX', MimeBuilder::build(['from' => 'Bob <bob@beta.co.za>', 'to' => ['test@example.com'],
    'subject' => 'Lunch?', 'text' => "Lunch on Friday?\n\nBob"])['raw'], ['\\Seen']);

// ---------------------------------------------------------------- first sync
$r = MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_true($r['ok'], 'first sync ok: ' . ($r['error'] ?? ''));
assert_eq(2, $r['new'], 'first sync imports 2 messages');
$account = MailRepo::account($DB, TEST_COMPANY_ID, TEST_USER_ID, $accountId);
$map = MailRepo::folderMap($account);
assert_eq('Sent', $map['sent'] ?? null, 'Sent folder detected');
assert_eq('Archive', $map['archive'] ?? null, 'Archive folder detected');
assert_eq('Junk', $map['junk'] ?? null, 'Junk folder detected');

$alice = $DB->query("SELECT * FROM emails WHERE subject = 'Quote request RFQ-123'")->fetch();
assert_eq('alice@acme.com', $alice['sender'], 'sender stored as bare address');
assert_eq('Alice Smith', $alice['sender_name'], 'sender name stored');
assert_eq(0, (int)$alice['is_read'], 'unseen stays unread');
assert_eq(1, (int)$alice['has_attachments'], 'has_attachments set (inline image excluded)');
assert_eq($first['message_id'], $alice['message_id'], 'message-id stored');
$atts = $DB->query("SELECT * FROM email_attachments WHERE email_id = " . (int)$alice['email_id'] . " ORDER BY attachment_id")->fetchAll();
assert_eq(2, count($atts), 'two attachment rows (file + inline)');
foreach ($atts as $a) {
    $p = MailPaths::resolveStored($a['file_path']);
    assert_true($p !== null && is_file($p), 'attachment stored on disk: ' . $a['file_name']);
}
$labels = MailRepo::tagsForEmails($DB, [(int)$alice['email_id']]);
assert_true(in_array('RFQ', array_column($labels[(int)$alice['email_id']] ?? [], 'name'), true), 'smart label RFQ applied');

$bob = $DB->query("SELECT * FROM emails WHERE subject = 'Lunch?'")->fetch();
assert_eq(1, (int)$bob['is_read'], '\\Seen imported as read');
assert_eq(1, (int)$bob['is_starred'], 'rule starred Bob');
$bobLabels = array_column(MailRepo::tagsForEmails($DB, [(int)$bob['email_id']])[(int)$bob['email_id']] ?? [], 'name');
assert_true(in_array('Friends', $bobLabels, true), 'rule created + applied label Friends');

// ---------------------------------------------------------------- list + counts
$list = MailRepo::listThreads($DB, TEST_COMPANY_ID, TEST_USER_ID, ['view' => 'inbox']);
assert_eq(2, count($list['threads']), 'inbox lists 2 conversations');
assert_eq(2, $list['total'], 'inbox total');
assert_eq('Lunch?', $list['threads'][0]['subject'] === 'Lunch?' || $list['threads'][1]['subject'] === 'Lunch?' ? 'Lunch?' : '', 'thread subject present');
$counts = MailRepo::counts($DB, TEST_COMPANY_ID, TEST_USER_ID);
assert_eq(1, $counts['inbox'], 'one unread conversation');
$search = MailRepo::listThreads($DB, TEST_COMPANY_ID, TEST_USER_ID, ['view' => 'inbox', 'q' => 'from:alice has:attachment']);
assert_eq(1, count($search['threads']), 'search with operators finds Alice');
$none = MailRepo::listThreads($DB, TEST_COMPANY_ID, TEST_USER_ID, ['view' => 'inbox', 'q' => 'nonexistentword']);
assert_eq(0, count($none['threads']), 'search miss');
$other = MailRepo::listThreads($DB, TEST_COMPANY_ID, 999999, ['view' => 'inbox']);
assert_eq(0, count($other['threads']), 'another user sees nothing');
assert_eq([], MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, 999999, ['t' . $alice['thread_id']]), 'another user cannot load the thread');

// ---------------------------------------------------------------- sanitised rendering
$cidMap = [];
foreach ($atts as $a) if ($a['content_id']) $cidMap[$a['content_id']] = '/mail/api/attachment.php?id=' . $a['attachment_id'] . '&inline=1';
$san = HtmlSanitizer::sanitize($alice['body_html'], ['block_remote' => true, 'cid_map' => $cidMap]);
assert_true($san['remote_images'], 'remote image detected');
assert_true(str_contains($san['html'], 'attachment.php?id='), 'cid image rewritten to attachment URL');
assert_true(!preg_match('/\s src="https:\/\/track/x', $san['html']), 'tracking pixel blocked');

// ---------------------------------------------------------------- reply threads into same conversation
$imap->append('INBOX', MimeBuilder::build(['from' => '"Alice Smith" <alice@acme.com>', 'to' => ['test@example.com'],
    'subject' => 'Re: Quote request RFQ-123', 'text' => 'Any update?', 'in_reply_to' => $first['message_id'],
    'references' => '<' . $first['message_id'] . '>'])['raw'], []);
$r = MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_eq(1, $r['new'], 'incremental sync imports the reply only');
$reply = $DB->query("SELECT * FROM emails WHERE subject = 'Re: Quote request RFQ-123'")->fetch();
assert_eq((int)$alice['thread_id'], (int)$reply['thread_id'], 'reply joins the original thread');
$r = MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_eq(0, $r['new'], 'no duplicates on re-sync');

// ---------------------------------------------------------------- actions propagate to server
$rows = MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, TEST_USER_ID, ['t' . $alice['thread_id']]);
assert_eq(2, MailActions::apply($DB, $rows, 'read'), 'mark thread read');
$rows = MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, TEST_USER_ID, ['t' . $bob['thread_id']]);
assert_eq(1, MailActions::apply($DB, $rows, 'archive'), 'archive Bob');
assert_true((int)$DB->query("SELECT COUNT(*) FROM email_imap_ops")->fetchColumn() >= 3, 'server ops queued');
$r = MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_true($r['ok'], 'sync after actions ok');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM email_imap_ops")->fetchColumn(), 'ops applied');
$imap->select('Archive');
assert_eq(1, count($imap->uidSearch('ALL')), 'Bob moved to server Archive');
$bobNow = $DB->query("SELECT * FROM emails WHERE email_id = " . (int)$bob['email_id'])->fetch();
assert_eq('Archive', $bobNow['folder'], 'local folder Archive');
assert_eq('Archive', $bobNow['imap_folder'], 'rebound to server Archive via COPYUID');
$imap->select('INBOX');
$seen = $imap->uidFetch('1:*', 'UID FLAGS');
foreach ($seen as $u => $m) assert_true(in_array('\\Seen', (array)$m['FLAGS'], true), "server message $u marked \\Seen");

// Phone marks Alice's reply unread → reconciled locally.
$imap->uidStore([(int)$reply['imap_uid']], '-', ['\\Seen']);
MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_eq(0, (int)$DB->query("SELECT is_read FROM emails WHERE email_id = " . (int)$reply['email_id'])->fetchColumn(), 'remote unread mirrored');

// ---------------------------------------------------------------- trash → delete forever
$rows = MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, TEST_USER_ID, ['t' . $bob['thread_id']]);
MailActions::apply($DB, $rows, 'trash');
MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
$imap->select('Trash');
assert_eq(1, count($imap->uidSearch('ALL')), 'Bob in server Trash');
$rows = MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, TEST_USER_ID, ['t' . $bob['thread_id']]);
assert_eq(1, MailActions::apply($DB, $rows, 'delete_forever'), 'delete forever');
MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
$imap->select('Trash');
assert_eq(0, count($imap->uidSearch('ALL')), 'expunged on server');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM emails WHERE email_id = " . (int)$bob['email_id'])->fetchColumn(), 'deleted locally');

// ---------------------------------------------------------------- compose → undo → send → Sent copy
$draft = MailOutbox::saveDraft($DB, TEST_COMPANY_ID, TEST_USER_ID, [
    'account_id' => $accountId, 'to' => ['Alice Smith <alice@acme.com>'], 'bcc' => ['boss@example.org'],
    'subject' => 'Re: Quote request RFQ-123', 'body_html' => '<p>Quote attached.</p><script>x()</script>',
    'thread_key' => 't' . $alice['thread_id'], 'in_reply_to' => $reply['message_id'],
    'references' => '<' . $first['message_id'] . '> <' . $reply['message_id'] . '>',
]);
$draftId = $draft['email_id'];
$d = $DB->query("SELECT * FROM emails WHERE email_id = $draftId")->fetch();
assert_eq('Drafts', $d['folder'], 'draft saved');
assert_true(!str_contains($d['body_html'], '<script'), 'draft body sanitised');
MailStore::storeAttachment($DB, TEST_COMPANY_ID, $accountId, $draftId, 'quote.pdf', 'application/pdf', '%PDF quote');

$q = MailOutbox::queue($DB, TEST_COMPANY_ID, TEST_USER_ID, $draftId, null, 30);
assert_true(!empty($q['queued']), 'queued with undo window');
assert_true(MailOutbox::cancel($DB, TEST_COMPANY_ID, TEST_USER_ID, $draftId), 'undo send');
assert_eq('Drafts', $DB->query("SELECT folder FROM emails WHERE email_id = $draftId")->fetchColumn(), 'back in drafts');

MailOutbox::queue($DB, TEST_COMPANY_ID, TEST_USER_ID, $draftId, null, 2);
assert_eq([], MailOutbox::flushDue($DB, TEST_COMPANY_ID, TEST_USER_ID), 'not due during undo window');
$DB->exec("UPDATE emails SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE email_id = $draftId");
$flush = MailOutbox::flushDue($DB, TEST_COMPANY_ID, TEST_USER_ID);
assert_true(!empty($flush[$draftId]['ok']), 'sent: ' . json_encode($flush));
assert_eq([], MailOutbox::flushDue($DB, TEST_COMPANY_ID, TEST_USER_ID), 'never sent twice');
$sent = $DB->query("SELECT * FROM emails WHERE email_id = $draftId")->fetch();
assert_eq('Sent', $sent['folder'], 'moved to Sent');
assert_eq((int)$alice['thread_id'], (int)$sent['thread_id'], 'sent reply stays in the conversation');

$emls = glob($smtpOut . '/*.eml');
assert_eq(1, count($emls), 'one message hit SMTP');
$env = json_decode(file_get_contents(str_replace('.eml', '.json', $emls[0])), true);
assert_true(in_array('boss@example.org', $env['rcpt'], true), 'bcc in envelope');
$rawSent = file_get_contents($emls[0]);
assert_true(!str_contains($rawSent, 'boss@example.org'), 'bcc not in headers');
$ps = MimeParser::parse($rawSent);
assert_eq($reply['message_id'], $ps['in_reply_to'], 'In-Reply-To header');
assert_eq('Test User', $ps['from']['name'], 'From display name');
assert_eq(1, count($ps['attachments']), 'attachment sent');

$ops = $DB->query("SELECT * FROM email_imap_ops WHERE op = 'append'")->fetchAll();
assert_eq(1, count($ops), 'Sent copy queued');
MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
$imap->select('Sent');
assert_eq(1, count($imap->uidSearch('ALL')), 'copy filed in server Sent');
$sent = $DB->query("SELECT * FROM emails WHERE email_id = $draftId")->fetch();
assert_eq('Sent', $sent['imap_folder'], 'local Sent row bound to server copy');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM emails WHERE folder = 'Sent'")->fetchColumn(), 'Sent sync did not duplicate');

$thread = MailRepo::emailsForKeys($DB, TEST_COMPANY_ID, TEST_USER_ID, ['t' . $alice['thread_id']], 'visible');
assert_eq(3, count($thread), 'conversation has original, reply and my answer');
$contacts = (int)$DB->query("SELECT COUNT(*) FROM email_contacts WHERE email = 'alice@acme.com'")->fetchColumn();
assert_eq(1, $contacts, 'correspondent remembered');

// ---------------------------------------------------------------- send failure surfaces
$d2 = MailOutbox::saveDraft($DB, TEST_COMPANY_ID, TEST_USER_ID, ['account_id' => $accountId, 'to' => 'x@reject.test', 'subject' => 'bounce', 'body_html' => 'x']);
$res = MailOutbox::queue($DB, TEST_COMPANY_ID, TEST_USER_ID, $d2['email_id'], null, 0);
assert_true(empty($res['sent']) && str_contains((string)$res['error'], 'rejected'), 'rejected recipient reported: ' . json_encode($res));
assert_eq('failed', $DB->query("SELECT send_status FROM emails WHERE email_id = " . (int)$d2['email_id'])->fetchColumn(), 'marked failed');

// ---------------------------------------------------------------- UIDVALIDITY reset safety
$DB->exec("UPDATE email_folder_state SET uidvalidity = 1 WHERE folder = 'INBOX'");
$r = MailSyncService::syncAccount($DB, $account, ['time_budget' => 30]);
assert_true($r['ok'], 'sync after UIDVALIDITY change');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM emails WHERE folder = 'INBOX'")->fetchColumn(), 'no duplicates after UIDVALIDITY reset');

$imap->logout();
echo "OK mail integration (" . $GLOBALS['__assert_count'] . " assertions)\n";
