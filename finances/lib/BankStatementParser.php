<?php
/**
 * BankStatementParser — Extracts transactions from South African bank statement PDFs.
 *
 * Uses pdftotext (poppler-utils) for text extraction, then applies regex-based
 * pattern matching for common SA bank formats (FNB, ABSA, Nedbank, Standard Bank,
 * Capitec, Investec, TymeBank).
 *
 * Each bank uses different column layouts and date formats, so the parser detects
 * the bank from header text and applies the appropriate extraction strategy.
 *
 * All amounts are returned in cents (integer) for consistency with the GL system.
 */

class BankStatementParser
{
    private string $rawText = '';
    private string $detectedBank = 'unknown';
    private array $transactions = [];
    private array $warnings = [];

    /**
     * Parse a PDF bank statement file and extract transactions.
     *
     * @param string $filePath Path to PDF file
     * @return array{ok: bool, bank: string, transactions: array, warnings: array, raw_text: string}
     */
    public function parsePDF(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['ok' => false, 'error' => 'File not found', 'transactions' => [], 'warnings' => []];
        }

        // Extract text from PDF using pdftotext (layout mode preserves columns)
        $this->rawText = $this->extractText($filePath);

        if (empty(trim($this->rawText))) {
            return [
                'ok' => false,
                'error' => 'Could not extract text from PDF. The file may be scanned/image-based. Please use CSV export from your bank instead.',
                'transactions' => [],
                'warnings' => []
            ];
        }

        // Detect which bank the statement is from
        $this->detectedBank = $this->detectBank($this->rawText);

        // Parse transactions based on detected bank
        $this->transactions = $this->extractTransactions($this->rawText, $this->detectedBank);

        if (empty($this->transactions)) {
            $this->warnings[] = 'No transactions could be extracted. The PDF format may not be supported. Try generic line-by-line parsing.';
            // Attempt generic fallback
            $this->transactions = $this->extractGeneric($this->rawText);
        }

        return [
            'ok' => count($this->transactions) > 0,
            'bank' => $this->detectedBank,
            'transactions' => $this->transactions,
            'count' => count($this->transactions),
            'warnings' => $this->warnings,
            'raw_text' => $this->rawText
        ];
    }

    /**
     * Extract text from PDF using pdftotext with layout preservation.
     */
    private function extractText(string $filePath): string
    {
        $pdftotext = '/usr/bin/pdftotext';
        if (!file_exists($pdftotext)) {
            // Try system path
            $pdftotext = 'pdftotext';
        }

        // -layout preserves the original page layout (important for column alignment)
        $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($filePath) . ' -';
        $output = shell_exec($cmd . ' 2>/dev/null');

        return $output ?? '';
    }

    /**
     * Detect which South African bank the statement belongs to.
     */
    private function detectBank(string $text): string
    {
        $textLower = strtolower($text);

        if (strpos($textLower, 'first national bank') !== false || strpos($textLower, 'fnb') !== false) {
            return 'fnb';
        }
        if (strpos($textLower, 'absa') !== false) {
            return 'absa';
        }
        if (strpos($textLower, 'nedbank') !== false) {
            return 'nedbank';
        }
        if (strpos($textLower, 'standard bank') !== false) {
            return 'standard_bank';
        }
        if (strpos($textLower, 'capitec') !== false) {
            return 'capitec';
        }
        if (strpos($textLower, 'investec') !== false) {
            return 'investec';
        }
        if (strpos($textLower, 'tymebank') !== false || strpos($textLower, 'tyme bank') !== false) {
            return 'tymebank';
        }
        if (strpos($textLower, 'discovery bank') !== false) {
            return 'discovery';
        }

        return 'generic';
    }

    /**
     * Route to bank-specific parser or generic fallback.
     */
    private function extractTransactions(string $text, string $bank): array
    {
        switch ($bank) {
            case 'fnb':
                return $this->parseFNB($text);
            case 'absa':
                return $this->parseABSA($text);
            case 'nedbank':
                return $this->parseNedbank($text);
            case 'standard_bank':
                return $this->parseStandardBank($text);
            case 'capitec':
                return $this->parseCapitec($text);
            default:
                return $this->extractGeneric($text);
        }
    }

    // ── FNB ──────────────────────────────────────────────────────────
    // FNB statements typically have: Date | Description | Amount | Balance
    // Date format: DD/MM/YYYY or DD MMM YYYY
    // Debits are negative, credits are positive
    private function parseFNB(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip summary/header lines
            if (preg_match('/^(opening|closing|total)\s+balance/i', $line)) continue;
            if (preg_match('/balance\s+(brought|carried)\s+forward/i', $line)) continue;

            // FNB pattern: DD/MM/YYYY or DD Mon YYYY followed by description and amounts
            // e.g. "05/03/2026   SHOPRITE SALES      -1,250.00    15,432.10"
            // e.g. "05 Mar 2026  SALARY DEPOSIT       25,000.00   40,432.10"
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})?/', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }
            // DD Mon YYYY format
            if (preg_match('/^(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4})\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})?/i', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }
        }

        return $transactions;
    }

    // ── ABSA ─────────────────────────────────────────────────────────
    // ABSA statements: Date | Description | Debit | Credit | Balance
    // Date format: DD Mon YYYY, DD/MM/YYYY or YYYY/MM/DD
    // IMPORTANT: Transaction detail often appears on the NEXT line below the main line
    // e.g. "05 Mar 2026  POS PURCHASE SHOPRITE       1,250.00                    15,432.10"
    //       "             CARD NO 1234*5678"
    // Debit/Credit are separate columns: the amount's POSITION decides its sign,
    // not the order in which it appears on the line.
    private function parseABSA(string $text): array
    {
        $datePattern = '(?:\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4}'
            . '|\d{2}[\/\-]\d{2}[\/\-]\d{4}'
            . '|\d{4}[\/\-]\d{2}[\/\-]\d{2})';
        return $this->parseTwoColumn($text, $datePattern, '/\bdebits?\b/i', '/\bcredits?\b/i', true);
    }

    // ── Nedbank ──────────────────────────────────────────────────────
    // Nedbank: Date | Transaction | Amount | Balance
    // Date format: DD/MM/YYYY
    private function parseNedbank(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Nedbank pattern
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s{2,}(-?[\d,\s]+\.\d{2})\s+(-?[\d,\s]+\.\d{2})?/', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), str_replace(' ', '', $m[3]));
                continue;
            }

            // DD Mon YYYY
            if (preg_match('/^(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4})\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})?/i', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }
        }

        return $transactions;
    }

    // ── Standard Bank ────────────────────────────────────────────────
    // Standard Bank: Date | Description | Amount | Balance
    // Often uses DD/MM/YY or DD Mon format
    // IMPORTANT: Filter "Balance brought forward" / "Balance carried forward" lines
    private function parseStandardBank(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip balance brought/carried forward lines (appear on every page)
            if (preg_match('/balance\s+(brought|carried)\s+forward/i', $line)) {
                continue;
            }
            // Skip summary/header lines
            if (preg_match('/^(opening|closing)\s+balance/i', $line)) {
                continue;
            }

            // DD/MM/YY format (Standard Bank often uses 2-digit year)
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{2,4})\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s*(-?[\d,]+\.\d{2})?/', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }

            // DD Mon format
            if (preg_match('/^(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)(?:\s+\d{2,4})?)\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s*(-?[\d,]+\.\d{2})?/i', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }
        }

        return $transactions;
    }

    // ── Capitec ──────────────────────────────────────────────────────
    // Capitec: Date | Transaction | Money Out | Money In | Balance
    // Date format: YYYY-MM-DD or DD/MM/YYYY
    // IMPORTANT: Capitec uses space as thousands separator (e.g. "1 250.00")
    // and has separate Money Out / Money In columns: the amount's POSITION
    // decides its sign, not the order in which it appears on the line.
    private function parseCapitec(string $text): array
    {
        $datePattern = '(?:\d{4}-\d{2}-\d{2}|\d{2}[\/\-]\d{2}[\/\-]\d{4})';
        return $this->parseTwoColumn($text, $datePattern, '/money\s*out|\bdebits?\b/i', '/money\s*in|\bcredits?\b/i', false);
    }

    // ── Two-column statement engine (ABSA / Capitec) ────────────────
    // Parses statements that print separate money-out / money-in columns.
    // An amount's column POSITION (not its sequence on the line) decides
    // whether it is a debit or a credit. Classification order per amount:
    //   1. explicit minus sign  → money out
    //   2. header column anchors (Debit/Credit, Money Out/Money In)
    //   3. two unsigned amounts → first column is out, second is in
    //   4. running-balance delta (|balance - prev_balance| == amount)
    //   5. document uses signed amounts elsewhere → unsigned = money in
    //   6. legacy assumption (money out) + warning
    private function parseTwoColumn(string $text, string $datePattern, string $outHeaderPattern, string $inHeaderPattern, bool $joinContinuations): array
    {
        $transactions = [];
        $lines = explode("\n", $text);
        $lineCount = count($lines);

        // Column anchors from the header row (character offset of each label's end;
        // pdftotext -layout right-aligns numeric columns, so compare right edges)
        [$outAnchor, $inAnchor] = $this->findColumnAnchors($lines, $outHeaderPattern, $inHeaderPattern);

        $summaryPattern = '/^(opening|closing|total)\s+balance|balance\s+(brought|carried)\s+forward/i';

        // ── Pass 1: collect candidate rows with token positions and running balance
        $rows = [];
        $prevBalance = null;
        $hasNegative = false;
        for ($i = 0; $i < $lineCount; $i++) {
            $rawLine = rtrim($lines[$i]); // keep leading spaces — offsets must match the header line
            $line = trim($rawLine);
            if ($line === '') continue;

            // Summary lines seed the running balance, then are skipped
            if (preg_match($summaryPattern, $line)) {
                $tokens = $this->amountTokens($rawLine);
                if (!empty($tokens)) {
                    $prevBalance = end($tokens)['value'];
                }
                continue;
            }

            if (!preg_match('/^\s*(' . $datePattern . ')(?=\s)/i', $rawLine, $dm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $rawDate = $dm[1][0];
            $dateEnd = $dm[1][1] + strlen($dm[1][0]);

            $tokens = $this->amountTokens($rawLine);
            if (empty($tokens) || $tokens[0]['start'] <= $dateEnd) continue;

            $desc = trim(substr($rawLine, $dateEnd, $tokens[0]['start'] - $dateEnd));

            // Dated summary lines ("01 Mar 2026  Opening balance   10 000.00")
            if (preg_match($summaryPattern, $desc)) {
                $prevBalance = end($tokens)['value'];
                continue;
            }

            // ABSA: transaction detail often continues on the next line (indented, no date, no amount)
            if ($joinContinuations && $i + 1 < $lineCount) {
                $nextLine = trim($lines[$i + 1]);
                if ($nextLine !== '' && !preg_match('/^\d/', $nextLine) && !preg_match('/[\d,]+\.\d{2}\s*$/', $nextLine)) {
                    $desc .= ' ' . $nextLine;
                    $i++; // Skip the detail line
                }
            }

            // With two or more amounts, the last one is the running balance column
            $balance = null;
            $amountTokens = $tokens;
            if (count($tokens) >= 2) {
                $balance = end($tokens)['value'];
                $amountTokens = array_slice($tokens, 0, -1);
            }

            foreach ($amountTokens as $tok) {
                if ($tok['negative']) $hasNegative = true;
            }

            $rows[] = [
                'date'         => $rawDate,
                'desc'         => $desc,
                'tokens'       => $amountTokens,
                'balance'      => $balance,
                'prev_balance' => $prevBalance,
            ];
            if ($balance !== null) {
                $prevBalance = $balance;
            }
        }

        // ── Pass 2: classify each amount as money out / money in
        foreach ($rows as $row) {
            $tokenCount = count($row['tokens']);
            foreach ($row['tokens'] as $idx => $tok) {
                $value = abs($tok['value']);
                if ($value < 0.005) continue;

                $isOut = null;
                if ($tok['negative']) {
                    $isOut = true;
                } elseif ($outAnchor !== null && $inAnchor !== null) {
                    // Right edge of the amount closest to the right edge of a header label
                    $isOut = abs($tok['end'] - $outAnchor) <= abs($tok['end'] - $inAnchor);
                } elseif ($tokenCount === 2) {
                    // Two unsigned amount columns: out column precedes in column
                    $isOut = ($idx === 0);
                } elseif ($row['balance'] !== null && $row['prev_balance'] !== null) {
                    $delta = $row['balance'] - $row['prev_balance'];
                    if (abs(abs($delta) - $value) <= 0.011) {
                        $isOut = $delta < 0;
                    }
                }
                if ($isOut === null) {
                    if ($hasNegative) {
                        // Statement uses explicit signs; an unsigned amount is a credit
                        $isOut = false;
                    } else {
                        $isOut = true;
                        $this->warnings[] = "Could not determine debit/credit column for '"
                            . $row['date'] . ' ' . $row['desc'] . "' — assumed money out. Please verify.";
                    }
                }

                $transactions[] = $this->buildTransaction(
                    $row['date'],
                    $row['desc'],
                    ($isOut ? '-' : '') . number_format($value, 2, '.', '')
                );
            }
        }

        return $transactions;
    }

    /**
     * Locate the money-out / money-in header column anchors. Returns the
     * character offsets of the END of each header label on the first line
     * containing both, or [null, null] if no such header line exists.
     */
    private function findColumnAnchors(array $lines, string $outPattern, string $inPattern): array
    {
        foreach ($lines as $line) {
            if (preg_match($outPattern, $line, $mo, PREG_OFFSET_CAPTURE)
                && preg_match($inPattern, $line, $mi, PREG_OFFSET_CAPTURE)
                && $mo[0][1] !== $mi[0][1]) {
                return [$mo[0][1] + strlen($mo[0][0]), $mi[0][1] + strlen($mi[0][0])];
            }
        }
        return [null, null];
    }

    /**
     * Extract monetary tokens (with character offsets) from an untrimmed line.
     * Supports comma and space thousands separators ("1,250.00", "1 250.00").
     */
    private function amountTokens(string $rawLine): array
    {
        $tokens = [];
        if (!preg_match_all('/(?<![\d.,])(-?)(\d+(?:[ ,]\d{3})*\.\d{2})(?!\d)/', $rawLine, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $tokens;
        }
        foreach ($m as $match) {
            $neg = ($match[1][0] === '-');
            $num = (float)str_replace([' ', ','], '', $match[2][0]);
            $tokens[] = [
                'value'    => $neg ? -$num : $num,
                'negative' => $neg,
                'start'    => $match[0][1],
                'end'      => $match[0][1] + strlen($match[0][0]),
            ];
        }
        return $tokens;
    }

    // ── Generic Fallback ─────────────────────────────────────────────
    // Tries to extract any line that looks like a transaction
    private function extractGeneric(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 15) continue;

            // Skip common non-transaction lines
            if (preg_match('/balance\s+(brought|carried)\s+forward/i', $line)) continue;
            if (preg_match('/^(opening|closing|total)\s+balance/i', $line)) continue;
            if (preg_match('/^(date|transaction|description|debit|credit|amount)\s/i', $line)) continue;
            if (preg_match('/^page\s+\d/i', $line)) continue;

            // Pattern 1: Date at start, amount somewhere in line
            // Matches: DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, DD Mon YYYY
            $datePattern = '(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}[\/\-]\d{2}[\/\-]\d{2}|\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{2,4})';

            if (preg_match('/^' . $datePattern . '\s+(.+?)\s{2,}(-?[\d,\s]+\.\d{2})/i', $line, $m)) {
                $amount = str_replace([' ', ','], ['', ''], $m[3]);
                if (is_numeric($amount) && abs((float)$amount) > 0) {
                    $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $amount);
                }
            }
        }

        return $transactions;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Build a normalized transaction array from raw parsed values.
     */
    private function buildTransaction(string $rawDate, string $description, string $rawAmount): array
    {
        // Normalize date to YYYY-MM-DD
        $date = $this->normalizeDate($rawDate);

        // Clean amount: remove commas and spaces, keep sign
        $amountStr = str_replace([',', ' '], '', trim($rawAmount));
        $amount = (float)$amountStr;
        $amountCents = (int)round($amount * 100);

        // Clean description: collapse whitespace
        $description = preg_replace('/\s+/', ' ', trim($description));

        return [
            'tx_date' => $date,
            'description' => $description,
            'amount_cents' => $amountCents,
            'amount_display' => number_format(abs($amount), 2),
            'is_debit' => $amountCents < 0,
            'reference' => null
        ];
    }

    /**
     * Normalize various date formats to YYYY-MM-DD.
     */
    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);

        // Strict per-format parsing with a year-range guard. Without it,
        // DateTime::createFromFormat('d/m/Y', '01/02/26') reads the 2-digit '26'
        // as year 0026 (PHP's Y token is greedy) and the 2-digit-year branches
        // below were never reached — Standard Bank statements (2-digit years)
        // were mis-dated to year 26, so every row sorted out of view and was
        // blocked by period locks. Require a 4-digit year (>= 1000) for the
        // Y-token formats; the y-token formats accept the century-pivoted 2-digit
        // year (>= 100). A parse warning/error (e.g. day/month overflow) rejects
        // the candidate. Formats are tried in d/m-first (SA) order.
        $formats = [
            'Y-m-d' => 1000, 'd/m/Y' => 1000, 'd-m-Y' => 1000, 'Y/m/d' => 1000,
            'd M Y' => 1000, 'j M Y' => 1000,
            'd/m/y' => 100, 'd-m-y' => 100, 'd M y' => 100, 'j M y' => 100,
        ];
        foreach ($formats as $fmt => $minYear) {
            $d = DateTime::createFromFormat('!' . $fmt, $raw);
            $errs = DateTime::getLastErrors();
            $clean = ($errs === false) || ($errs['warning_count'] === 0 && $errs['error_count'] === 0);
            if ($d && $clean && (int)$d->format('Y') >= $minYear) {
                return $d->format('Y-m-d');
            }
        }

        // Last resort for exotic formats — but never accept a year < 100.
        $ts = strtotime($raw);
        if ($ts !== false && $ts > 0 && (int)date('Y', $ts) >= 100) {
            return date('Y-m-d', $ts);
        }

        $this->warnings[] = "Could not parse date: '{$raw}'";
        return null;
    }
}
