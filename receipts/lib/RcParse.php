<?php
// /receipts/lib/RcParse.php — what a till slip or invoice says, from its text
// (text recognition or a PDF's text layer). No database; unit tested in
// tests/receipts/test_receipts_parse.php.
//
// Only what is printed is taken: nothing is made up. South African habits are
// understood: day-first dates, "R 1 234,50" and "1,234.50" amounts, VAT
// numbers of 10 digits starting with 4, "VAT 15%", "Amount due", "Incl. VAT".
// Line items are only kept when they add up to the slip's total (or the total
// before VAT) — otherwise the bill starts as one line for the whole amount.

final class RcParse
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
                            'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'des' => 12];
    // A money amount: "1 234.50", "1,234.50", "1234,50", "R1234.5" is not money (needs two decimals)
    private const MONEY = '(?<![\d.,])-?(?:\d{1,3}(?:[ ,\x{00A0}]\d{3})+|\d+)[.,]\d{2}(?![\d])';
    private const SKIP_LINE = '/\b(sub\s*-?\s*total|total|vat|tax|btw|change|cash|card|tender(?:ed)?|balance|rounding|tip|gratuity|discount\s+total|amount\s+due|paid|payment|visa|master\s*card|debit|credit|loyalty|points|savings|you\s+saved|auth|approval|ref(?:erence)?\s*no)\b|^amount\b/i';

    /**
     * @return array{vendor_name:?string, vendor_vat:?string, invoice_number:?string, invoice_date:?string, due_date:?string,
     *               currency:string, subtotal:?float, tax:?float, total:?float, prices_include_vat:?bool, lines:array, confidence:float}
     */
    public static function fields(string $text, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $text = str_replace(["\r\n", "\r", "\t", "\u{00A0}"], ["\n", "\n", ' ', ' '], $text);
        $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/ {2,}/', '  ', $l)), explode("\n", $text)), fn($l) => $l !== ''));
        $out = [
            'vendor_name' => self::vendor($lines), 'vendor_vat' => self::vatNumber($text), 'invoice_number' => self::number($lines),
            'invoice_date' => self::dateNear($lines, '/\b(date|datum|issued)\b/i', '/\b(due|pay\s+by|expir|valid\s+until|delivery)\b/i', $today, true),
            'due_date' => self::dateNear($lines, '/\b(due\s+date|payment\s+due|pay\s+by|due\s+by|due)\b/i', null, $today, false),
            'currency' => self::currency($text), 'subtotal' => null, 'tax' => null, 'total' => null, 'prices_include_vat' => null, 'lines' => [],
        ];
        if ($out['due_date'] && $out['invoice_date'] && $out['due_date'] < $out['invoice_date']) $out['due_date'] = null;

        $out['total'] = self::amountOn($lines, [
            '/\b(amount|balance|total)\s+(due|payable|owing)\b/i', '/\bgrand\s+total\b/i', '/\btotal\s*\(?\s*incl/i', '/\b(to\s+pay|amount\s+payable)\b/i',
            '/^(?!.*\b(sub|excl|vat|tax|nett?|before)\b).*\btotal\b/i',
        ], 'max');
        $out['subtotal'] = self::amountOn($lines, ['/\bsub\s*-?\s*total\b/i', '/\b(total\s*\(?\s*excl|excl\.?\s*vat|amount\s+excl|nett?\s+(amount|total))\b/i'], 'last');
        $out['tax'] = self::amountOn($lines, ['/^(?!.*\b(incl|excl|inclusive|exclusive)\b).*\b(vat|tax|btw)\b(?!\s*(reg|no\b|nr|number|#|registration))/i'], 'last', $out['total']);

        // Make the three agree where only two were found; never invent VAT.
        $t = $out['total']; $s = $out['subtotal']; $v = $out['tax'];
        if ($t !== null && $v !== null && $v >= $t) $v = $out['tax'] = null;
        if ($t !== null && $v !== null && $s === null) $out['subtotal'] = round($t - $v, 2);
        elseif ($t !== null && $s !== null && $v === null && $s <= $t) $out['tax'] = round($t - $s, 2);
        elseif ($t === null && $s !== null && $v !== null) $out['total'] = round($s + $v, 2);
        elseif ($t !== null && $s === null && $v === null && preg_match('/\bincl(usive|\.)?\s*(of\s*)?vat\b|\bvat\s*incl/i', $text)) {
            $out['tax'] = round($t * 15 / 115, 2);
            $out['subtotal'] = round($t - $out['tax'], 2);
        }
        if ($out['subtotal'] !== null && $out['tax'] !== null && $out['total'] !== null && abs($out['subtotal'] + $out['tax'] - $out['total']) > 0.05) {
            $out['subtotal'] = round($out['total'] - $out['tax'], 2);
        }

        [$items, $incl] = self::items($lines, $out['total'], $out['subtotal']);
        $out['lines'] = $items;
        $out['prices_include_vat'] = $items ? $incl : (($out['tax'] ?? 0) > 0 ? true : null);

        $score = 0.0;
        foreach (['vendor_name' => 0.15, 'invoice_date' => 0.2, 'total' => 0.3, 'invoice_number' => 0.15, 'vendor_vat' => 0.1] as $k => $w) {
            if (!empty($out[$k])) $score += $w;
        }
        if ($out['tax'] !== null) $score += 0.05;
        $out['confidence'] = round(min(0.85, $score), 2);
        return $out;
    }

    /** Money text to a float, or null. */
    public static function money(string $s): ?float
    {
        $s = trim(str_replace(['R', 'r', ' ', "\u{00A0}"], '', $s));
        if (!preg_match('/^-?[\d,.]+$/', $s)) return null;
        if (preg_match('/^-?\d{1,3}(,\d{3})+\.\d{2}$/', $s)) $s = str_replace(',', '', $s);
        elseif (preg_match('/^-?\d{1,3}(\.\d{3})+,\d{2}$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
        elseif (preg_match('/^-?\d+,\d{2}$/', $s)) $s = str_replace(',', '.', $s);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
        return round((float)$s, 2);
    }

    /** A date as printed ("12/10/2026", "2026-10-12", "12 Oct 2026", "Oct 12, 2026", "12-Oct-26") → Y-m-d. Day first. */
    public static function date(string $s, ?string $today = null): ?string
    {
        $today = $today ?: date('Y-m-d');
        $s = strtolower(trim($s));
        $y = $m = $d = 0;
        if (preg_match('/\b(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $s, $x)) {
            [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]];
        } elseif (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\b/', $s, $x)) {
            [$d, $m, $y] = [(int)$x[1], (int)$x[2], (int)$x[3]];
            if ($m > 12 && $d <= 12) [$d, $m] = [$m, $d]; // 10/25/2026 — only when it cannot be day-first
        } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.]+([a-z]{3,9})\.?[\s\-\/.,]+(\d{2,4})\b/', $s, $x) && ($mn = self::month($x[2]))) {
            [$d, $m, $y] = [(int)$x[1], $mn, (int)$x[3]];
        } elseif (preg_match('/\b([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/', $s, $x) && ($mn = self::month($x[1]))) {
            [$m, $d, $y] = [$mn, (int)$x[2], (int)$x[3]];
        }
        if (!$y) return null;
        if ($y < 100) $y += 2000;
        if (!checkdate($m, $d, $y)) return null;
        $out = sprintf('%04d-%02d-%02d', $y, $m, $d);
        // A receipt is not from the far past or the future.
        if ($out < date('Y-m-d', strtotime($today . ' -6 years')) || $out > date('Y-m-d', strtotime($today . ' +400 days'))) return null;
        return $out;
    }

    // -------------------------------------------------------------- pieces

    private static function month(string $w): int
    {
        $w = strtolower($w);
        return self::MONTHS[substr($w, 0, 4)] ?? self::MONTHS[substr($w, 0, 3)] ?? 0;
    }

    private static function vendor(array $lines): ?string
    {
        foreach (array_slice($lines, 0, 8) as $l) {
            $c = trim(preg_replace('/[*=#_~|]+/', ' ', $l));
            if (mb_strlen($c) < 3 || mb_strlen($c) > 60) continue;
            if (preg_match('/\b(tax\s+invoice|invoice|receipt|slip|date|tel|fax|phone|cell|vat|reg|www|http|email|total|welcome|thank|copy|page|till|cashier|order|quote|statement)\b|@|\d{4,}/i', $c)) continue;
            if (preg_match_all('/\p{L}/u', $c) < 3) continue;
            $c = preg_replace('/\s{2,}/', ' ', $c);
            if (mb_strtoupper($c) === $c) $c = mb_convert_case(mb_strtolower($c), MB_CASE_TITLE);
            return trim($c);
        }
        return null;
    }

    private static function vatNumber(string $text): ?string
    {
        if (preg_match('/v\.?\s*a\.?\s*t\.?\s*(?:reg(?:istration|istered)?\.?\s*)?(?:no|nr|number|num|#)?\.?\s*[:#.]?\s*(4(?:\s?\d){9})\b/i', $text, $m)) return preg_replace('/\s/', '', $m[1]);
        if (preg_match('/(?<!\d)(4\d{9})(?!\d)/', $text, $m)) return $m[1];
        return null;
    }

    private static function number(array $lines): ?string
    {
        $re = '/\b(?:tax\s+invoice|invoice|inv|receipt|rcpt|slip|doc(?:ument)?|bill|trans(?:action)?)\s*(?:no|nr|number|num|#)?\s*[.:#]*\s*([A-Z0-9][A-Z0-9\-\/]{1,30})/i';
        foreach ($lines as $l) {
            if (preg_match('/\b(date|datum)\s*[:#]/i', $l) && !preg_match('/\b(no|nr|number|#)\b/i', $l)) continue;
            if (preg_match_all($re, $l, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $v = rtrim($m[1], '-/');
                    if (preg_match('/\d/', $v) && !self::date($v) && !preg_match('/^4\d{9}$/', $v)) return mb_substr($v, 0, 60);
                }
            }
        }
        return null;
    }

    private static function dateNear(array $lines, string $label, ?string $not, string $today, bool $fallback): ?string
    {
        foreach ($lines as $i => $l) {
            if (!preg_match($label, $l) || ($not && preg_match($not, $l))) continue;
            if ($d = self::date($l, $today)) return $d;
            if (isset($lines[$i + 1]) && ($d = self::date($lines[$i + 1], $today))) return $d;
        }
        if (!$fallback) return null;
        foreach ($lines as $l) {
            if (preg_match('/\b(due|pay\s+by|expir|valid\s+until)\b/i', $l)) continue;
            if ($d = self::date($l, $today)) return $d;
        }
        return null;
    }

    private static function currency(string $text): string
    {
        if (preg_match('/\b(USD|US\$|EUR|GBP|€|£)/u', $text, $m) && !preg_match('/\bR\s?\d|\bZAR\b|\brand\b/i', $text)) {
            return ['US$' => 'USD', '€' => 'EUR', '£' => 'GBP'][$m[1]] ?? strtoupper($m[1]);
        }
        return 'ZAR';
    }

    /** The amount on the first label that has one (by priority); 'max' takes the largest of that label's lines, 'last' the last one. */
    private static function amountOn(array $lines, array $labels, string $pick, ?float $below = null): ?float
    {
        foreach ($labels as $label) {
            $found = [];
            foreach ($lines as $l) {
                if (!preg_match($label, $l)) continue;
                if (preg_match_all('/' . self::MONEY . '/u', $l, $m)) {
                    $vals = array_values(array_filter(array_map([self::class, 'money'], $m[0]), fn($v) => $v !== null && $v >= 0));
                    if (!$vals) continue;
                    // "VAT 15%  130.43": the last amount on the line is the value
                    $v = end($vals);
                    if ($below !== null && $v >= $below) continue;
                    $found[] = $v;
                }
            }
            if ($found) return $pick === 'max' ? max($found) : end($found);
        }
        return null;
    }

    /** Item lines that add up to the total (incl. VAT) or the subtotal (excl.). @return array{0: array, 1: ?bool} */
    private static function items(array $lines, ?float $total, ?float $subtotal): array
    {
        if ($total === null && $subtotal === null) return [[], null];
        $items = [];
        foreach ($lines as $l) {
            if (preg_match(self::SKIP_LINE, $l)) continue;
            if (!preg_match('/^(.*?\p{L}.*?)\s+(' . self::MONEY . ')\s*[A-Z*#]{0,2}$/u', $l, $m)) continue;
            $desc = trim($m[1]);
            $amount = self::money($m[2]);
            if ($amount === null || $amount <= 0) continue;
            $qty = 1.0;
            $price = $amount;
            // "2 x 45.00  90.00", "2 @ 45.00 90.00", "Cement 50kg  3  89.90  269.70"
            if (preg_match('/^(.*?\p{L}.*?)\s+(\d+(?:[.,]\d+)?)\s*(?:x|@|\*)?\s*(' . self::MONEY . ')$/iu', $desc, $q)) {
                $q2 = (float)str_replace(',', '.', $q[2]);
                $p2 = self::money($q[3]);
                if ($q2 > 0 && $q2 < 10000 && $p2 !== null && abs($q2 * $p2 - $amount) < 0.02) { $desc = trim($q[1]); $qty = $q2; $price = $p2; }
            } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:x|@|\*)\s*(.+)$/iu', $desc, $q)) {
                $q2 = (float)str_replace(',', '.', $q[1]);
                if ($q2 > 0 && $q2 < 10000 && abs(round($amount / $q2, 2) * $q2 - $amount) < 0.02) {
                    $qty = $q2;
                    $price = round($amount / $q2, 2);
                    // "2 x Gloves  35.00  70.00": drop the unit price printed after the name
                    $desc = trim(preg_replace('/\s+' . self::MONEY . '$/u', '', trim($q[2])) ?? $q[2]);
                }
            }
            $desc = trim(preg_replace('/\s{2,}/', ' ', $desc), " .:-");
            if (mb_strlen($desc) < 2 || preg_match('/^\d+$/', $desc)) continue;
            $items[] = ['description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit_price' => $price, 'amount' => $amount];
            if (count($items) > 40) return [[], null];
        }
        if (!$items) return [[], null];
        $sum = round(array_sum(array_column($items, 'amount')), 2);
        if ($total !== null && abs($sum - $total) <= 0.02) return [$items, true];
        if ($subtotal !== null && abs($sum - $subtotal) <= 0.02) return [$items, false];
        return [[], null];
    }
}
