<?php
// GET /mail/api/quick_replies.php?key=t123 — one-tap reply suggestions based on
// what the latest incoming message is about (RFQ, invoice, payment, general).
require_once __DIR__ . '/_bootstrap.php';

$key = (string)($_GET['key'] ?? '');
if (!MailRepo::parseKey($key)) mail_error('Invalid conversation');
$rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], 'visible');
$incoming = array_values(array_filter($rows, fn($r) => $r['direction'] === 'incoming'));
if (!$incoming) mail_json(['replies' => []]);
$last = end($incoming);

$name = MailRepo::displayName($last['sender_name'], '');
$first = ($name !== '' && $name !== '(unknown)' && !str_contains($name, '@')) ? explode(' ', trim($name))[0] : '';
$greet = $first !== '' ? "Hi $first," : 'Hi,';
$me = mail_user_name($DB, $MAIL_USER_ID);
$sign = "Kind regards\n" . trim((string)$me['first_name']);

$intent = RulesEngine::detectIntent((string)$last['subject'], (string)$last['body_text']);
$sets = [
    'rfq' => [
        ['Quote on its way', "Thanks for your request. We're preparing the quotation and will send it through shortly."],
        ['Confirm details', "Thanks for the request. Could you please confirm the quantities, specifications and delivery address so we can price it accurately?"],
        ['Received', "Received with thanks — we'll revert with pricing as soon as possible."],
    ],
    'invoice_query' => [
        ['Looking into it', "Thanks for your message. We're looking into the invoice and will get back to you with the details shortly."],
        ['Need invoice number', "Thanks for getting in touch. Could you please confirm the invoice number so we can check the status for you?"],
        ['Statement attached', "Please find the requested statement attached. Let us know if anything is unclear."],
    ],
    'payment_proof' => [
        ['Thanks, will allocate', "Thank you for the proof of payment. We'll allocate it to your account and confirm once done."],
        ['Payment received', "Thank you — we confirm receipt of your payment."],
        ['Missing reference', "Thanks for the payment. Could you please send the invoice number(s) this payment relates to?"],
    ],
    'general' => [
        ['Thanks, noted', "Thanks for your email — noted."],
        ['Will revert', "Thanks for your email. I'll look into this and get back to you shortly."],
        ['Sounds good', "Sounds good, thank you."],
    ],
];

$replies = [];
foreach ($sets[$intent] ?? $sets['general'] as [$label, $body]) {
    $text = "$greet\n\n$body\n\n$sign";
    $replies[] = ['label' => $label, 'body_html' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))];
}
mail_json(['intent' => $intent, 'email_id' => (int)$last['email_id'], 'replies' => $replies]);
