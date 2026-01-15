<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$threadId = (int)($_GET['id'] ?? 0);
if ($threadId<=0) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
$stmt = $DB->prepare("SELECT * FROM emails WHERE company_id=? AND thread_id=? ORDER BY sent_at ASC");
$stmt->execute([$companyId,$threadId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
// user pref
$p = $DB->prepare("SELECT block_external_images FROM user_mail_prefs WHERE company_id=? AND user_id=?");
$p->execute([$companyId, $_SESSION['user_id'] ?? 0]);
$block = (int)$p->fetchColumn() ? true : false;

function sanitizeEmailHtml($html, $blockImages=true) {
  // Remove dangerous elements
  $html = preg_replace('/<script\\b[^>]*>.*?<\\/script>/is', '', $html);
  $html = preg_replace('/<iframe\\b[^>]*>.*?<\\/iframe>/is', '', $html);
  $html = preg_replace('/on\\w+\\s*=\\s*([\"\\\']).*?\\1/i', '', $html);
  if ($blockImages) {
    $html = preg_replace_callback('/<img\\s+[^>]*src=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>/i', function($m){
      $src = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
      return '<img alt=\"blocked\" data-src=\"'.$src.'\" src=\"data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'24\' height=\'16\'%3E%3Crect width=\'24\' height=\'16\' fill=\'%23ddd\'/%3E%3Ctext x=\'12\' y=\'12\' font-size=\'6\' text-anchor=\'middle\' fill=\'%23666\'%3Eimage blocked%3C/text%3E%3C/svg%3E\" />';
    }, $html);
  }
  return $html;
}
foreach ($messages as &$m) {
  if (!empty($m['body_html'])) $m['body_html'] = sanitizeEmailHtml($m['body_html'], $block);
}
echo json_encode(['ok'=>true,'messages'=>$messages]);