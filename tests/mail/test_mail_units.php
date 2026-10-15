<?php
/**
 * Mail unit tests — no database or mail server needed.
 * Usage: php tests/mail/test_mail_units.php
 */

define('FLOWWORK_ROOT', dirname(__DIR__, 2));
require FLOWWORK_ROOT . '/mail/lib/autoload.php';

$n = 0;
function check($cond, string $msg): void
{
    global $n;
    $n++;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}
function same($exp, $act, string $msg): void
{
    check($exp === $act, $msg . ' (expected ' . var_export($exp, true) . ', got ' . var_export($act, true) . ')');
}

// ------------------------------------------------------------ MimeParser
$raw = "From: =?ISO-8859-1?Q?Andr=E9_M=FCller?= <Andre@Example.COM>\r\n"
     . "To: \"Doe, Jane\" <jane@x.com>, bob@y.com\r\n"
     . "Subject: =?UTF-8?B?w4lsw6lnYW50?= =?UTF-8?B?IHN1YmplY3Q=?=\r\n"
     . "Date: Tue, 13 Oct 2026 09:30:00 +0200 (SAST)\r\n"
     . "Message-ID: <abc.123@example.com>\r\n"
     . "References: <r1@x> <r2@x>\r\n"
     . "List-Unsubscribe: <mailto:u@x.com>, <https://x.com/u?id=1>\r\n"
     . "Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
     . "preamble\r\n--outer\r\n"
     . "Content-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n"
     . "--inner\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
     . "Caf=E9 au lait, s'il vous pla=EEt. This is a long line that is soft=\r\n wrapped.\r\n"
     . "--inner\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Caf\xc3\xa9</p>\r\n--inner--\r\n"
     . "--outer\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''R%C3%A9sum%C3%A9%202026.pdf\r\n"
     . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode("%PDF-1.4 binary\x00\xff"), 20, "\r\n")
     . "--outer\r\nContent-Type: message/rfc822\r\n\r\nSubject: Inner mail\r\n\r\nhello\r\n"
     . "--outer--\r\n";
$p = MimeParser::parse($raw);
same('Élégant subject', $p['subject'], 'encoded-word subject (adjacent words joined)');
same('andre@example.com', $p['from']['email'], 'from email lower-cased');
same('André Müller', $p['from']['name'], 'Q-encoded latin1 name');
same(['jane@x.com', 'bob@y.com'], array_column($p['to'], 'email'), 'address list with quoted comma');
same('Doe, Jane', $p['to'][0]['name'], 'quoted display name');
same('abc.123@example.com', $p['message_id'], 'message-id');
same(['r1@x', 'r2@x'], $p['references'], 'references');
same('https://x.com/u?id=1', $p['list_unsubscribe'], 'prefers https unsubscribe');
check(str_contains($p['text'], "Café au lait, s'il vous plaît") && str_contains($p['text'], 'soft wrapped'), 'QP + charset + soft breaks');
check(!str_contains($p['text'], "\r"), 'text normalised to LF');
same('<p>Café</p>', trim($p['html']), 'html part');
same(2, count($p['attachments']), 'pdf + rfc822 attachments');
same('Résumé 2026.pdf', $p['attachments'][0]['filename'], 'RFC 2231 filename');
same("%PDF-1.4 binary\x00\xff", $p['attachments'][0]['content'], 'base64 binary intact');
same('Inner mail.eml', $p['attachments'][1]['filename'], 'message/rfc822 named after subject');
same(strtotime('2026-10-13 07:30:00 UTC'), $p['date'], 'date with comment and offset');

$flowed = "Content-Type: text/plain; format=flowed\n\nThis is \nflowed text.\n-- \nSig";
same("This is flowed text.\n-- \nSig", MimeParser::parse($flowed)['text'], 'format=flowed unwrapped, sig delimiter kept');

$broken = "Content-Type: multipart/mixed\n\nno boundary here";
same('no boundary here', MimeParser::parse($broken)['text'], 'multipart without boundary falls back to text');

$cp1252 = "Subject: Price \x80 100\nContent-Type: text/plain; charset=windows-1252\n\nCost \x80100 \x93quoted\x94";
$pc = MimeParser::parse($cp1252);
same('Price € 100', $pc['subject'], 'raw 8-bit header decoded as cp1252');
same('Cost €100 “quoted”', $pc['text'], 'cp1252 body');

same('hello world', MimeParser::normalizeSubject('RE: Fwd: [Team] Re:  Hello   World'), 'subject normalisation');
check(MimeParser::isReplySubject('Re: x') && !MimeParser::isReplySubject('Regarding x'), 'reply subject detection');
$fn = MimeParser::safeFilename('../../etc/passwd');
check(!str_contains($fn, '/') && !str_starts_with($fn, '.'), 'path traversal stripped from filenames: ' . $fn);

// ------------------------------------------------------------ HtmlSanitizer
$dirty = '<div onclick="x()">Hi<script>alert(1)</script><a href="javascript:alert(1)">x</a><a href=" JaVaScRiPt:alert(1)">y</a>'
       . '<a href="https://ok.example/">ok</a><img src="https://t.example/p.gif" width="1" height="1"><img src="https://b.example/b.jpg" width="600">'
       . '<img src="data:image/png;base64,AAAA"><img src="cid:Logo1"><iframe src="https://evil"></iframe><svg><script>1</script></svg>'
       . '<form action="https://evil"><input value="x"><button>go</button><p style="background:url(https://t.example/bg.png);color:red;position:fixed">p</p></form>'
       . '<style>@import url(https://evil/x.css); body{background:url(//t.example/z.png)} .a{behavior:url(x.htc)}</style></div>';
$r = HtmlSanitizer::sanitize($dirty, ['block_remote' => true, 'cid_map' => ['logo1' => '/mail/api/attachment.php?id=9&inline=1']]);
$h = $r['html'];
foreach (['<script', 'onclick', 'javascript:', 'JaVaScRiPt', '<iframe', '<svg', '<form', '<input', '<button', '@import', 'behavior', 'position:fixed'] as $bad) {
    check(stripos($h, $bad) === false, "sanitizer removes $bad");
}
check(str_contains($h, 'href="https://ok.example/"') && str_contains($h, 'rel="noopener noreferrer nofollow"'), 'safe link kept + rel');
check(str_contains($h, 'data-fw-src="https://t.example/p.gif"') && !preg_match('/\ssrc="https:/', $h), 'remote images blocked');
check(str_contains($h, 'height:1px'), 'blocked image keeps declared height');
check(str_contains($h, 'display:none'), 'blocked image without height hidden');
check(str_contains($h, 'data:image/png;base64,AAAA'), 'data: image kept');
check(str_contains($h, '/mail/api/attachment.php?id=9&amp;inline=1'), 'cid mapped (case-insensitive)');
check(str_contains($h, '<p') && str_contains($h, 'color:red'), 'form content unwrapped, safe style kept');
check($r['remote_images'], 'remote images reported');
$shown = HtmlSanitizer::sanitize('<img src="https://b.example/b.jpg">', ['block_remote' => false]);
check(str_contains($shown['html'], 'src="https://b.example/b.jpg"'), 'images allowed when not blocking');
same('', HtmlSanitizer::sanitize('   ')['html'], 'empty html');
same("Hello\n\n• one\n• two\nlink (https://x.com)", HtmlSanitizer::toText('<p>Hello</p><ul><li>one</li><li>two</li></ul><a href="https://x.com">link</a>'), 'html → text');
// Local attachment URLs: never from received mail, only exact ones from drafts.
$recv = HtmlSanitizer::sanitize('<img src="/mail/api/attachment.php?id=5"><img src="/mail/api/attachment.php/../../logout.php">')['html'];
check(!str_contains($recv, 'attachment.php') && !str_contains($recv, 'logout'), 'received mail cannot point at local attachments');
$draft = HtmlSanitizer::sanitize('<img src="/mail/api/attachment.php?id=5&amp;inline=1"><img src="/mail/api/attachment.php?id=5&x=../../logout.php"><img src="/mail/api/attachment.php/../../logout.php">',
    ['block_remote' => false, 'allow_local' => true])['html'];
check(substr_count($draft, 'attachment.php') === 1 && str_contains($draft, 'attachment.php?id=5&amp;inline=1') && !str_contains($draft, 'logout'), 'drafts keep only exact attachment URLs');
$ed = HtmlSanitizer::sanitize('<style>.fwm-app{display:none}</style><div class="fwm-modal" id="app" style="color:red">x</div>', ['for_editor' => true])['html'];
check(!str_contains($ed, '<style') && !str_contains($ed, 'class=') && !str_contains($ed, 'id=') && str_contains($ed, 'color:red'), 'editor content loses <style>, class and id');
$kept = HtmlSanitizer::sanitize('<style>.a{color:red}</style><div class="a">x</div>')['html'];
check(str_contains($kept, '<style>') && str_contains($kept, 'class="a"'), 'reader keeps <style> and class');

same('Thanks for the quote.', HtmlSanitizer::snippet("Thanks for the quote.\n\nOn Mon, 1 Jan 2026 at 10:00, Ann <a@b> wrote:\n> old"), 'snippet drops quoted history');

// ------------------------------------------------------------ MimeBuilder
$b = MimeBuilder::build([
    'from' => 'me@ex.co.za', 'from_name' => "Evil\r\nBcc: victim@x.com",
    'to' => ['ann@x.com', 'not an address'], 'cc' => [['email' => 'Zoe@Z.de', 'name' => 'Zoë']],
    'subject' => "Hi\r\nX-Injected: 1", 'html' => '<p>Ünïcödé body</p>',
    'attachments' => [['filename' => 'Fäktura.pdf', 'mime' => 'application/pdf', 'content' => 'PDF']],
]);
[$headers] = explode("\r\n\r\n", $b['raw'], 2);
check(!preg_match('/^(Bcc|X-Injected):/mi', $headers), 'header injection neutralised');
check(!str_contains($b['raw'], 'not an address'), 'invalid recipients dropped');
$rp = MimeParser::parse($b['raw']);
same('zoe@z.de', $rp['cc'][0]['email'], 'cc round-trip');
same('Zoë', $rp['cc'][0]['name'], 'encoded cc name round-trip');
same('Fäktura.pdf', $rp['attachments'][0]['filename'], 'RFC 2231 filename round-trip');
check(str_contains($rp['html'], 'Ünïcödé body'), 'UTF-8 html round-trip');
check(MimeBuilder::validEmail('a.b+tag@sub.example.co.za') && !MimeBuilder::validEmail('a b@x.com') && !MimeBuilder::validEmail('a@b'), 'email validation');

// ------------------------------------------------------------ RulesEngine (pure parts)
$legacy = RulesEngine::decode(['conditions_json' => '{"contains":["rfq","quote needed"]}', 'actions_json' => '{"add_tags":["RFQ"]}']);
same('any', $legacy['match'], 'legacy rule → match any');
same([['type' => 'label', 'value' => 'RFQ']], $legacy['actions'], 'legacy add_tags → label');
$msg = ['from' => "Bob\nbob@supplier.co.za", 'to' => 'me@x.com', 'subject' => 'Quote needed for pipes', 'body' => 'please'];
check(RulesEngine::matches($legacy, $msg), 'legacy rule matches');
$ui = RulesEngine::decode(['conditions_json' => json_encode(['match' => 'all', 'items' => [
    ['field' => 'from', 'operator' => 'ends_with', 'value' => '@supplier.co.za'],
    ['field' => 'subject', 'operator' => 'not_contains', 'value' => 'invoice'],
]]), 'actions_json' => json_encode([['type' => 'tag', 'value' => '5'], ['type' => 'bogus']])]);
check(RulesEngine::matches($ui, $msg), 'UI rule (all) matches');
same([['type' => 'label', 'value' => '5']], $ui['actions'], 'old "tag" action mapped, unknown dropped');
check(!RulesEngine::matches($ui, ['subject' => 'Invoice 5'] + $msg), 'not_contains blocks');
check(!RulesEngine::matches(RulesEngine::decode(['conditions_json' => '{"contains":["pop"]}']), $msg + ['body' => 'populate']), 'short keywords match whole words only');
same('payment_proof', RulesEngine::detectIntent('Proof of payment – INV-2041', ''), 'POP beats invoice number');
same('rfq', RulesEngine::detectIntent('RFQ: concrete', ''), 'rfq intent');

// ------------------------------------------------------------ Search + keys + IMAP tokens
$s = MailRepo::parseSearch('from:alice "exact phrase" has:attachment is:unread after:2026-01-01 in:sent label:"Big client" loose');
same(['alice'], $s['from'], 'from: operator');
same(['exact phrase', 'loose'], $s['terms'], 'phrases and free terms');
check($s['has_att'] && $s['is'] === ['unread'] && $s['in'] === 'sent' && $s['label'] === 'Big client' && $s['after'] === '2026-01-01', 'other operators');
same(['t', 12], MailRepo::parseKey('t12'), 'thread key');
same(null, MailRepo::parseKey('t12; DROP'), 'bad key rejected');
same('1:3,5,9:10', ImapClient::sequenceSet([10, 2, 1, 3, 5, 9, 2]), 'uid set compression');
$tok = ImapClient::parseTokens([['t', '* 7 FETCH (UID 42 FLAGS (\\Seen) BODY[HEADER.FIELDS (FROM)] {5}'], ['l', "ab\r\nc"], ['t', ' X NIL)']]);
same('42', $tok[3][1], 'fetch UID token');
same(['\\Seen'], $tok[3][3], 'flags list');
same("ab\r\nc", $tok[3][5], 'literal inside list');
same(null, $tok[3][7], 'NIL → null');

// ------------------------------------------------------------ Host policy
foreach (['127.0.0.1', '10.1.2.3', '192.168.0.1', '172.16.5.4', '169.254.169.254', '100.64.0.1', '0.0.0.0',
          '::1', '::', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1'] as $ip) {
    check(!MailNet::isPublic($ip), "private address refused: $ip");
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2a00:1450:4001:80b::200e'] as $ip) {
    check(MailNet::isPublic($ip), "public address accepted: $ip");
}
check(MailNet::hostProblem('127.0.0.1') !== null && MailNet::hostProblem('[::1]') !== null, 'loopback hosts refused');
check(MailNet::hostProblem('localhost') !== null, 'localhost refused');
check(MailNet::hostProblem('bad host;rm') !== null, 'bad host syntax refused');
same('8.8.8.8', MailNet::target('8.8.8.8'), 'public IP connects as is');
same('[2606:4700:4700::1111]', MailNet::target('2606:4700:4700::1111'), 'IPv6 target bracketed');
putenv('MAIL_ALLOW_PRIVATE_HOSTS=1');
same('127.0.0.1', MailNet::target('127.0.0.1'), 'private allowed when configured');
putenv('MAIL_ALLOW_PRIVATE_HOSTS');

// ------------------------------------------------------------ SecureVault
$blob = SecureVault::encrypt('s3cret-pass');
same('s3cret-pass', SecureVault::decrypt($blob), 'vault round trip');
same('legacy-pw', SecureVault::decrypt(base64_encode('legacy-pw')), 'legacy base64 password');
same('a-long-legacy-password-of-29-bytes+', SecureVault::decrypt(base64_encode('a-long-legacy-password-of-29-bytes+')), 'long legacy password');
putenv('MAIL_SECRET_KEY=a-real-key-0123456789-abcdefghijkl');
same('s3cret-pass', SecureVault::decrypt($blob), 'dev-key password still opens after a key is set');
$blob2 = SecureVault::encrypt('new-pass');
same('new-pass', SecureVault::decrypt($blob2), 'real-key round trip');
putenv('MAIL_SECRET_KEY');
same('', SecureVault::decrypt($blob2), 'real-key blob does not open without the key');

echo "OK mail units ($n assertions)\n";
