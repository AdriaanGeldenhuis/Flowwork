<?php
// /receipts/lib/RcReader.php — reads a slip into supplier, number, dates,
// amounts and lines. Never makes anything up: what cannot be read stays
// empty and the person types it in (the old engine invented a supplier,
// an invoice number and random amounts whenever reading failed).
//
// Readers, chosen under Settings → Reading:
//   ai         the company's OpenAI key from Suppliers AI (ai_settings) reads
//              the photo or PDF; it also picks the likely expense account from
//              the chart. Only the document and the expense account names are
//              sent. Falls back to the text readers when it cannot answer.
//   tesseract  text recognition on the server (tesseract, pdftoppm), then RcParse
//   manual     nothing is read
// A PDF with a text layer is always read from its text when pdftotext is
// installed — that is exact and needs no recognition.

final class RcReader
{
    private const SYSTEM = <<<'TXT'
You read South African till slips, tax invoices and supplier invoices for a bookkeeper.
Copy only what is printed on the document. Never guess or make anything up: use null when something is not on it.
Amounts are plain numbers without currency symbols or thousands separators, in the document's currency (rand unless another currency is printed).
Dates are YYYY-MM-DD. South African documents write the day before the month (12/10/2026 is 12 October 2026).
supplier_name is the seller (the business that issued the document), not the buyer.
supplier_vat_number is the seller's VAT number (10 digits starting with 4), not the buyer's.
document_number is the invoice, tax invoice, slip or receipt number.
total is the amount payable including VAT; vat_amount is the VAT charged; subtotal_excl_vat is the amount before VAT.
lines are the items bought with their printed amounts; leave out totals, VAT, change, tender and card lines. prices_include_vat says whether the line amounts include VAT.
is_bill is false when the document is not a receipt or invoice (a quote, statement, delivery note, or not a document at all).
account_code: the best account for this purchase from the chart of accounts given, or null when none fits.
TXT;

    private static array $can = [];

    /** May the server run this program (exec allowed and the program installed)? */
    public static function canRun(string $bin): bool
    {
        if (isset(self::$can[$bin])) return self::$can[$bin];
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true) || !preg_match('/^[a-z0-9_-]+$/', $bin)) return self::$can[$bin] = false;
        $out = [];
        @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $rc);
        return self::$can[$bin] = ($rc === 0 && !empty($out));
    }

    /** The company's OpenAI key (from Suppliers AI), or null. */
    public static function aiKey(PDO $db, int $companyId): ?string
    {
        try {
            $st = $db->prepare("SELECT api_keys_json FROM ai_settings WHERE company_id = ? LIMIT 1");
            $st->execute([$companyId]);
            $keys = json_decode((string)$st->fetchColumn(), true);
        } catch (Throwable $e) {
            return null;
        }
        $key = trim((string)($keys['openai_api_key'] ?? ''));
        return preg_match('/^sk-[A-Za-z0-9_\-]{20,}$/', $key) ? $key : null;
    }

    /** What this server and company can use. */
    public static function engines(PDO $db, int $companyId): array
    {
        return ['ai' => self::aiKey($db, $companyId) !== null, 'tesseract' => self::canRun('tesseract'), 'pdftotext' => self::canRun('pdftotext'),
                'pdftoppm' => self::canRun('pdftoppm'), 'gd' => function_exists('imagecreatetruecolor')];
    }

    /**
     * Read a receipt row and store the result. Never throws for a reading that
     * did not work: the receipt is then marked "could not be read" with the reason.
     * @return array{ok:bool, engine:?string, error:?string, fields:array}
     */
    public static function readAndStore(PDO $db, int $companyId, int $userId, array $file, array $settings): array
    {
        try {
            $r = self::read($db, $companyId, $file, $settings);
        } catch (Throwable $e) {
            error_log('[receipts] read ' . $file['file_id'] . ': ' . $e->getMessage());
            $r = ['ok' => false, 'engine' => null, 'error' => 'Reading stopped unexpectedly.', 'fields' => self::blank(), 'text' => '', 'account_code' => null];
        }
        self::store($db, (int)$file['file_id'], $r);
        RcKit::audit($db, $companyId, $userId, 'receipt_read', (int)$file['file_id'], ['engine' => $r['engine'], 'ok' => $r['ok'], 'error' => $r['error']]);
        return ['ok' => $r['ok'], 'engine' => $r['engine'], 'error' => $r['error'], 'fields' => $r['fields']];
    }

    public static function read(PDO $db, int $companyId, array $file, array $settings): array
    {
        $path = RcFiles::path($companyId, $file['path'] ?? '');
        if (!$path) return self::fail(null, 'The file is no longer on the server.');
        $mime = (string)$file['mime'];
        $provider = (string)$settings['ocr_provider'];
        if ($provider === 'manual') return self::fail('manual', null);

        $pdfText = $mime === 'application/pdf' ? self::pdfText($path) : '';
        $aiError = null;
        if ($provider === 'ai') {
            $key = self::aiKey($db, $companyId);
            if (!$key) {
                $aiError = 'The AI reader needs an OpenAI key under Suppliers AI → Settings.';
            } elseif (($wait = RcKit::rateLimit('ai:' . $companyId, 300, 3600)) > 0) {
                $aiError = 'The AI reader is paused for ' . ceil($wait / 60) . ' minutes (300 slips an hour).';
            } else {
                $r = self::viaAi($db, $companyId, $key, (string)$settings['ai_model'], $path, $mime, $pdfText);
                if ($r['ok']) return $r;
                $aiError = $r['error'];
            }
        }
        if (self::usable($pdfText)) {
            $f = RcParse::fields($pdfText);
            return ['ok' => self::found($f), 'engine' => 'pdf-text', 'error' => self::found($f) ? null : ($aiError ?: 'Nothing recognisable in the PDF.'), 'fields' => $f, 'text' => $pdfText, 'account_code' => null];
        }
        if (self::canRun('tesseract')) {
            $text = self::ocr($path, $mime);
            if (self::usable($text)) {
                $f = RcParse::fields($text);
                return ['ok' => self::found($f), 'engine' => 'tesseract', 'error' => self::found($f) ? null : ($aiError ?: 'The slip could not be read clearly.'), 'fields' => $f, 'text' => $text, 'account_code' => null];
            }
            return self::fail('tesseract', $aiError ?: 'The slip could not be read clearly — a sharper, flatter photo helps.');
        }
        return self::fail($provider === 'ai' ? 'ai' : null, $aiError ?: 'This server cannot read photos. Type the details in, or switch on the AI reader in Settings.');
    }

    // --------------------------------------------------------------- AI reader

    private static function viaAi(PDO $db, int $companyId, string $key, string $model, string $path, string $mime, string $pdfText): array
    {
        if (!function_exists('curl_init')) return self::fail('ai', 'The server cannot reach the AI reader (no cURL).');
        $parts = [['type' => 'text', 'text' => self::chartPrompt($db, $companyId)]];
        if ($mime === 'application/pdf') {
            if (self::usable($pdfText)) {
                $parts[] = ['type' => 'text', 'text' => "The document's text:\n" . mb_substr($pdfText, 0, 30000)];
            } else {
                if (filesize($path) > 15 * 1048576) return self::fail('ai', 'The PDF is too large for the AI reader.');
                $parts[] = ['type' => 'file', 'file' => ['filename' => 'receipt.pdf', 'file_data' => 'data:application/pdf;base64,' . base64_encode((string)file_get_contents($path))]];
            }
        } else {
            [$bytes, $imgMime] = self::imageForAi($path, $mime);
            if ($bytes === null) return self::fail('ai', 'The photo could not be prepared for the AI reader.');
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $imgMime . ';base64,' . base64_encode($bytes), 'detail' => 'high']];
        }
        $str = ['type' => ['string', 'null']];
        $num = ['type' => ['number', 'null']];
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['is_bill', 'supplier_name', 'supplier_vat_number', 'document_number', 'document_date', 'due_date', 'currency', 'subtotal_excl_vat', 'vat_amount', 'total', 'prices_include_vat', 'lines', 'account_code'],
            'properties' => [
                'is_bill' => ['type' => 'boolean'], 'supplier_name' => $str, 'supplier_vat_number' => $str, 'document_number' => $str, 'document_date' => $str, 'due_date' => $str,
                'currency' => $str, 'subtotal_excl_vat' => $num, 'vat_amount' => $num, 'total' => $num, 'prices_include_vat' => ['type' => ['boolean', 'null']],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['description', 'quantity', 'unit_price', 'amount'],
                    'properties' => ['description' => ['type' => 'string'], 'quantity' => $num, 'unit_price' => $num, 'amount' => $num]]],
                'account_code' => $str,
            ],
        ];
        $body = json_encode([
            'model' => $model, 'temperature' => 0, 'max_tokens' => 2000,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'receipt', 'strict' => true, 'schema' => $schema]],
            'messages' => [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $parts]],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 75,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('[receipts] AI reader: ' . $err);
            return self::fail('ai', 'The AI reader could not be reached.');
        }
        $res = json_decode((string)$raw, true);
        if ($code !== 200) {
            $msg = (string)($res['error']['message'] ?? ('HTTP ' . $code));
            error_log('[receipts] AI reader ' . $code . ': ' . mb_substr($msg, 0, 300));
            return self::fail('ai', $code === 401 ? 'The OpenAI key under Suppliers AI was refused.' : ($code === 429 ? 'The AI reader is busy or out of credit — try again later.' : 'The AI reader could not read this one.'));
        }
        $out = json_decode((string)($res['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($out)) return self::fail('ai', 'The AI reader gave no answer.');
        $f = self::fromAi($out);
        if (empty($out['is_bill'])) {
            return ['ok' => false, 'engine' => 'ai', 'error' => 'This does not look like a receipt or invoice.', 'fields' => $f, 'text' => '', 'account_code' => null];
        }
        $acc = is_string($out['account_code'] ?? null) ? mb_substr(trim($out['account_code']), 0, 20) : null;
        return ['ok' => self::found($f), 'engine' => 'ai', 'error' => self::found($f) ? null : 'Nothing could be read from it.', 'fields' => $f, 'text' => '', 'account_code' => $acc ?: null];
    }

    /** The expense accounts the AI may choose from (names only — no balances). */
    private static function chartPrompt(PDO $db, int $companyId): string
    {
        $rows = [];
        try {
            $st = $db->prepare("SELECT a.account_code, a.account_name FROM gl_accounts a WHERE a.company_id = ? AND a.account_type = 'expense' AND a.is_active = 1 AND COALESCE(a.is_control, 0) = 0
                                   AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.company_id = a.company_id AND c.parent_id = a.account_id)
                                 ORDER BY a.account_code LIMIT 150");
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$c, $n]) $rows[] = $c . ' — ' . $n;
        } catch (Throwable $e) {
        }
        return "Read this document.\n" . ($rows ? "Chart of accounts (code — name):\n" . implode("\n", $rows) : 'There is no chart of accounts; set account_code to null.');
    }

    /** JPEG/PNG/WebP bytes small enough to send (long side 2000 px). @return array{0:?string,1:string} */
    private static function imageForAi(string $path, string $mime): array
    {
        $size = (int)filesize($path);
        $info = @getimagesize($path);
        if ($info && $size <= 3500000 && max($info[0], $info[1]) <= 2600) return [(string)file_get_contents($path), $mime];
        if (!function_exists('imagecreatetruecolor') || !$info) return [$size <= 15000000 ? (string)file_get_contents($path) : null, $mime];
        $img = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path),
                               'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false, default => false };
        if (!$img) return [null, $mime];
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $angle = [3 => 180, 6 => -90, 8 => 90][(int)(@exif_read_data($path)['Orientation'] ?? 1)] ?? 0;
            if ($angle && ($r = imagerotate($img, $angle, 0))) { imagedestroy($img); $img = $r; }
        }
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, 2000 / max($w, $h));
        $t = imagecreatetruecolor(max(1, (int)($w * $scale)), max(1, (int)($h * $scale)));
        imagecopyresampled($t, $img, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
        imagedestroy($img);
        ob_start();
        imagejpeg($t, null, 86);
        imagedestroy($t);
        return [(string)ob_get_clean(), 'image/jpeg'];
    }

    private static function fromAi(array $o): array
    {
        $num = fn($v) => is_numeric($v) ? round((float)$v, 2) : null;
        $date = function ($v) { $v = is_string($v) ? trim($v) : ''; return RcKit::ymd($v) ?: ($v !== '' ? RcParse::date($v) : null); };
        $vat = preg_replace('/\D/', '', (string)($o['supplier_vat_number'] ?? ''));
        $f = self::blank();
        $f['vendor_name'] = is_string($o['supplier_name'] ?? null) && trim($o['supplier_name']) !== '' ? mb_substr(trim($o['supplier_name']), 0, 190) : null;
        $f['vendor_vat'] = preg_match('/^4\d{9}$/', $vat) ? $vat : null;
        $f['invoice_number'] = is_string($o['document_number'] ?? null) && trim($o['document_number']) !== '' ? mb_substr(trim($o['document_number']), 0, 60) : null;
        $f['invoice_date'] = $date($o['document_date'] ?? null);
        $f['due_date'] = $date($o['due_date'] ?? null);
        if ($f['due_date'] && $f['invoice_date'] && $f['due_date'] < $f['invoice_date']) $f['due_date'] = null;
        $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($o['currency'] ?? '')));
        $f['currency'] = preg_match('/^[A-Z]{3}$/', $cur) ? $cur : 'ZAR';
        if (in_array($cur, ['R', 'RAND', 'RANDS'], true)) $f['currency'] = 'ZAR';
        $f['total'] = $num($o['total'] ?? null);
        $f['tax'] = $num($o['vat_amount'] ?? null);
        $f['subtotal'] = $num($o['subtotal_excl_vat'] ?? null);
        if ($f['total'] !== null && $f['tax'] !== null && $f['subtotal'] === null) $f['subtotal'] = round($f['total'] - $f['tax'], 2);
        if ($f['total'] === null && $f['subtotal'] !== null) $f['total'] = round($f['subtotal'] + ($f['tax'] ?? 0), 2);
        $lines = [];
        foreach (array_slice(is_array($o['lines'] ?? null) ? $o['lines'] : [], 0, 60) as $l) {
            $d = trim((string)($l['description'] ?? ''));
            if ($d === '') continue;
            $q = is_numeric($l['quantity'] ?? null) && (float)$l['quantity'] > 0 ? round((float)$l['quantity'], 3) : 1.0;
            $p = $num($l['unit_price'] ?? null);
            $a = $num($l['amount'] ?? null);
            if ($a === null && $p !== null) $a = round($p * $q, 2);
            if ($p === null && $a !== null) $p = round($a / $q, 2);
            if ($a === null) continue;
            $lines[] = ['description' => mb_substr($d, 0, 255), 'qty' => $q, 'unit_price' => $p, 'amount' => $a];
        }
        $sum = round(array_sum(array_column($lines, 'amount')), 2);
        $incl = $o['prices_include_vat'] ?? null;
        if ($lines && $f['total'] !== null && abs($sum - $f['total']) <= max(0.02, $f['total'] * 0.001)) $incl = true;
        elseif ($lines && $f['subtotal'] !== null && abs($sum - $f['subtotal']) <= max(0.02, $f['subtotal'] * 0.001)) $incl = false;
        elseif ($lines) {
            // Items that do not add up (a discount or deposit left out): one line for the total, named after them.
            $f['item_names'] = array_slice(array_column($lines, 'description'), 0, 4);
            $lines = [];
        }
        $f['lines'] = $lines;
        $f['prices_include_vat'] = is_bool($incl) ? $incl : null;
        $score = 0.4;
        foreach (['vendor_name' => 0.15, 'invoice_date' => 0.15, 'total' => 0.2, 'invoice_number' => 0.05] as $k => $w) if (!empty($f[$k])) $score += $w;
        $f['confidence'] = round(min(0.95, $score), 2);
        return $f;
    }

    // ------------------------------------------------------- text readers

    private static function pdfText(string $path): string
    {
        if (!self::canRun('pdftotext')) return '';
        $out = [];
        @exec('pdftotext -layout -l 3 -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null', $out, $rc);
        return $rc === 0 ? mb_substr(implode("\n", $out), 0, 60000) : '';
    }

    private static function ocr(string $path, string $mime): string
    {
        $img = $path;
        $tmp = null;
        if ($mime === 'application/pdf') {
            if (!self::canRun('pdftoppm')) return '';
            $tmp = tempnam(sys_get_temp_dir(), 'rcocr');
            @exec('pdftoppm -png -r 220 -f 1 -l 1 -singlefile ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
            $img = $tmp . '.png';
            if (!is_file($img)) { @unlink($tmp); return ''; }
        }
        $out = [];
        @exec('tesseract ' . escapeshellarg($img) . ' stdout --psm 4 2>/dev/null', $out, $rc);
        if ($tmp) { @unlink($tmp); @unlink($tmp . '.png'); }
        return $rc === 0 ? mb_substr(implode("\n", $out), 0, 60000) : '';
    }

    // ------------------------------------------------------------ helpers

    private static function usable(string $text): bool
    {
        return preg_match_all('/\p{L}/u', $text) >= 20;
    }

    private static function found(array $f): bool
    {
        return !empty($f['total']) || !empty($f['vendor_name']) || !empty($f['invoice_number']);
    }

    public static function blank(): array
    {
        return ['vendor_name' => null, 'vendor_vat' => null, 'invoice_number' => null, 'invoice_date' => null, 'due_date' => null, 'currency' => 'ZAR',
                'subtotal' => null, 'tax' => null, 'total' => null, 'prices_include_vat' => null, 'lines' => [], 'item_names' => [], 'confidence' => 0.0];
    }

    private static function fail(?string $engine, ?string $error): array
    {
        return ['ok' => false, 'engine' => $engine, 'error' => $error, 'fields' => self::blank(), 'text' => '', 'account_code' => null];
    }

    /** One reading per receipt (a new one replaces the old). */
    private static function store(PDO $db, int $fileId, array $r): void
    {
        $f = $r['fields'];
        $raw = ['engine' => $r['engine'], 'error' => $r['error'], 'fields' => $f, 'account_code' => $r['account_code'] ?? null, 'text' => mb_substr((string)($r['text'] ?? ''), 0, 20000)];
        $db->prepare("DELETE FROM receipt_ocr WHERE file_id = ?")->execute([$fileId]);
        $db->prepare("INSERT INTO receipt_ocr (file_id, raw_json, vendor_name, vendor_vat, invoice_number, invoice_date, currency, subtotal, tax, total, line_items_json, confidence_score, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$fileId, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $f['vendor_name'] !== null ? mb_substr($f['vendor_name'], 0, 255) : null,
                      $f['vendor_vat'], $f['invoice_number'] !== null ? mb_substr($f['invoice_number'], 0, 190) : null, $f['invoice_date'], substr((string)$f['currency'], 0, 3) ?: 'ZAR',
                      $f['subtotal'], $f['tax'], $f['total'], json_encode($f['lines'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $f['confidence'] ?? 0]);
        $db->prepare("UPDATE receipt_file SET ocr_status = ?, read_engine = ?, read_error = ?, read_at = NOW() WHERE file_id = ?")
           ->execute([$r['ok'] ? 'parsed' : 'failed', $r['engine'], $r['error'] !== null ? mb_substr($r['error'], 0, 255) : null, $fileId]);
    }
}
