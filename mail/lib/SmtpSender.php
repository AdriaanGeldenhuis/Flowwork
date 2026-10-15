<?php
// mail/lib/SmtpSender.php
//
// SMTP client. Public API is unchanged for existing callers (CRM email,
// payroll payslips):  SmtpSender::send([...]) → ['ok'=>bool, 'error'=>?string]
// and now also returns 'message_id' and 'raw' (used to file a copy in Sent).
//
// Accepted options:
//   host, port, encryption ('ssl'|'tls'|'none'), username, password, tls_verify (bool, default true)
//   from, from_name, reply_to, to[], cc[], bcc[], subject, html, text,
//   attachments [['filename','content','mime', 'cid'?, 'inline'?]],
//   message_id, in_reply_to, references
//   raw + message_id  — send a prebuilt message instead (Outbox)
//
// Fixes over the previous implementation: Bcc no longer leaked as a header,
// every SMTP reply code is checked, dot-stuffing on DATA, STARTTLS is required
// when TLS is selected (no silent plaintext fallback), AUTH PLAIN/LOGIN per
// server capabilities, proper EHLO name, RFC 2047 subjects and names.

require_once __DIR__ . '/MimeParser.php';
require_once __DIR__ . '/HtmlSanitizer.php';
require_once __DIR__ . '/MimeBuilder.php';
require_once __DIR__ . '/ImapClient.php';

class SmtpSender
{
    /** @var resource|null */
    private $fp = null;
    private array $ext = [];

    public static function send(array $opts): array
    {
        $host = trim((string)($opts['host'] ?? ''));
        $from = (string)($opts['from'] ?? '');
        if ($host === '' || $from === '') {
            return ['ok' => false, 'error' => 'Missing SMTP parameters (server or From address)'];
        }

        $to  = MimeBuilder::normalizeList((array)($opts['to'] ?? []));
        $cc  = MimeBuilder::normalizeList((array)($opts['cc'] ?? []));
        $bcc = MimeBuilder::normalizeList((array)($opts['bcc'] ?? []));
        $envelope = [];
        foreach ([$to, $cc, $bcc] as $list) foreach ($list as $a) $envelope[$a['email']] = true;
        $envelope = array_keys($envelope);
        if (!$envelope) return ['ok' => false, 'error' => 'No valid recipients'];

        try {
            if (!empty($opts['raw'])) {
                $raw = (string)$opts['raw'];
                $messageId = (string)($opts['message_id'] ?? '');
            } else {
                $built = MimeBuilder::build([
                    'from' => $from, 'from_name' => $opts['from_name'] ?? '',
                    'to' => $to, 'cc' => $cc, 'reply_to' => $opts['reply_to'] ?? '',
                    'subject' => $opts['subject'] ?? '', 'html' => $opts['html'] ?? '',
                    'text' => $opts['text'] ?? '', 'attachments' => $opts['attachments'] ?? [],
                    'message_id' => $opts['message_id'] ?? null, 'in_reply_to' => $opts['in_reply_to'] ?? null,
                    'references' => $opts['references'] ?? null,
                ]);
                $raw = $built['raw'];
                $messageId = $built['message_id'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not build message: ' . $e->getMessage()];
        }

        $fromAddr = MimeBuilder::normalizeList([$from])[0]['email'] ?? '';
        if ($fromAddr === '') return ['ok' => false, 'error' => 'Invalid From address'];

        if (!function_exists('stream_socket_client')) {
            return self::sendViaMailFunction($raw, $envelope, $messageId);
        }

        $c = new self();
        try {
            $c->open($opts);
            $c->auth((string)($opts['username'] ?? ''), (string)($opts['password'] ?? ''));
            $c->expect($c->cmd('MAIL FROM:<' . $fromAddr . '>'), [250], 'Sender rejected');
            $accepted = 0;
            $rejected = [];
            foreach ($envelope as $rcpt) {
                [$code, $text] = $c->cmd('RCPT TO:<' . $rcpt . '>');
                if ($code === 250 || $code === 251) $accepted++;
                else $rejected[] = $rcpt . ' (' . self::short($text) . ')';
            }
            if ($accepted === 0) {
                throw new RuntimeException('All recipients were rejected: ' . implode('; ', $rejected));
            }
            $c->expect($c->cmd('DATA'), [354], 'Server refused message data');
            $c->writeData($raw);
            $c->expect($c->read(), [250], 'Message was not accepted');
            $c->quit();
            return ['ok' => true, 'message_id' => $messageId, 'raw' => $raw, 'rejected' => $rejected];
        } catch (Throwable $e) {
            $c->close();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Connect + authenticate only (settings "Test connection"). */
    public static function test(array $opts): array
    {
        $c = new self();
        try {
            $c->open($opts);
            $c->auth((string)($opts['username'] ?? ''), (string)($opts['password'] ?? ''));
            $c->quit();
            return ['ok' => true];
        } catch (Throwable $e) {
            $c->close();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function open(array $opts): void
    {
        $host = trim((string)$opts['host']);
        $port = (int)($opts['port'] ?? 587) ?: 587;
        $enc  = strtolower((string)($opts['encryption'] ?? 'tls'));
        $verify = !array_key_exists('tls_verify', $opts) || !empty($opts['tls_verify']);

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $errstr = ImapClient::connectError($errstr);
            $hint = (stripos($errstr, 'certificate') !== false)
                ? ' — the server certificate does not match the host name; check the server name or untick "Verify TLS certificate"'
                : '';
            throw new RuntimeException("Could not connect to SMTP server $host:$port ($errstr)$hint");
        }
        $this->fp = $fp;
        stream_set_timeout($fp, 45);

        $this->expect($this->read(), [220], 'SMTP server did not greet');
        $this->ehlo();

        if ($enc === 'tls') {
            if (!isset($this->ext['STARTTLS'])) {
                throw new RuntimeException('The SMTP server does not offer STARTTLS on port ' . $port . '. Try SSL on port 465.');
            }
            $this->expect($this->cmd('STARTTLS'), [220], 'STARTTLS refused');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $method)) {
                throw new RuntimeException('TLS negotiation failed (certificate or protocol mismatch)');
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $name = self::heloName();
        [$code, $text] = $this->cmd('EHLO ' . $name);
        if ($code !== 250) {
            $this->expect($this->cmd('HELO ' . $name), [250], 'HELO rejected');
            $this->ext = [];
            return;
        }
        $this->ext = [];
        foreach (explode("\n", $text) as $i => $line) {
            if ($i === 0) continue;
            $line = trim($line);
            if ($line === '') continue;
            $parts = preg_split('/[ =]/', $line, 2);
            $this->ext[strtoupper($parts[0])] = $parts[1] ?? '';
        }
    }

    private function auth(string $user, string $pass): void
    {
        if ($user === '' || $pass === '') return;
        $mechs = strtoupper($this->ext['AUTH'] ?? '');
        if (str_contains($mechs, 'PLAIN')) {
            [$code, $text] = $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
            if ($code === 235) return;
            if (!str_contains($mechs, 'LOGIN')) throw new RuntimeException('SMTP authentication failed: ' . self::short($text));
        }
        [$code, $text] = $this->cmd('AUTH LOGIN');
        if ($code !== 334) throw new RuntimeException('SMTP authentication not supported: ' . self::short($text));
        [$code, $text] = $this->cmd(base64_encode($user));
        if ($code !== 334) throw new RuntimeException('SMTP authentication failed: ' . self::short($text));
        [$code, $text] = $this->cmd(base64_encode($pass));
        if ($code !== 235) throw new RuntimeException('SMTP authentication failed: ' . self::short($text));
    }

    private function writeData(string $raw): void
    {
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing (RFC 5321 4.5.2)
        if (!str_ends_with($data, "\r\n")) $data .= "\r\n";
        $data .= ".\r\n";
        $this->write($data);
    }

    private function cmd(string $line): array
    {
        $this->write($line . "\r\n");
        return $this->read();
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($this->fp, substr($data, $off, 65536));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Connection to SMTP server lost while sending');
            }
            $off += $n;
        }
    }

    /** @return array{0:int,1:string} */
    private function read(): array
    {
        $lines = [];
        while (true) {
            $line = @fgets($this->fp, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP server timed out' : 'SMTP connection closed unexpectedly');
            }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $code = (int)substr($lines[count($lines) - 1], 0, 3);
        $text = implode("\n", array_map(fn($l) => substr($l, 4), $lines));
        return [$code, $text];
    }

    private function expect(array $reply, array $codes, string $what): void
    {
        if (!in_array($reply[0], $codes, true)) {
            throw new RuntimeException($what . ': ' . $reply[0] . ' ' . self::short($reply[1]));
        }
    }

    private function quit(): void
    {
        try { $this->cmd('QUIT'); } catch (Throwable $e) { /* ignore */ }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->fp)) @fclose($this->fp);
        $this->fp = null;
    }

    private static function short(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_substr($s, 0, 200);
    }

    private static function heloName(): string
    {
        $host = parse_url(defined('APP_BASE_URL') ? APP_BASE_URL : '', PHP_URL_HOST);
        if (!$host) $host = gethostname() ?: 'localhost';
        return preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';
    }

    /** Last-resort transport when sockets are disabled on the host. */
    private static function sendViaMailFunction(string $raw, array $envelope, string $messageId): array
    {
        if (!function_exists('mail')) return ['ok' => false, 'error' => 'No mail transport available'];
        [$headers, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
        $subject = '';
        $keep = [];
        foreach (preg_split("/\r\n(?![ \t])/", $headers) as $h) {
            if (stripos($h, 'Subject:') === 0) { $subject = trim(substr($h, 8)); continue; }
            if (stripos($h, 'To:') === 0) continue; // mail() adds To from the first argument
            $keep[] = $h;
        }
        $ok = @mail(implode(', ', $envelope), $subject, $body, implode("\r\n", $keep));
        return $ok ? ['ok' => true, 'message_id' => $messageId, 'raw' => $raw, 'transport' => 'mail()']
                   : ['ok' => false, 'error' => 'PHP mail() failed'];
    }
}
