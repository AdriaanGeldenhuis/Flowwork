<?php
// GET /mail/api/thread.php?key=t123&view=inbox[&images=1]
// A whole conversation, oldest first, with sanitised HTML bodies.
require_once __DIR__ . '/_bootstrap.php';

$key = (string)($_GET['key'] ?? '');
if (!MailRepo::parseKey($key)) mail_error('Invalid conversation');
$view = (string)($_GET['view'] ?? 'inbox');
$scope = in_array($view, ['trash', 'spam', 'outbox'], true) ? $view : 'visible';

$rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], $scope);
if (!$rows && $scope !== 'all') {
    // e.g. opened from a notification after it moved folders
    $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], 'all');
}
if (!$rows) mail_error('Conversation not found', 404);

$prefs = MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);
$showImages = !empty($_GET['images']) || !$prefs['block_external_images'];

$ids = array_map(fn($r) => (int)$r['email_id'], $rows);
$attachments = MailRepo::attachmentsFor($DB, $ids);
$tags = MailRepo::tagsForEmails($DB, $ids);

$messages = [];
$labels = [];
$anyRemote = false;
foreach ($rows as $r) {
    $id = (int)$r['email_id'];
    $cidMap = [];
    $files = [];
    foreach ($attachments[$id] ?? [] as $a) {
        $url = '/mail/api/attachment.php?id=' . (int)$a['attachment_id'];
        if ($a['content_id']) $cidMap[strtolower($a['content_id'])] = $url . '&inline=1';
    }
    $html = (string)$r['body_html'];
    $san = $html !== ''
        ? HtmlSanitizer::sanitize($html, ['block_remote' => !$showImages, 'cid_map' => $cidMap])
        : ['html' => HtmlSanitizer::fromText((string)$r['body_text']), 'remote_images' => false];
    $anyRemote = $anyRemote || $san['remote_images'];

    foreach ($attachments[$id] ?? [] as $a) {
        $referenced = $a['content_id'] && str_contains($san['html'], 'attachment.php?id=' . (int)$a['attachment_id']);
        if ((int)$a['is_inline'] === 1 && $referenced) continue; // shown in the body
        $files[] = [
            'attachment_id' => (int)$a['attachment_id'],
            'name'          => $a['file_name'],
            'mime'          => $a['mime_type'] ?: 'application/octet-stream',
            'size'          => (int)$a['file_size'],
            'url'           => '/mail/api/attachment.php?id=' . (int)$a['attachment_id'],
        ];
    }
    foreach ($tags[$id] ?? [] as $t) $labels[$t['tag_id']] = $t;

    $from = ['email' => (string)$r['sender'], 'name' => MailRepo::displayName($r['sender_name'], '')];
    if ($from['name'] === '(unknown)') $from['name'] = '';
    if (preg_match('/<([^>]+)>/', $from['email'], $m)) { // legacy "Name <email>"
        $from = ['email' => strtolower($m[1]), 'name' => MailRepo::displayName(null, $r['sender'])];
    }
    $outgoing = $r['direction'] === 'outgoing';

    $messages[] = [
        'email_id'     => $id,
        'account_id'   => (int)$r['account_id'],
        'folder'       => $r['folder'],
        'outgoing'     => $outgoing,
        'is_draft'     => $r['folder'] === 'Drafts',
        'from'         => $from,
        'to'           => MimeParser::parseAddressList((string)$r['to_recipients']),
        'cc'           => MimeParser::parseAddressList((string)$r['cc_recipients']),
        'bcc'          => $outgoing ? MimeParser::parseAddressList((string)$r['bcc_recipients']) : [],
        'reply_to'     => MimeParser::parseAddressList((string)$r['reply_to']),
        'subject'      => (string)$r['subject'],
        'date'         => MailRepo::iso($r['sent_at'] ?? $r['created_at']),
        'is_read'      => (int)$r['is_read'] === 1,
        'is_starred'   => (int)$r['is_starred'] === 1,
        'snippet'      => HtmlSanitizer::snippet((string)$r['body_text'], 140),
        'html'         => $san['html'],
        'remote_images'=> $san['remote_images'],
        'attachments'  => $files,
        'unsubscribe'  => $r['list_unsubscribe'] ?: null,
        'send_status'  => $r['send_status'],
        'send_error'   => $r['send_error'],
        'scheduled_at' => MailRepo::iso($r['scheduled_at']),
        'snoozed_until'=> MailRepo::iso($r['snoozed_until']),
    ];
}

$subject = '';
foreach (array_reverse($messages) as $m) if ($m['subject'] !== '') { $subject = $m['subject']; break; }

mail_json([
    'key'           => $key,
    'subject'       => $subject,
    'messages'      => $messages,
    'labels'        => array_values($labels),
    'links'         => MailRepo::linksFor($DB, $MAIL_COMPANY_ID, $ids),
    'images_shown'  => $showImages,
    'remote_images' => $anyRemote,
]);
