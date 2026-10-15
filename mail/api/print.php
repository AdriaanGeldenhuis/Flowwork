<?php
// GET /mail/api/print.php?key=t123[&images=1] — printable conversation.
// Bodies are sanitised and the page runs under a CSP that only allows our own
// nonce'd print script, so nothing from an email can execute here.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
session_write_close();
$DB->exec("SET time_zone = '" . date('P') . "'");
MailSchema::ensure($DB);

$key = (string)($_GET['key'] ?? '');
$rows = MailRepo::parseKey($key) ? MailRepo::emailsForKeys($DB, $companyId, $userId, [$key], 'visible') : [];
$rows = array_values(array_filter($rows, fn($r) => $r['folder'] !== 'Drafts'));
if (!$rows) { http_response_code(404); exit('Conversation not found'); }

$images = !empty($_GET['images']);
$nonce = base64_encode(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:" . ($images ? ' https: http:' : '')
     . "; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'nonce-$nonce'");
header('X-Content-Type-Options: nosniff');

$atts = MailRepo::attachmentsFor($DB, array_map(fn($r) => (int)$r['email_id'], $rows));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$subject = '';
foreach ($rows as $r) if ($r['subject'] !== '') $subject = $r['subject'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $e($subject ?: '(no subject)') ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Newsreader:opsz,wght@6..72,500&display=swap">
<style>
  body { font-family: Inter, -apple-system, Segoe UI, Roboto, sans-serif; color: #1f1a17; margin: 32px auto; max-width: 760px; padding: 0 24px; font-size: 13px; }
  h1 { font-family: Newsreader, Georgia, serif; font-weight: 500; font-size: 26px; margin: 0 0 4px; }
  .count { color: #7a716a; margin-bottom: 24px; }
  .msg { border-top: 1px solid #e6e1db; padding: 18px 0; page-break-inside: avoid; }
  .meta { display: flex; justify-content: space-between; gap: 16px; color: #4a423c; margin-bottom: 4px; }
  .meta b { color: #1f1a17; }
  .rcpt { color: #7a716a; margin-bottom: 12px; }
  .body { overflow-wrap: anywhere; }
  .body img { max-width: 100%; height: auto; }
  .atts { margin-top: 12px; color: #4a423c; }
  @media print { body { margin: 0 auto; } }
</style>
</head>
<body>
<h1><?= $e($subject ?: '(no subject)') ?></h1>
<div class="count"><?= count($rows) ?> message<?= count($rows) === 1 ? '' : 's' ?></div>
<?php foreach ($rows as $r):
    $id = (int)$r['email_id'];
    $cid = [];
    foreach ($atts[$id] ?? [] as $a) if ($a['content_id']) $cid[strtolower($a['content_id'])] = '/mail/api/attachment.php?id=' . (int)$a['attachment_id'] . '&inline=1';
    $html = $r['body_html'] !== '' && $r['body_html'] !== null
        ? HtmlSanitizer::sanitize((string)$r['body_html'], ['block_remote' => !$images, 'cid_map' => $cid, 'allow_local' => $r['send_status'] !== null])['html']
        : HtmlSanitizer::fromText((string)$r['body_text']);
    $files = array_filter($atts[$id] ?? [], fn($a) => (int)$a['is_inline'] !== 1);
?>
  <div class="msg">
    <div class="meta">
      <span><b><?= $e(MailRepo::displayName($r['sender_name'], $r['sender'])) ?></b> &lt;<?= $e($r['sender']) ?>&gt;</span>
      <span><?= $r['sent_at'] ? $e(date('D, j M Y H:i', strtotime($r['sent_at']))) : '' ?></span>
    </div>
    <div class="rcpt">To: <?= $e($r['to_recipients']) ?><?= $r['cc_recipients'] ? ' · Cc: ' . $e($r['cc_recipients']) : '' ?></div>
    <div class="body"><?= $html ?></div>
    <?php if ($files): ?>
      <div class="atts">Attachments: <?= $e(implode(', ', array_map(fn($a) => $a['file_name'], $files))) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<script nonce="<?= $nonce ?>">window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
