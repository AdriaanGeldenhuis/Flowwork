<?php
// /mail/compose.php — kept for old links (CRM, bookmarks). Composing now
// happens in the Mail app's composer, so forward the parameters there.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$mode = $_GET['mode'] ?? 'new';
if (!in_array($mode, ['new', 'reply', 'reply_all', 'forward'], true)) $mode = 'new';
$params = ['compose' => $mode];
foreach (['email_id', 'template_id'] as $k) {
    if (!empty($_GET[$k])) $params[$k] = (int)$_GET[$k];
}
foreach (['to', 'subject'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k])) $params[$k] = mb_substr($_GET[$k], 0, 500);
}
header('Location: /mail/?' . http_build_query($params), true, 302);
exit;
