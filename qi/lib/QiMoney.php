<?php
/**
 * QiMoney — line and document arithmetic for quotes, invoices and credit notes.
 *
 * Integer cents with exactly the rounding PostingService::postInvoice uses
 * (net = qty × price − discount, VAT rounded per line), so what a customer
 * sees on a quote, the invoice it becomes, the VAT201 and the ledger agree to
 * the cent. Every save path and every renderer goes through here.
 */
final class QiMoney
{
    /**
     * @return array{net:int, vat:int, gross:int, before:int, discount:int} cents
     */
    public static function line($qty, $price, $discount, $ratePercent): array
    {
        $qty = (float)$qty;
        $price = (float)$price;
        $discount = (float)$discount;
        $rate = (float)$ratePercent;
        $netC = (int)round(($qty * $price - $discount) * 100);
        $vatC = (int)round($netC * $rate / 100);
        return [
            'net' => $netC,
            'vat' => $vatC,
            'gross' => $netC + $vatC,
            'before' => (int)round($qty * $price * 100),
            'discount' => (int)round($discount * 100),
        ];
    }

    /**
     * Totals over item rows (rows of other kinds are ignored).
     * Each row: quantity, unit_price, discount, tax_rate.
     *
     * @return array{before:int, discount:int, net:int, vat:int, total:int, by_rate:array<string, array{net:int, vat:int}>}
     */
    public static function totals(array $rows): array
    {
        $t = ['before' => 0, 'discount' => 0, 'net' => 0, 'vat' => 0, 'total' => 0, 'by_rate' => []];
        foreach ($rows as $r) {
            if (($r['_kind'] ?? 'item') !== 'item') continue;
            $l = self::line($r['quantity'] ?? 0, $r['unit_price'] ?? 0, $r['discount'] ?? 0, $r['tax_rate'] ?? 0);
            $t['before'] += $l['before'];
            $t['discount'] += $l['discount'];
            $t['net'] += $l['net'];
            $t['vat'] += $l['vat'];
            $key = rtrim(rtrim(number_format((float)($r['tax_rate'] ?? 0), 2, '.', ''), '0'), '.');
            $t['by_rate'][$key]['net'] = ($t['by_rate'][$key]['net'] ?? 0) + $l['net'];
            $t['by_rate'][$key]['vat'] = ($t['by_rate'][$key]['vat'] ?? 0) + $l['vat'];
        }
        $t['total'] = $t['net'] + $t['vat'];
        krsort($t['by_rate'], SORT_NUMERIC);
        return $t;
    }

    public static function toUnits(int $cents): float
    {
        return $cents / 100;
    }

    /**
     * Format an amount: "R 1 234,56"-style is not used — Flowwork shows
     * "R 1,234.56" everywhere; the symbol and spacing follow the currency.
     */
    public static function format(float $amount, string $symbol, bool $withSymbol = true): string
    {
        $neg = $amount < 0;
        $s = number_format(abs($amount), 2, '.', ',');
        $out = $withSymbol && $symbol !== '' ? $symbol . ' ' . $s : $s;
        return $neg ? '−' . $out : $out;
    }

    /** Quantity without trailing zeros: 1, 2.5, 0.125. */
    public static function qty($q): string
    {
        $s = number_format((float)$q, 3, '.', ',');
        return rtrim(rtrim($s, '0'), '.');
    }

    /** VAT rate label: 15, 0, 7.5. */
    public static function rate($r): string
    {
        return rtrim(rtrim(number_format((float)$r, 2, '.', ''), '0'), '.');
    }
}
