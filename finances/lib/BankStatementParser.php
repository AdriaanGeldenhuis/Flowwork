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
    // Date format: DD Mon YYYY or YYYY/MM/DD
    // IMPORTANT: Transaction detail often appears on the NEXT line below the main line
    // e.g. "05 Mar 2026  POS PURCHASE SHOPRITE       1,250.00                    15,432.10"
    //       "             CARD NO 1234*5678"
    private function parseABSA(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            // Skip summary lines
            if (preg_match('/^(opening|closing|total)\s+balance/i', $line)) continue;

            // ABSA pattern with separate debit/credit columns
            if (preg_match('/^(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4})\s+(.+?)\s{2,}([\d,]+\.\d{2})?\s+([\d,]+\.\d{2})?\s+([\d,]+\.\d{2})?/i', $line, $m)) {
                $debit = $m[3] ?? '';
                $credit = $m[4] ?? '';
                $desc = trim($m[2]);

                // Check if next line is a continuation (indented detail, no date)
                if ($i + 1 < $lineCount) {
                    $nextLine = trim($lines[$i + 1]);
                    if (!empty($nextLine) && !preg_match('/^\d/', $nextLine) && !preg_match('/[\d,]+\.\d{2}\s*$/', $nextLine)) {
                        $desc .= ' ' . $nextLine;
                        $i++; // Skip the detail line
                    }
                }

                if ($debit && $debit !== '0.00') {
                    $transactions[] = $this->buildTransaction($m[1], $desc, '-' . $debit);
                } elseif ($credit && $credit !== '0.00') {
                    $transactions[] = $this->buildTransaction($m[1], $desc, $credit);
                }
                continue;
            }

            // ABSA DD/MM/YYYY format
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4})\s+(.+?)\s{2,}([\d,]+\.\d{2})?\s+([\d,]+\.\d{2})?\s+([\d,]+\.\d{2})?/', $line, $m)) {
                $debit = $m[3] ?? '';
                $credit = $m[4] ?? '';
                $desc = trim($m[2]);

                if ($debit && $debit !== '0.00') {
                    $transactions[] = $this->buildTransaction($m[1], $desc, '-' . $debit);
                } elseif ($credit && $credit !== '0.00') {
                    $transactions[] = $this->buildTransaction($m[1], $desc, $credit);
                }
                continue;
            }

            // ABSA YYYY/MM/DD format with single signed amount
            if (preg_match('/^(\d{4}[\/\-]\d{2}[\/\-]\d{2})\s+(.+?)\s{2,}(-?[\d,]+\.\d{2})\s+(-?[\d,]+\.\d{2})?/', $line, $m)) {
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $m[3]);
                continue;
            }
        }

        return $transactions;
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
    // and has separate Money Out / Money In columns
    private function parseCapitec(string $text): array
    {
        $transactions = [];
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip summary lines
            if (preg_match('/^(opening|closing|total)\s+balance/i', $line)) continue;

            // Capitec with separate debit/credit columns (amounts may have spaces)
            // Pattern: Date  Description  MoneyOut  MoneyIn  Balance
            // Amounts like "1 250.00" or "25 000.00"
            $datePattern = '(\d{4}-\d{2}-\d{2}|\d{2}[\/\-]\d{2}[\/\-]\d{4})';

            if (preg_match('/^' . $datePattern . '\s+(.+?)\s{2,}([\d\s,]+\.\d{2})?\s+([\d\s,]+\.\d{2})?\s+([\d\s,]+\.\d{2})?/', $line, $m)) {
                $debit = str_replace([' ', ','], '', trim($m[3] ?? ''));
                $credit = str_replace([' ', ','], '', trim($m[4] ?? ''));
                $desc = trim($m[2]);

                if ($debit && (float)$debit > 0) {
                    $transactions[] = $this->buildTransaction($m[1], $desc, '-' . $debit);
                } elseif ($credit && (float)$credit > 0) {
                    $transactions[] = $this->buildTransaction($m[1], $desc, $credit);
                }
                continue;
            }

            // Fallback: single signed amount column (amounts may have spaces)
            if (preg_match('/^' . $datePattern . '\s+(.+?)\s{2,}(-?[\d\s,]+\.\d{2})/', $line, $m)) {
                $amount = str_replace([' ', ','], '', $m[3]);
                $transactions[] = $this->buildTransaction($m[1], trim($m[2]), $amount);
                continue;
            }
        }

        return $transactions;
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

        // YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
            return $raw;
        }

        // DD/MM/YYYY or DD-MM-YYYY
        $d = DateTime::createFromFormat('d/m/Y', $raw);
        if ($d) return $d->format('Y-m-d');

        $d = DateTime::createFromFormat('d-m-Y', $raw);
        if ($d) return $d->format('Y-m-d');

        // YYYY/MM/DD
        $d = DateTime::createFromFormat('Y/m/d', $raw);
        if ($d) return $d->format('Y-m-d');

        // DD/MM/YY (2-digit year)
        $d = DateTime::createFromFormat('d/m/y', $raw);
        if ($d) return $d->format('Y-m-d');

        $d = DateTime::createFromFormat('d-m-y', $raw);
        if ($d) return $d->format('Y-m-d');

        // DD Mon YYYY
        $d = DateTime::createFromFormat('d M Y', $raw);
        if ($d) return $d->format('Y-m-d');

        $d = DateTime::createFromFormat('j M Y', $raw);
        if ($d) return $d->format('Y-m-d');

        // DD Mon YY
        $d = DateTime::createFromFormat('d M y', $raw);
        if ($d) return $d->format('Y-m-d');

        $d = DateTime::createFromFormat('j M y', $raw);
        if ($d) return $d->format('Y-m-d');

        // Try PHP's strtotime as last resort
        $ts = strtotime($raw);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d', $ts);
        }

        $this->warnings[] = "Could not parse date: '{$raw}'";
        return null;
    }
}
