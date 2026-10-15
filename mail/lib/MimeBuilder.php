<?php
// mail/lib/MimeBuilder.php
//
// Builds an RFC 5322 / MIME message for sending.
//   - Bcc is never written to the headers (recipients would see each other).
//   - Header values are stripped of CR/LF (header injection) and non-ASCII is
//     RFC 2047 encoded; non-ASCII filenames use RFC 2231.
//   - Bodies are quoted-printable (short lines, 8-bit safe); attachments base64.
//   - Structure: mixed[ alternative[ text, related[ html, inline images ] ], attachments ].

class MimeBuilder
{
    /**
     * @param array{
     *   from:string, from_name?:string, to?:array, cc?:array, reply_to?:string,
     *   subject?:string, html?:string, text?:string, attachments?:array,
     *   message_id?:string, in_reply_to?:string, references?:string, date?:int,
     *   extra_headers?:array<string,string>
     * } $m  to/cc entries: "a@b", "Name <a@b>" or ['email'=>..,'name'=>..]
     * @return array{raw:string, message_id:string, headers:string}
     */
    public static function build(array $m): array
    {
        $from = self::normalizeList([$m['from'] ?? '']);
        if (!$from) throw new InvalidArgumentException('Invalid From address');
        $fromAddr = $from[0]['email'];
        $fromName = trim((string)($m['from_name'] ?? $from[0]['name'] ?? ''));

        $domain = substr(strrchr($fromAddr, '@') ?: '@localhost', 1) ?: 'localhost';
        $messageId = $m['message_id'] ?? (bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain);
        $messageId = trim($messageId, '<> ');

        $html = (string)($m['html'] ?? '');
        $text = (string)($m['text'] ?? '');
        if ($text === '' && $html !== '') $text = HtmlSanitizer::toText($html);
        if ($html === '' && $text !== '') $html = HtmlSanitizer::fromText($text);

        $inline = [];
        $attach = [];
        foreach (($m['attachments'] ?? []) as $a) {
            if (!empty($a['cid']) && !empty($a['inline'])) $inline[] = $a; else $attach[] = $a;
        }

        $h = [];
        $h[] = 'Date: ' . date('r', $m['date'] ?? time());
        $h[] = 'From: ' . self::formatAddress($fromAddr, $fromName);
        $to = self::normalizeList($m['to'] ?? []);
        $cc = self::normalizeList($m['cc'] ?? []);
        if ($to) $h[] = 'To: ' . self::formatAddressList($to);
        if ($cc) $h[] = 'Cc: ' . self::formatAddressList($cc);
        if (!$to && !$cc) $h[] = 'To: undisclosed-recipients:;';
        if (!empty($m['reply_to'])) {
            $rt = self::normalizeList([$m['reply_to']]);
            if ($rt) $h[] = 'Reply-To: ' . self::formatAddressList($rt);
        }
        $h[] = 'Subject: ' . self::encodeHeader(self::clean((string)($m['subject'] ?? '')), 9);
        $h[] = 'Message-ID: <' . self::clean($messageId) . '>';
        if (!empty($m['in_reply_to'])) $h[] = 'In-Reply-To: <' . self::clean(trim($m['in_reply_to'], '<> ')) . '>';
        if (!empty($m['references'])) {
            $refs = [];
            foreach (preg_split('/\s+/', self::clean((string)$m['references'])) as $r) {
                $r = trim($r, '<> ');
                if ($r !== '') $refs[] = "<$r>";
            }
            if ($refs) $h[] = 'References: ' . implode("\r\n ", array_slice($refs, -20));
        }
        foreach (($m['extra_headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9-]+$/', (string)$k)) $h[] = $k . ': ' . self::encodeHeader(self::clean((string)$v), strlen($k) + 2);
        }
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Mailer: Flowwork Mail';

        // --- body tree ---
        $textPart = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($text);
        $htmlDoc = self::wrapHtml($html);
        $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::qp($htmlDoc);

        if ($inline) {
            $rb = self::boundary();
            $rel = "Content-Type: multipart/related; boundary=\"$rb\"\r\n\r\n"
                . "--$rb\r\n" . $htmlPart . "\r\n";
            foreach ($inline as $a) {
                $rel .= "--$rb\r\n" . self::attachmentPart($a, true) . "\r\n";
            }
            $rel .= "--$rb--";
            $htmlPart = $rel;
        }

        $ab = self::boundary();
        $alt = "Content-Type: multipart/alternative; boundary=\"$ab\"\r\n\r\n"
            . "--$ab\r\n" . $textPart . "\r\n"
            . "--$ab\r\n" . $htmlPart . "\r\n"
            . "--$ab--";

        if ($attach) {
            $mb = self::boundary();
            $bodyPart = "Content-Type: multipart/mixed; boundary=\"$mb\"\r\n\r\n"
                . "This is a multi-part message in MIME format.\r\n\r\n"
                . "--$mb\r\n" . $alt . "\r\n";
            foreach ($attach as $a) {
                $bodyPart .= "--$mb\r\n" . self::attachmentPart($a, false) . "\r\n";
            }
            $bodyPart .= "--$mb--";
        } else {
            $bodyPart = $alt;
        }

        // $bodyPart starts with its own Content-Type header line(s).
        [$ctHeaders, $ctBody] = explode("\r\n\r\n", $bodyPart, 2);
        $headers = implode("\r\n", $h) . "\r\n" . $ctHeaders;
        $raw = $headers . "\r\n\r\n" . $ctBody . "\r\n";

        return ['raw' => $raw, 'message_id' => $messageId, 'headers' => $headers];
    }

    private static function wrapHtml(string $html): string
    {
        if (stripos($html, '<html') !== false) return $html;
        return "<!DOCTYPE html><html><head><meta charset=\"UTF-8\">"
            . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"></head>"
            . "<body style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f1a17\">"
            . $html . "</body></html>";
    }

    private static function attachmentPart(array $a, bool $inline): string
    {
        $name = MimeParser::safeFilename((string)($a['filename'] ?? 'attachment'));
        $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string)($a['mime'] ?? '')) ? $a['mime'] : 'application/octet-stream';
        $out = "Content-Type: $mime; " . self::paramName('name', $name) . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . self::paramName('filename', $name) . "\r\n";
        if ($inline && !empty($a['cid'])) {
            $out .= 'Content-ID: <' . self::clean(trim((string)$a['cid'], '<> ')) . ">\r\n";
        }
        return $out . "\r\n" . rtrim(chunk_split(base64_encode((string)($a['content'] ?? '')), 76, "\r\n"));
    }

    private static function paramName(string $param, string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $param . '="' . addcslashes($value, '"\\') . '"';
        }
        // RFC 2231 for modern clients + an encoded-word for older ones.
        $legacy = $param . '="' . self::encodeWord($value) . '"';
        return $param . "*=UTF-8''" . rawurlencode($value) . ";\r\n " . $legacy;
    }

    private static function boundary(): string
    {
        return '=_fw_' . bin2hex(random_bytes(12));
    }

    private static function qp(string $s): string
    {
        $s = preg_replace("/\r\n|\r|\n/", "\r\n", $s);
        return quoted_printable_encode($s);
    }

    public static function clean(string $v): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $v));
    }

    public static function encodeHeader(string $v, int $offset = 0): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $v)) {
            return $v; // ASCII; keep as-is (well under the 998-octet line limit for real subjects)
        }
        return self::encodeWord($v);
    }

    /** RFC 2047 "B" encoding, split into chunks that never break a UTF-8 char. */
    private static function encodeWord(string $v): string
    {
        $words = [];
        $chunk = '';
        foreach (mb_str_split($v, 1, 'UTF-8') as $ch) {
            if (strlen($chunk . $ch) > 42) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        return implode("\r\n ", $words);
    }

    public static function formatAddress(string $email, string $name = ''): string
    {
        $email = self::clean($email);
        $name = self::clean($name);
        if ($name === '' || strcasecmp($name, $email) === 0) return $email;
        if (preg_match('/^[\x20-\x7E]*$/', $name)) {
            $quoted = preg_match('/[()<>\[\]:;@\\\\,."]/', $name) ? '"' . addcslashes($name, '"\\') . '"' : $name;
            return "$quoted <$email>";
        }
        return self::encodeWord($name) . " <$email>";
    }

    private static function formatAddressList(array $list): string
    {
        $out = [];
        foreach ($list as $a) $out[] = self::formatAddress($a['email'], $a['name'] ?? '');
        return implode(",\r\n ", $out);
    }

    /** Accepts strings ("a@b", "Name <a@b>", "a@b, c@d") or ['email','name'] arrays; validates. */
    public static function normalizeList(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $it) {
            $parsed = is_array($it)
                ? [['email' => strtolower(trim((string)($it['email'] ?? ''))), 'name' => (string)($it['name'] ?? '')]]
                : MimeParser::parseAddressList(self::clean((string)$it));
            foreach ($parsed as $a) {
                $e = $a['email'];
                if ($e === '' || isset($seen[$e]) || !self::validEmail($e)) continue;
                $seen[$e] = true;
                $out[] = ['email' => $e, 'name' => self::clean($a['name'] ?? '')];
            }
        }
        return $out;
    }

    public static function validEmail(string $email): bool
    {
        if (strlen($email) > 254 || preg_match('/[\s<>(),;:"\\\\\[\]]/', $email)) return false;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) return true;
        // Allow internationalised local parts / IDN domains FILTER_VALIDATE_EMAIL rejects.
        return (bool)preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $email);
    }
}
