<?php
// POST /mail/api/signature.php
//   {op:"save", signature_id?, name, content_html, is_default, shared}
//   {op:"delete", signature_id}
// GET → {signatures:[...]}
require_once __DIR__ . '/_bootstrap.php';

$list = function () use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID): array {
    $st = $DB->prepare("SELECT signature_id, name, content_html, is_default, user_id FROM email_signatures
                         WHERE company_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY is_default DESC, name");
    $st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
    return array_map(fn($s) => ['signature_id' => (int)$s['signature_id'], 'name' => $s['name'],
        'content_html' => HtmlSanitizer::sanitize(HtmlSanitizer::ensureHtml((string)$s['content_html']), ['block_remote' => false, 'for_editor' => true])['html'],
        'is_default' => (int)$s['is_default'] === 1,
        'shared' => $s['user_id'] === null], $st->fetchAll(PDO::FETCH_ASSOC));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') mail_json(['signatures' => $list()]);

mail_require_post();
$in = mail_input();
$op = mail_str($in, 'op', 10);
$id = (int)($in['signature_id'] ?? 0);

if ($id) {
    $st = $DB->prepare("SELECT signature_id, user_id FROM email_signatures WHERE signature_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)");
    $st->execute([$id, $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $sig = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sig) mail_error('Signature not found', 404);
    if ($sig['user_id'] === null && !in_array($MAIL_ROLE, ['admin', 'owner'], true)) {
        mail_error('Only an admin can change a shared company signature', 403);
    }
}

if ($op === 'delete') {
    if (!$id) mail_error('Signature not found', 404);
    $DB->prepare("DELETE FROM email_signatures WHERE signature_id = ? AND company_id = ?")->execute([$id, $MAIL_COMPANY_ID]);
    $DB->prepare("UPDATE email_accounts SET default_signature_id = NULL WHERE default_signature_id = ? AND company_id = ?")
       ->execute([$id, $MAIL_COMPANY_ID]);
    mail_json(['signatures' => $list()]);
}

if ($op === 'save') {
    $name = mail_str($in, 'name', 120);
    $html = HtmlSanitizer::sanitize((string)($in['content_html'] ?? ''), ['block_remote' => false, 'for_editor' => true])['html'];
    if ($name === '') mail_error('Give the signature a name');
    if (trim(strip_tags($html, '<img>')) === '') mail_error('The signature is empty');
    if (strlen($html) > 60000) mail_error('The signature is too long (embed fewer or smaller images)');
    $default = mail_bool($in, 'is_default');
    $shared = mail_bool($in, 'shared') && in_array($MAIL_ROLE, ['admin', 'owner'], true);
    $owner = $shared ? null : $MAIL_USER_ID;

    $DB->beginTransaction();
    if ($default) {
        $DB->prepare("UPDATE email_signatures SET is_default = 0 WHERE company_id = ? AND (user_id = ? OR user_id IS NULL)")
           ->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
    }
    if ($id) {
        $DB->prepare("UPDATE email_signatures SET name = ?, content_html = ?, is_default = ?, user_id = ?, updated_at = NOW()
                       WHERE signature_id = ? AND company_id = ?")
           ->execute([$name, $html, $default ? 1 : 0, $owner, $id, $MAIL_COMPANY_ID]);
    } else {
        $DB->prepare("INSERT INTO email_signatures (company_id, user_id, name, content_html, is_default, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$MAIL_COMPANY_ID, $owner, $name, $html, $default ? 1 : 0]);
        $id = (int)$DB->lastInsertId();
    }
    $DB->commit();
    mail_json(['signature_id' => $id, 'signatures' => $list()]);
}
mail_error('Unknown operation');
