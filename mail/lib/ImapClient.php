<?php
// mail/lib/ImapClient.php
//
// Minimal, dependency-free IMAP4rev1 client over PHP streams. ext-imap is not
// required (it is unbundled from PHP 8.4 and often missing on shared hosts).
// Supports SSL/STARTTLS, LOGIN, LIST (+SPECIAL-USE flags), SELECT/EXAMINE,
// UID SEARCH/FETCH/STORE/MOVE/COPY/EXPUNGE and APPEND, with full literal
// handling in responses.

class ImapClient
{
    /** @var resource|null */
    private $fp = null;
    private int $tagNo = 0;
    public array $caps = [];
    private ?string $selected = null;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $encryption = 'ssl',
        private bool $verify = true,
        private int $timeout = 60
    ) {}

    public function connect(): void
    {
        $enc = strtolower($this->encryption);
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $this->verify, 'verify_peer_name' => $this->verify,
            'allow_self_signed' => !$this->verify, 'peer_name' => $this->host, 'SNI_enabled' => true,
        ]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . ($this->port ?: 993);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $errstr = self::connectError($errstr);
            $hint = stripos($errstr, 'certificate') !== false
                ? ' — the server certificate does not match the host name; check the server name or untick "Verify TLS certificate"'
                : '';
            throw new RuntimeException("Could not connect to IMAP server {$this->host}:{$this->port} ($errstr)$hint");
        }
        $this->fp = $fp;
        stream_set_timeout($fp, $this->timeout);

        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new RuntimeException('IMAP server refused connection: ' . self::short($greeting));
        }
        $this->parseCapsFrom($greeting);

        if ($enc === 'tls') {
            if (!$this->caps) $this->capability();
            if (!in_array('STARTTLS', $this->caps, true)) {
                throw new RuntimeException('The IMAP server does not offer STARTTLS on port ' . $this->port . '. Try SSL on port 993.');
            }
            $this->command('STARTTLS');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $method)) {
                throw new RuntimeException('IMAP TLS negotiation failed (certificate or protocol mismatch)');
            }
            $this->caps = [];
        }
    }

    public function login(string $user, string $pass): void
    {
        if (!$this->caps) $this->capability();
        if (in_array('LOGINDISABLED', $this->caps, true)) {
            throw new RuntimeException('The IMAP server does not allow login on an unencrypted connection. Use SSL (993) or TLS.');
        }
        try {
            $res = $this->command(['LOGIN ', $this->astring($user), ' ', $this->astring($pass)]);
        } catch (ImapCommandException $e) {
            throw new RuntimeException('IMAP login failed: ' . $e->getMessage());
        }
        if (!$this->parseCapsFrom($res['text'])) $this->capability();
    }

    public function capability(): array
    {
        $res = $this->command('CAPABILITY');
        foreach ($res['lines'] as $l) {
            if (stripos($l, '* CAPABILITY ') === 0) {
                $this->caps = array_map('strtoupper', preg_split('/\s+/', trim(substr($l, 13))));
            }
        }
        return $this->caps;
    }

    public function hasCap(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    /** @return array<int, array{name:string, display:string, delim:string, flags:string[]}> */
    public function listFolders(): array
    {
        $res = $this->command('LIST "" "*"');
        $out = [];
        foreach ($res['untagged'] as $tok) {
            if (count($tok) < 5 || strtoupper((string)$tok[1]) !== 'LIST') continue;
            $flags = is_array($tok[2]) ? array_map(fn($f) => strtolower((string)$f), $tok[2]) : [];
            if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true)) continue;
            $name = (string)$tok[4];
            $out[] = [
                'name'    => $name,
                'display' => self::decodeFolderName($name),
                'delim'   => (string)($tok[3] ?? '/'),
                'flags'   => $flags,
            ];
        }
        return $out;
    }

    /** @return array{exists:int, uidvalidity:int, uidnext:int} */
    public function select(string $folder, bool $readOnly = false): array
    {
        $res = $this->command([($readOnly ? 'EXAMINE ' : 'SELECT '), $this->astring($folder)]);
        $this->selected = $folder;
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($res['lines'] as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) $info['exists'] = (int)$m[1];
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $l, $m)) $info['uidvalidity'] = (int)$m[1];
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $l, $m)) $info['uidnext'] = (int)$m[1];
        }
        return $info;
    }

    public function ensureSelected(string $folder): void
    {
        if ($this->selected !== $folder) $this->select($folder);
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        $res = $this->command('UID SEARCH ' . $criteria);
        $uids = [];
        foreach ($res['lines'] as $l) {
            if (stripos($l, '* SEARCH') === 0) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) as $n) {
                    if ($n !== '' && ctype_digit($n)) $uids[] = (int)$n;
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * @param int[]|string $uids
     * @return array<int, array<string, mixed>> uid => item map (keys upper-case: UID, FLAGS, INTERNALDATE, RFC822.SIZE, BODY[] ...)
     */
    public function uidFetch($uids, string $items): array
    {
        $set = is_array($uids) ? self::sequenceSet($uids) : $uids;
        if ($set === '') return [];
        $res = $this->command("UID FETCH $set ($items)");
        $out = [];
        foreach ($res['untagged'] as $tok) {
            if (count($tok) < 4 || strtoupper((string)$tok[2]) !== 'FETCH' || !is_array($tok[3])) continue;
            $map = [];
            $list = $tok[3];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $map[strtoupper((string)$list[$i])] = $list[$i + 1];
            }
            if (isset($map['UID'])) $out[(int)$map['UID']] = $map;
        }
        return $out;
    }

    public function uidStore($uids, string $mode, array $flags): void
    {
        $set = is_array($uids) ? self::sequenceSet($uids) : $uids;
        if ($set === '') return;
        $this->command("UID STORE $set {$mode}FLAGS.SILENT (" . implode(' ', $flags) . ')');
    }

    /**
     * Move messages; returns old uid => new uid when the server reports COPYUID.
     * @return array<int,int>
     */
    public function uidMove($uids, string $dest): array
    {
        $list = is_array($uids) ? $uids : [];
        $set = is_array($uids) ? self::sequenceSet($uids) : $uids;
        if ($set === '') return [];
        if ($this->hasCap('MOVE')) {
            $res = $this->command(["UID MOVE $set ", $this->astring($dest)]);
            return self::copyUidMap($res['lines'], $list);
        }
        $res = $this->command(["UID COPY $set ", $this->astring($dest)]);
        $map = self::copyUidMap($res['lines'], $list);
        $this->uidStore($set, '+', ['\\Deleted']);
        $this->expungeUids($set);
        return $map;
    }

    public function uidDelete($uids): void
    {
        $set = is_array($uids) ? self::sequenceSet($uids) : $uids;
        if ($set === '') return;
        $this->uidStore($set, '+', ['\\Deleted']);
        $this->expungeUids($set);
    }

    private function expungeUids(string $set): void
    {
        if ($this->hasCap('UIDPLUS')) $this->command("UID EXPUNGE $set");
        else $this->command('EXPUNGE');
    }

    /** APPEND a raw RFC 5322 message; returns the new UID when APPENDUID is reported. */
    public function append(string $folder, string $raw, array $flags = ['\\Seen']): ?int
    {
        $raw = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $tag = $this->nextTag();
        $folderArg = $this->astring($folder);
        if ($folderArg instanceof ImapLiteral) throw new RuntimeException('Unsupported folder name for APPEND');
        $this->write("$tag APPEND " . $folderArg . ' (' . implode(' ', $flags) . ') {' . strlen($raw) . "}\r\n");
        $cont = $this->readLine();
        if (!str_starts_with($cont, '+')) {
            throw new RuntimeException('APPEND refused: ' . self::short($cont));
        }
        $this->write($raw . "\r\n");
        $res = $this->readTagged($tag);
        if (preg_match('/\[APPENDUID \d+ (\d+)\]/i', $res['text'], $m)) return (int)$m[1];
        return null;
    }

    public function logout(): void
    {
        if (!$this->fp) return;
        try { $this->command('LOGOUT'); } catch (Throwable $e) { /* ignore */ }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->fp)) @fclose($this->fp);
        $this->fp = null;
    }

    // ------------------------------------------------------------------ core

    /**
     * Send a tagged command and read through to its completion.
     * $cmd is a string, or a list of strings / ImapLiteral parts.
     * @return array{status:string, text:string, lines:string[], untagged:array}
     */
    public function command($cmd): array
    {
        $tag = $this->nextTag();
        $buf = "$tag ";
        foreach ((array)$cmd as $part) {
            if ($part instanceof ImapLiteral) {
                if ($this->hasCap('LITERAL+')) {
                    $this->write($buf . '{' . strlen($part->data) . "+}\r\n");
                } else {
                    $this->write($buf . '{' . strlen($part->data) . "}\r\n");
                    $cont = $this->readLine();
                    if (!str_starts_with($cont, '+')) {
                        throw new ImapCommandException(self::short($cont));
                    }
                }
                $buf = $part->data;
            } else {
                $buf .= $part;
            }
        }
        $this->write($buf . "\r\n");
        return $this->readTagged($tag);
    }

    private function nextTag(): string
    {
        return 'F' . str_pad((string)(++$this->tagNo), 4, '0', STR_PAD_LEFT);
    }

    private function readTagged(string $tag): array
    {
        $lines = [];
        $untagged = [];
        while (true) {
            $chunks = $this->readResponseChunks();
            $first = $chunks[0][1];
            if (str_starts_with($first, $tag . ' ')) {
                $rest = substr($first, strlen($tag) + 1);
                [$status, $text] = array_pad(explode(' ', $rest, 2), 2, '');
                $status = strtoupper($status);
                if ($status !== 'OK') {
                    throw new ImapCommandException(self::short($text !== '' ? $text : $status));
                }
                return ['status' => $status, 'text' => $text, 'lines' => $lines, 'untagged' => $untagged];
            }
            if (str_starts_with($first, '*')) {
                $lines[] = $first;
                $untagged[] = self::parseTokens($chunks);
                continue;
            }
            // Unexpected continuation ("+") — ignore.
        }
    }

    /**
     * One logical response: text chunks interleaved with literal chunks.
     * @return array<int, array{0:string,1:string}> ['t', text] | ['l', bytes]
     */
    private function readResponseChunks(): array
    {
        $chunks = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\+?\}$/', $line, $m)) {
                $chunks[] = ['t', $line];
                $chunks[] = ['l', $this->readBytes((int)$m[1])];
                continue;
            }
            $chunks[] = ['t', $line];
            return $chunks;
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->fp);
        if ($line === false) {
            $meta = is_resource($this->fp) ? stream_get_meta_data($this->fp) : [];
            throw new RuntimeException(!empty($meta['timed_out']) ? 'IMAP server timed out' : 'IMAP connection closed unexpectedly');
        }
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->fp, min(65536, $n - strlen($buf)));
            if ($chunk === false || ($chunk === '' && feof($this->fp))) {
                throw new RuntimeException('IMAP connection closed while reading a message');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out'])) throw new RuntimeException('IMAP server timed out');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function write(string $data): void
    {
        if (!$this->fp) throw new RuntimeException('IMAP not connected');
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($this->fp, substr($data, $off, 65536));
            if ($n === false || $n === 0) throw new RuntimeException('IMAP connection lost while sending');
            $off += $n;
        }
    }

    /** Quote a string for IMAP; 8-bit or CR/LF content is sent as a literal. */
    private function astring(string $s)
    {
        if (preg_match('/[\r\n\x80-\xff]/', $s) || strlen($s) > 1000) {
            return new ImapLiteral($s);
        }
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    private function parseCapsFrom(string $text): bool
    {
        if (preg_match('/\[CAPABILITY ([^\]]+)\]/i', $text, $m)) {
            $this->caps = array_map('strtoupper', preg_split('/\s+/', trim($m[1])));
            return true;
        }
        return false;
    }

    // --------------------------------------------------------------- parsing

    /** Tokenise one response (with literals) into nested arrays. */
    public static function parseTokens(array $chunks): array
    {
        $stream = new ImapTokenStream($chunks);
        $out = [];
        while (($t = $stream->next()) !== ImapTokenStream::END) {
            $out[] = $t;
        }
        return $out;
    }

    /** [1,2,3,7,9,10] → "1:3,7,9:10" */
    public static function sequenceSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $parts = [];
        $n = count($uids);
        for ($i = 0; $i < $n; $i++) {
            $start = $uids[$i];
            while ($i + 1 < $n && $uids[$i + 1] === $uids[$i] + 1) $i++;
            $parts[] = $start === $uids[$i] ? (string)$start : $start . ':' . $uids[$i];
        }
        return implode(',', $parts);
    }

    private static function copyUidMap(array $lines, array $oldUids): array
    {
        foreach ($lines as $l) {
            if (preg_match('/\[COPYUID \d+ ([\d:,]+) ([\d:,]+)\]/i', $l, $m)) {
                $src = self::expandSet($m[1]);
                $dst = self::expandSet($m[2]);
                if (count($src) === count($dst)) return array_combine($src, $dst);
            }
        }
        return [];
    }

    private static function expandSet(string $set): array
    {
        $out = [];
        foreach (explode(',', $set) as $p) {
            if (str_contains($p, ':')) {
                [$a, $b] = array_map('intval', explode(':', $p));
                if ($b - $a > 100000) continue;
                for ($i = min($a, $b); $i <= max($a, $b); $i++) $out[] = $i;
            } else {
                $out[] = (int)$p;
            }
        }
        return $out;
    }

    public static function decodeFolderName(string $name): string
    {
        if (!str_contains($name, '&')) return $name;
        try {
            $d = mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
            return $d !== false ? $d : $name;
        } catch (Throwable $e) {
            return $name;
        }
    }

    /** stream_socket_client() leaves $errstr empty for TLS failures; the detail is in the warning. */
    public static function connectError(string $errstr): string
    {
        $last = error_get_last()['message'] ?? '';
        if (stripos($last, 'certificate verify failed') !== false || stripos($last, 'peer certificate') !== false) {
            return 'TLS certificate verification failed';
        }
        if ($errstr === '' && $last !== '') {
            return trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $last));
        }
        return $errstr !== '' ? $errstr : 'connection refused or timed out';
    }

    private static function short(string $s): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 200);
    }
}

class ImapCommandException extends RuntimeException {}

final class ImapLiteral
{
    public function __construct(public string $data) {}
}

/** Lexer/parser over response chunks (text + literal bytes). */
class ImapTokenStream
{
    public const END = "\0__END__";
    private int $ci = 0;   // chunk index
    private int $pos = 0;  // offset in current text chunk

    public function __construct(private array $chunks) {}

    public function next()
    {
        $tok = $this->lex();
        if ($tok === self::END) return self::END;
        if ($tok === '(') {
            $list = [];
            while (true) {
                $t = $this->next();
                if ($t === self::END || $t === ')') break;
                $list[] = $t;
            }
            return $list;
        }
        return $tok;
    }

    /** @return string|null|array  '(' / ')' as markers, null for NIL */
    private function lex()
    {
        while ($this->ci < count($this->chunks)) {
            [$kind, $data] = $this->chunks[$this->ci];
            if ($kind === 'l') {
                $this->ci++;
                $this->pos = 0;
                return $data;
            }
            $len = strlen($data);
            while ($this->pos < $len && $data[$this->pos] === ' ') $this->pos++;
            if ($this->pos >= $len) { $this->ci++; $this->pos = 0; continue; }

            $ch = $data[$this->pos];
            if ($ch === '(' || $ch === ')') { $this->pos++; return $ch; }
            if ($ch === '"') {
                $this->pos++;
                $buf = '';
                while ($this->pos < $len) {
                    $c = $data[$this->pos++];
                    if ($c === '\\' && $this->pos < $len) { $buf .= $data[$this->pos++]; continue; }
                    if ($c === '"') break;
                    $buf .= $c;
                }
                return $buf;
            }
            if ($ch === '{' && preg_match('/\{(\d+)\+?\}$/', substr($data, $this->pos))) {
                // Literal marker at end of this text chunk; the literal is the next chunk.
                $this->ci++;
                $this->pos = 0;
                continue;
            }
            // Atom (may contain [...] sections with spaces, e.g. BODY[HEADER.FIELDS (FROM)]).
            $buf = '';
            $depth = 0;
            while ($this->pos < $len) {
                $c = $data[$this->pos];
                if ($c === '[') $depth++;
                if ($c === ']') $depth = max(0, $depth - 1);
                if ($depth === 0 && ($c === ' ' || $c === '(' || $c === ')')) break;
                $buf .= $c;
                $this->pos++;
            }
            return strtoupper($buf) === 'NIL' ? null : $buf;
        }
        return self::END;
    }
}
