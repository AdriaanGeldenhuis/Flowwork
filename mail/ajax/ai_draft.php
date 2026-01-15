<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/RulesEngine.php';
header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$threadId = (int)($_POST['thread_id'] ?? ($_GET['thread_id'] ?? 0));
if ($threadId<=0) { echo json_encode(['ok'=>false,'error'=>'Missing thread_id']); exit; }

// Find latest incoming
$stmt = $DB->prepare("SELECT subject, body_text, sender FROM emails WHERE company_id=? AND thread_id=? AND direction='incoming' ORDER BY sent_at DESC LIMIT 1");
$stmt->execute([$companyId,$threadId]);
$msg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$msg) { echo json_encode(['ok'=>false,'error'=>'No incoming message']); exit; }

$intent = RulesEngine::detectIntent($msg['subject'] ?? '', $msg['body_text'] ?? '');
$greet = 'Hi';
if (preg_match('/([^<@]+)@/', $msg['sender'], $m)) $greet = 'Hi ' . trim($m[1]);

$subject = 'Re: ' . ($msg['subject'] ?? '');
$body = "$greet,\n\n";

if ($intent === 'rfq') {
  $body .= "We received your request for a quotation. Please confirm scope and quantities, and we’ll revert with pricing.\n\nRegards";
} elseif ($intent === 'invoice_query') {
  $body .= "Thanks for your message about the invoice. Please confirm the invoice number and we’ll provide status and details.\n\nRegards";
} elseif ($intent === 'payment_proof') {
  $body .= "Thanks for the proof of payment. We’ll confirm allocation shortly.\n\nRegards";
} else {
  $body .= "Thanks for your email. We’ll get back to you shortly.\n\nRegards";
}

echo json_encode(['ok'=>true,'subject'=>$subject,'body'=>$body]);