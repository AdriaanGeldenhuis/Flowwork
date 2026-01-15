<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SecureVault.php';
require_once __DIR__ . '/../lib/SmtpSender.php';
header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

$accountId = (int)($_POST['account_id'] ?? 0);
$to        = array_filter(array_map('trim', explode(',', $_POST['to'] ?? '')));
$cc        = array_filter(array_map('trim', explode(',', $_POST['cc'] ?? '')));
$bcc       = array_filter(array_map('trim', explode(',', $_POST['bcc'] ?? '')));
$subject   = trim($_POST['subject'] ?? '');
$body      = trim($_POST['body'] ?? '');
$signatureId = (int)($_POST['signature_id'] ?? 0);

if (!$accountId || !$to || !$subject) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }

// pull account
$stmt = $DB->prepare("SELECT * FROM email_accounts WHERE account_id=? AND company_id=? AND user_id=?");
$stmt->execute([$accountId,$companyId,$userId]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { echo json_encode(['ok'=>false,'error'=>'Account not found']); exit; }

// signature
if ($signatureId > 0) {
  $s = $DB->prepare("SELECT html FROM email_signatures WHERE signature_id=? AND company_id=? AND (user_id=? OR user_id IS NULL)");
  $s->execute([$signatureId,$companyId,$userId]);
  $sig = $s->fetchColumn();
  if ($sig) {
    if ($body && substr($body,-4) !== '</p>') $body .= "<br><br>";
    $body .= $sig;
  }
}

$pass = SecureVault::decrypt($acc['password_encrypted'] ?? '');
$send = SmtpSender::send([
  'host'=>$acc['smtp_server'],'port'=>(int)$acc['smtp_port'],'encryption'=>$acc['smtp_encryption'],
  'username'=>$acc['username'],'password'=>$pass,
  'from'=>$acc['email_address'],'to'=>$to,'cc'=>$cc,'bcc'=>$bcc,
  'subject'=>$subject,'html'=>$body
]);
if (!$send['ok']) { echo json_encode(['ok'=>false,'error'=>$send['error'] ?? 'send failed']); exit; }

// Save local Sent
$stmt = $DB->prepare("INSERT INTO emails
  (company_id,account_id,thread_id,folder,direction,sender,to_recipients,cc_recipients,bcc_recipients,subject,body_html,body_text,sent_at,is_read,created_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),1,NOW())");
$sender = $acc['email_address'];
$toStr = implode(',', $to);
$ccStr = implode(',', $cc);
$bccStr = implode(',', $bcc);
$threadId = (int)($_POST['thread_id'] ?? 0);
$stmt->execute([$companyId,$accountId,$threadId,'Sent','outgoing',$sender,$toStr,$ccStr,$bccStr,$subject,$body,strip_tags($body)]);

// update thread last message
$DB->prepare("UPDATE email_threads SET last_message_at=NOW(), last_agent_reply_at=NOW() WHERE thread_id=? AND company_id=?")->execute([$threadId,$companyId]);

echo json_encode(['ok'=>true]);
