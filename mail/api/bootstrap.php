<?php
// GET /mail/api/bootstrap.php — everything the mail app needs on first paint.
require_once __DIR__ . '/_bootstrap.php';

$accounts = MailRepo::accounts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);
$me = mail_user_name($DB, $MAIL_USER_ID);

$st = $DB->prepare("SELECT signature_id, name, content_html, is_default, user_id FROM email_signatures
                     WHERE company_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY is_default DESC, name");
$st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
$signatures = array_map(fn($s) => [
    'signature_id' => (int)$s['signature_id'], 'name' => $s['name'],
    // Older signatures were stored unsanitised; they are inserted into a live editor.
    'content_html' => HtmlSanitizer::sanitize(HtmlSanitizer::ensureHtml((string)$s['content_html']), ['block_remote' => false, 'for_editor' => true])['html'],
    'is_default' => (int)$s['is_default'] === 1, 'shared' => $s['user_id'] === null,
], $st->fetchAll(PDO::FETCH_ASSOC));

$st = $DB->prepare("SELECT template_id, name, subject, category FROM email_templates WHERE company_id = ? ORDER BY category, name");
$st->execute([$MAIL_COMPANY_ID]);
$templates = array_map(fn($t) => ['template_id' => (int)$t['template_id'], 'name' => $t['name'],
    'subject' => (string)$t['subject'], 'category' => (string)$t['category']], $st->fetchAll(PDO::FETCH_ASSOC));

$st = $DB->prepare("SELECT id, name, filters_json FROM mail_saved_views WHERE company_id = ? AND user_id = ? ORDER BY name");
$st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
$views = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $f = json_decode((string)$v['filters_json'], true);
    $views[] = ['id' => (int)$v['id'], 'name' => $v['name'], 'filters' => is_array($f) ? $f : []];
}

mail_json([
    'me'         => ['name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')), 'first_name' => $me['first_name'] ?? '', 'email' => $me['email'] ?? ''],
    'accounts'   => array_map([MailRepo::class, 'publicAccount'], $accounts),
    'labels'     => MailRepo::labels($DB, $MAIL_COMPANY_ID),
    'counts'     => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID),
    'prefs'      => MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID),
    'signatures' => $signatures,
    'templates'  => $templates,
    'views'      => $views,
    'server_time'=> date('c'),
]);
