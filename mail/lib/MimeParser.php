<?php
// mail/lib/MimeParser.php
//
// Pure-PHP RFC 5322 / MIME parser (no ext-imap / ext-mailparse needed).
// Handles nested multiparts, base64 / quoted-printable, charsets, RFC 2047
// encoded words, RFC 2231 parameters, inline (cid:) images and attachments.

class MimeParser
{
    private const MAX_DEPTH = 12;

    /**
     * @return array{
     *   headers: array<string, string[]>, subject: string,
     *   from: array{email:string,name:string}|null, to: array, cc: array, reply_to: array,
     *   date: ?int, message_id: ?string, in_reply_to: ?string, references: string[],
     *   list_unsubscribe: ?string, text: string, html: string, attachments: array
     * }
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $headers = self::parseHeaders($headerBlock);

        $state = ['text' => [], 'html' => [], 'attachments' => []];
        self::walkPart($headers, $body, $state, 0, false);

        $first = fn(string $k) => $headers[$k][0] ?? null;

        $from = self::parseAddressList((string)$first('from'));
        $refs = [];
        foreach (preg_split('/\s+/', (string)$first('references')) as $r) {
            $id = self::cleanMessageId($r);
            if ($id !== null) $refs[] = $id;
        }

        $date = null;
        if ($d = $first('date')) {
            $d = trim(preg_replace('/\([^)]*\)/', '', $d));
            $ts = strtotime($d);
            if ($ts !== false && $ts > 0) $date = $ts;
        }

        $unsub = null;
        if ($lu = $first('list-unsubscribe')) {
            if (preg_match_all('/<([^>]+)>/', $lu, $m)) {
                foreach ($m[1] as $u) {
                    if (preg_match('#^https://#i', $u)) { $unsub = $u; break; }
                }
                if ($unsub === null) {
                    foreach ($m[1] as $u) {
                        if (preg_match('#^mailto:#i', $u)) { $unsub = $u; break; }
                    }
                }
            }
        }

        return [
            'headers'          => $headers,
            'subject'          => self::decodeHeader((string)$first('subject')),
            'from'             => $from[0] ?? null,
            'to'               => self::parseAddressList((string)implode(', ', $headers['to'] ?? [])),
            'cc'               => self::parseAddressList((string)implode(', ', $headers['cc'] ?? [])),
            'reply_to'         => self::parseAddressList((string)$first('reply-to')),
            'date'             => $date,
            'message_id'       => self::cleanMessageId((string)$first('message-id')),
            'in_reply_to'      => self::cleanMessageId((string)$first('in-reply-to')),
            'references'       => $refs,
            'list_unsubscribe' => $unsub,
            'text'             => trim(implode("\n\n", $state['text'])),
            'html'             => implode("\n", $state['html']),
            'attachments'      => $state['attachments'],
        ];
    }

    /** Headers only (cheap) — used for oversized messages. */
    public static function parseHeadersOnly(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock] = self::splitHeaderBody($raw);
        return self::parseHeaders($headerBlock);
    }

    private static function splitHeaderBody(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) return [$raw, ''];
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string, string[]> lower-cased name => raw (undecoded) values */
    public static function parseHeaders(string $block): array
    {
        $block = preg_replace("/\n[ \t]+/", ' ', $block);
        $headers = [];
        foreach (explode("\n", $block) as $line) {
            $p = strpos($line, ':');
            if ($p === false || $p === 0) continue;
            $name = strtolower(trim(substr($line, 0, $p)));
            if ($name === '' || preg_match('/\s/', $name)) continue;
            $headers[$name][] = trim(substr($line, $p + 1));
        }
        return $headers;
    }

    private static function walkPart(array $headers, string $body, array &$state, int $depth, bool $inAlternative): void
    {
        if ($depth > self::MAX_DEPTH) return;

        [$type, $params] = self::parseContentType($headers['content-type'][0] ?? 'text/plain');
        [$disposition, $dparams] = self::parseDisposition($headers['content-disposition'][0] ?? '');
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit'));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                // Broken multipart: treat as text.
                $state['text'][] = self::toUtf8(self::decodeBody($body, $encoding), $params['charset'] ?? null);
                return;
            }
            foreach (self::splitMultipart($body, $boundary) as $part) {
                [$ph, $pb] = self::splitHeaderBody($part);
                // A part with no header block at all starts with a blank line.
                if (str_starts_with($part, "\n")) { $ph = ''; $pb = substr($part, 1); }
                self::walkPart(self::parseHeaders($ph), $pb, $state, $depth + 1, $type === 'multipart/alternative');
            }
            return;
        }

        $filename = $dparams['filename'] ?? ($params['name'] ?? null);
        if ($filename !== null) $filename = self::decodeHeader($filename);
        $contentId = isset($headers['content-id'][0]) ? trim($headers['content-id'][0], " <>\t") : null;

        $isTextBody = ($type === 'text/plain' || $type === 'text/html')
            && $disposition !== 'attachment' && $filename === null;

        if ($isTextBody) {
            $content = str_replace(["\r\n", "\r"], "\n", self::toUtf8(self::decodeBody($body, $encoding), $params['charset'] ?? null));
            if ($type === 'text/html') {
                $state['html'][] = $content;
            } else {
                if (strtolower($params['format'] ?? '') === 'flowed') {
                    $content = self::unflow($content, strtolower($params['delsp'] ?? '') === 'yes');
                }
                $state['text'][] = $content;
            }
            return;
        }

        if ($type === 'message/rfc822' && $filename === null) {
            $inner = self::parseHeaders(self::splitHeaderBody(str_replace("\r\n", "\n", $body))[0]);
            $subj = self::decodeHeader($inner['subject'][0] ?? 'message');
            $filename = self::safeFilename($subj !== '' ? $subj : 'message') . '.eml';
        }

        $content = self::decodeBody($body, $encoding);
        if ($filename === null || $filename === '') {
            $ext = self::extensionFor($type);
            $filename = ($contentId ? 'image' : 'attachment') . $ext;
        }

        $inline = $contentId !== null && $disposition !== 'attachment' && str_starts_with($type, 'image/');
        $state['attachments'][] = [
            'filename'   => self::safeFilename($filename),
            'mime'       => $type,
            'content'    => $content,
            'size'       => strlen($content),
            'content_id' => $contentId !== null ? strtolower($contentId) : null,
            'inline'     => $inline,
        ];
    }

    /** @return string[] raw part strings (headers + body) */
    public static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $parts = [];
        $len = strlen($body);
        $offset = 0;
        $start = null;

        while ($offset <= $len) {
            $p = strpos($body, $delim, $offset);
            if ($p === false) break;
            // Delimiter must start a line.
            if ($p > 0 && $body[$p - 1] !== "\n") { $offset = $p + 1; continue; }
            $after = $p + strlen($delim);
            $isClose = substr($body, $after, 2) === '--';
            $eol = strpos($body, "\n", $after);
            $lineEnd = $eol === false ? $len : $eol;
            $rest = substr($body, $after + ($isClose ? 2 : 0), $lineEnd - $after - ($isClose ? 2 : 0));
            if (trim($rest) !== '') { $offset = $p + 1; continue; } // e.g. "--boundaryX" for a longer boundary

            if ($start !== null) {
                $end = $p > 0 ? $p - 1 : 0; // drop the newline that precedes the delimiter
                $parts[] = substr($body, $start, max(0, $end - $start));
            }
            if ($isClose) { $start = null; break; }
            $start = $eol === false ? $len : $eol + 1;
            $offset = $start;
        }
        if ($start !== null && $start < $len) {
            $parts[] = substr($body, $start); // unterminated final part
        }
        return $parts;
    }

    public static function decodeBody(string $body, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                return (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
            case 'quoted-printable':
                return quoted_printable_decode(str_replace("\n", "\r\n", $body));
            default:
                return $body;
        }
    }

    /** RFC 3676 format=flowed → normal paragraphs. */
    private static function unflow(string $text, bool $delsp): string
    {
        $out = '';
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if ($line !== '-- ' && str_ends_with($line, ' ')) {
                $out .= $delsp ? substr($line, 0, -1) : $line;
            } else {
                $out .= $line . "\n";
            }
        }
        return rtrim($out, "\n");
    }

    /** @return array{0:string,1:array<string,string>} */
    public static function parseContentType(string $value): array
    {
        $parts = self::splitParams($value);
        $type = strtolower(trim(array_shift($parts) ?? 'text/plain'));
        if (!str_contains($type, '/')) $type = 'text/plain';
        return [$type, self::collectParams($parts)];
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function parseDisposition(string $value): array
    {
        if (trim($value) === '') return ['', []];
        $parts = self::splitParams($value);
        $disp = strtolower(trim(array_shift($parts) ?? ''));
        return [$disp, self::collectParams($parts)];
    }

    /** Split "a; b=1; c=\"x;y\"" on semicolons outside quotes. */
    private static function splitParams(string $value): array
    {
        $out = [];
        $buf = '';
        $q = false;
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            if ($ch === '\\' && $q && $i + 1 < $n) { $buf .= $ch . $value[++$i]; continue; }
            if ($ch === '"') { $q = !$q; $buf .= $ch; continue; }
            if ($ch === ';' && !$q) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        $out[] = $buf;
        return $out;
    }

    /** Handles plain, quoted and RFC 2231 (name*=, name*0*=) parameters. */
    private static function collectParams(array $parts): array
    {
        $plain = [];
        $ext = []; // name => [index => [value, encoded]]
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) continue;
            $k = strtolower(trim(substr($p, 0, $eq)));
            $v = trim(substr($p, $eq + 1));
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
                $v = stripcslashes(substr($v, 1, -1));
            }
            if (preg_match('/^([a-z0-9_.-]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $name = $m[1];
                $idx = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
                $encoded = !empty($m[3]) || (!isset($m[2]) || $m[2] === '');
                $ext[$name][$idx] = [$v, $encoded];
            } else {
                $plain[$k] = $v;
            }
        }
        foreach ($ext as $name => $pieces) {
            ksort($pieces);
            $charset = 'utf-8';
            $acc = '';
            $first = true;
            foreach ($pieces as [$v, $encoded]) {
                if ($encoded && $first && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                    if ($m[1] !== '') $charset = $m[1];
                    $v = $m[2];
                }
                $acc .= $encoded ? rawurldecode($v) : $v;
                $first = false;
            }
            $plain[$name] = self::toUtf8($acc, $charset);
        }
        return $plain;
    }

    /** Decode RFC 2047 encoded words; fix raw 8-bit headers. */
    public static function decodeHeader(string $value): string
    {
        if ($value === '') return '';
        // Whitespace between adjacent encoded words is not significant.
        $value = preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/', '$1', $value);
        $decoded = preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // strip RFC 2231 language
            if (strtoupper($m[2]) === 'B') {
                $bytes = (string)base64_decode($m[3]);
            } else {
                $bytes = quoted_printable_decode(str_replace('_', ' ', $m[3]));
            }
            return self::toUtf8($bytes, $charset);
        }, $value);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = self::toUtf8($decoded, 'windows-1252');
        }
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $decoded));
    }

    public static function toUtf8(string $s, ?string $charset): string
    {
        $cs = strtolower(trim((string)$charset, " \t\"'"));
        $aliases = [
            'utf8' => 'utf-8', 'latin1' => 'iso-8859-1', 'latin-1' => 'iso-8859-1',
            'us-ascii' => 'utf-8', 'ascii' => 'utf-8', 'ansi_x3.4-1968' => 'utf-8',
            'ks_c_5601-1987' => 'cp949', 'gb2312' => 'gbk', 'x-gbk' => 'gbk', 'cp1252' => 'windows-1252',
            'unicode-1-1-utf-7' => 'utf-7', 'x-unknown' => '', 'unknown-8bit' => '',
        ];
        $cs = $aliases[$cs] ?? $cs;

        if ($cs === '' || $cs === 'utf-8') {
            if (mb_check_encoding($s, 'UTF-8')) return $s;
            $cs = 'windows-1252';
        }
        $out = null;
        try {
            $out = @mb_convert_encoding($s, 'UTF-8', $cs);
        } catch (Throwable $e) {
            $out = null;
        }
        if ($out === null || $out === false) {
            $out = @iconv($cs, 'UTF-8//IGNORE', $s);
        }
        if ($out === false || $out === null) {
            try { $out = mb_convert_encoding($s, 'UTF-8', 'windows-1252'); } catch (Throwable $e) { $out = ''; }
        }
        return mb_scrub((string)$out, 'UTF-8');
    }

    /**
     * Parse an address header into [['email' => ..., 'name' => ...], ...].
     * Supports quoted names with commas, groups, comments and encoded words.
     */
    public static function parseAddressList(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [];
        // Drop group syntax "Group: a@b, c@d;" → "a@b, c@d"
        $value = preg_replace('/^[^:"<>@,]+:\s*(.*?);?\s*$/s', '$1', $value) ?? $value;

        $tokens = [];
        $buf = '';
        $q = false; $angle = 0; $paren = 0;
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            if ($ch === '\\' && $q && $i + 1 < $n) { $buf .= $ch . $value[++$i]; continue; }
            if ($ch === '"' && !$paren) $q = !$q;
            elseif (!$q && $ch === '<') $angle++;
            elseif (!$q && $ch === '>') $angle = max(0, $angle - 1);
            elseif (!$q && $ch === '(') $paren++;
            elseif (!$q && $ch === ')') $paren = max(0, $paren - 1);
            if (($ch === ',' || $ch === ';') && !$q && !$angle && !$paren) {
                $tokens[] = $buf; $buf = ''; continue;
            }
            $buf .= $ch;
        }
        $tokens[] = $buf;

        $out = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $name = '';
            if (preg_match('/^(.*)<([^>]*)>\s*(\(.*\))?$/s', $tok, $m)) {
                $email = trim($m[2]);
                $name = trim($m[1]);
            } else {
                $email = $tok;
                if (preg_match('/\(([^)]*)\)/', $email, $c)) {
                    $name = $c[1];
                    $email = trim(preg_replace('/\([^)]*\)/', '', $email));
                }
            }
            if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $name = self::decodeHeader($name);
            $email = strtolower(trim($email, " \t\"'"));
            if ($email === '' || !str_contains($email, '@')) continue;
            if (strlen($email) > 254) continue;
            $out[] = ['email' => $email, 'name' => $name !== $email ? $name : ''];
        }
        return $out;
    }

    public static function cleanMessageId(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/<([^<>\s]+)>/', $v, $m)) $v = $m[1];
        $v = trim($v, '<> ');
        if ($v === '' || strlen($v) > 250 || preg_match('/\s/', $v)) return null;
        return $v;
    }

    /** Normalised subject for thread matching ("Re: Fwd: Hello" → "hello"). */
    public static function normalizeSubject(string $subject): string
    {
        $s = trim($subject);
        do {
            $prev = $s;
            $s = preg_replace('/^\s*(re|fw|fwd|aw|wg|sv|vs|antw|r|rif|tr)\s*(\[\d+\])?\s*:\s*/iu', '', $s);
            $s = preg_replace('/^\s*\[[^\]]{1,40}\]\s*/u', '', $s); // [list-tag]
        } while ($s !== $prev);
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    public static function isReplySubject(string $subject): bool
    {
        return (bool)preg_match('/^\s*(re|fw|fwd|aw|wg|sv|vs|antw|tr)\s*(\[\d+\])?\s*:/i', $subject);
    }

    public static function safeFilename(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '_', $name);
        $name = trim($name, " .");
        if ($name === '') $name = 'attachment';
        if (mb_strlen($name) > 180) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 170);
            $name = $ext !== '' ? "$base.$ext" : $base;
        }
        return $name;
    }

    private static function extensionFor(string $mime): string
    {
        $map = [
            'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'image/webp' => '.webp',
            'application/pdf' => '.pdf', 'text/calendar' => '.ics', 'text/plain' => '.txt',
            'text/html' => '.html', 'application/zip' => '.zip', 'message/rfc822' => '.eml',
            'text/csv' => '.csv',
        ];
        return $map[$mime] ?? '';
    }

    /** "Name <a@b>, c@d" for display/storage. */
    public static function formatAddressList(array $list): string
    {
        $parts = [];
        foreach ($list as $a) {
            $email = $a['email'] ?? '';
            if ($email === '') continue;
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') { $parts[] = $email; continue; }
            $needsQuote = preg_match('/[,;<>@"()\\\\:\[\]]/', $name);
            $parts[] = ($needsQuote ? '"' . addcslashes($name, '"\\') . '"' : $name) . " <$email>";
        }
        return implode(', ', $parts);
    }
}
