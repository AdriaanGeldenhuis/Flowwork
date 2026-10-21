<?php
// /suppliers_ai/lib/SaScore.php — how well a supplier fits a search, from 0 to
// 1, with the reasons in words (no database; unit tested). Everything counted
// is something real: the CRM, what was bought, past quotes, delivery figures,
// documents, Google's listing and the distance. Nothing is made up to fill a gap.

final class SaScore
{
    /**
     * @param array $c a candidate (SaSearch): source crm|google, hits, bills, pos, past, perf, google, compliance, allowed, distance_km
     * @param array $ctx radius (km), place, books (may see what was billed)
     * @return array{0: float, 1: array} score and reasons [{i, t, tone}]
     */
    public static function score(array $c, array $ctx): array
    {
        $r = [];
        $add = function (string $icon, string $text, string $tone = '') use (&$r) { $r[] = ['i' => $icon, 't' => $text, 'tone' => $tone]; };
        $s = 0.0;
        $g = $c['google'] ?? null;

        if (($c['source'] ?? '') === 'crm') {
            $s = 0.40;
            $add('i-building', ($c['status'] ?? '') === 'prospect' ? 'In your CRM as a prospect' : 'One of your suppliers', 'ok');
            if (!empty($c['preferred'])) { $s += 0.10; $add('i-star', 'Preferred supplier', 'gold'); }
            $rel = 0.0;
            $hits = $c['hits'] ?? [];
            if (!empty($hits['industry'])) { $rel += 0.25; $add('i-briefcase', 'Industry: ' . ($c['industry'] ?? '')); }
            if (!empty($hits['tags'])) { $rel += 0.25; $add('i-tag', 'Tagged ' . implode(', ', array_slice($hits['tags'], 0, 3))); }
            if (!empty($hits['name'])) { $rel += 0.25; $add('i-search', 'The name says “' . $hits['name'][0] . '”'); }
            if (!empty($hits['notes'])) { $rel += 0.15; $add('i-pen', 'Your notes mention “' . $hits['notes'][0] . '”'); }
            if (!empty($hits['website'])) $rel += 0.05;
            $s += min(0.45, $rel) * (!empty($hits['weak']) ? 0.4 : 1.0);

            if ($b = $c['bills'] ?? null) {
                $s += min(0.20, 0.10 + 0.03 * (int)$b['n']);
                if (!empty($ctx['books'])) {
                    $add('i-receipt', 'Billed you for “' . mb_strimwidth((string)$b['desc'], 0, 60, '…') . '” on ' . SaKit::date((string)$b['last']) . ($b['price'] !== null ? ' · ' . SaKit::money((int)$b['price']) . ' each incl. VAT' : '') . ((int)$b['n'] > 1 ? ' · ' . (int)$b['n'] . ' bills' : ''), 'ok');
                } else {
                    $add('i-receipt', 'You have bought from them before (last on ' . SaKit::date((string)$b['last']) . ')', 'ok');
                }
            } elseif ($p = $c['pos'] ?? null) {
                $s += 0.06;
                $add('is-order', !empty($ctx['books']) ? 'On purchase order ' . $p['po'] . ' for “' . mb_strimwidth((string)$p['desc'], 0, 50, '…') . '”' : 'You have ordered from them before', 'ok');
            }
            if ($p = $c['past'] ?? null) {
                if ((int)$p['won'] > 0) { $s += 0.10; $add('ic-trophy', 'Won your request “' . mb_strimwidth((string)$p['won_title'], 0, 50, '…') . '”', 'gold'); }
                elseif ((int)$p['quoted'] > 0) { $s += 0.05; $add('i-file-text', 'Quoted on ' . ((int)$p['quoted'] === 1 ? 'a similar request' : (int)$p['quoted'] . ' similar requests')); }
                if ((int)$p['sent'] >= 2 && (int)$p['answered'] === 0) { $s -= 0.06; $add('i-clock', 'Asked ' . (int)$p['sent'] . ' times before — never replied', 'warn'); }
                elseif ((int)$p['answered'] > 0 && $p['reply_min'] !== null && (float)$p['reply_min'] <= 480) {
                    $s += 0.03;
                    $h = (float)$p['reply_min'] / 60;
                    $add('i-zap', 'Usually replies within ' . ($h < 1 ? 'the hour' : (int)ceil($h) . ' hours'), 'ok');
                }
            }
            $perf = $c['perf'] ?? [];
            if (isset($perf['on_time'])) {
                if ($perf['on_time'] >= 90) { $s += 0.04; $add('i-check', 'Delivered ' . round($perf['on_time']) . '% of orders', 'ok'); }
                elseif ($perf['on_time'] < 70) { $s -= 0.04; $add('i-alert', 'Delivered only ' . round($perf['on_time']) . '% of orders', 'warn'); }
            }
            if (isset($perf['defect']) && $perf['defect'] > 10) { $s -= 0.04; $add('i-alert', round($perf['defect']) . '% of deliveries cancelled', 'warn'); }
            if (isset($perf['response_h']) && $perf['response_h'] > 0) $add('i-truck', 'Delivers in about ' . max(1, (int)round($perf['response_h'] / 24)) . ' ' . (round($perf['response_h'] / 24) <= 1 ? 'day' : 'days') . ' on average');
            if (($c['status'] ?? '') === 'prospect') $s -= 0.03;
            if ($g && $g['rating'] !== null && (int)$g['rating_count'] >= 3) {
                if ($g['rating'] >= 4.2) $s += 0.03;
                $add('i-star', 'Also on Google: ' . number_format((float)$g['rating'], 1) . '★ (' . (int)$g['rating_count'] . ')', $g['rating'] >= 4 ? 'ok' : '');
            }
        } else {
            $s = 0.42;
            $add('i-globe', 'Listed on Google' . (($g['type'] ?? '') !== '' ? ' as ' . $g['type'] : ''));
            if (!empty($c['hits']['name'])) $s += 0.10;
            if (!empty($c['hits']['type'])) $s += 0.08;
            $count = (int)($g['rating_count'] ?? 0);
            if ($g && $g['rating'] !== null && $count >= 3) {
                $s += ((float)$g['rating'] - 3.0) / 2 * 0.18 * min(1.0, log10($count + 1) / 2);
                $add('i-star', number_format((float)$g['rating'], 1) . '★ from ' . $count . ' Google reviews', $g['rating'] >= 4 ? 'ok' : ($g['rating'] < 3.5 ? 'warn' : ''));
            } elseif ($count > 0) {
                $add('i-star', 'Only ' . $count . ' Google ' . ($count === 1 ? 'review' : 'reviews'));
            } else {
                $add('i-star', 'No Google reviews yet');
            }
            if (($c['phone'] ?? '') === '' && ($c['website'] ?? '') === '') { $s -= 0.08; $add('i-alert', 'Google lists no phone number or website', 'warn'); }
            if (!empty($g['temporarily_closed'])) { $s -= 0.25; $add('i-alert', 'Temporarily closed, says Google', 'danger'); }
            $add('i-info', 'Not in your CRM yet — check them before you buy');
        }

        $d = $c['distance_km'] ?? null;
        $radius = (float)($ctx['radius'] ?? 25);
        if ($d !== null) {
            $where = ($ctx['place'] ?? '') !== '' ? ' from ' . $ctx['place'] : ' away';
            if ($d <= $radius) { $s += 0.10 * max(0.0, 1 - $d / max(1.0, $radius)); $add('i-pin', self::km($d) . $where); }
            else { $s -= 0.05; $add('i-pin', self::km($d) . $where . ' — outside ' . self::km($radius), 'warn'); }
        }

        $comp = $c['compliance'] ?? null;
        if ($comp && ($c['source'] ?? '') === 'crm') {
            switch ($comp['status']) {
                case 'valid': $s += 0.04; $add('i-shield', 'Documents in order', 'ok'); break;
                case 'expiring': $add('i-shield', 'Expiring soon: ' . self::few($comp['expiring']), 'warn'); break;
                case 'expired': $s -= 0.15; $add('i-shield', 'Expired: ' . self::few($comp['expired']), 'danger'); break;
                case 'missing': $s -= 0.06; $add('i-shield', 'Missing: ' . self::few($comp['missing']), 'warn'); break;
            }
            if (!empty($comp['blocking'])) $add('i-ban', 'Blocked by your company’s policy until the documents are in order', 'danger');
        }
        if (!empty($c['allowed'])) { $s += 0.15; $add('i-bookmark', 'On your always-ask list', 'gold'); }

        return [round(max(0.0, min(1.0, $s)), 2), $r];
    }

    /** "Tax clearance, BEE and 2 more" */
    public static function few(array $names, int $show = 2): string
    {
        $names = array_values($names);
        if (count($names) <= $show + 1) return implode(', ', $names);
        return implode(', ', array_slice($names, 0, $show)) . ' and ' . (count($names) - $show) . ' more';
    }

    public static function km(float $d): string
    {
        return ($d < 10 ? number_format($d, 1) : (string)(int)round($d)) . ' km';
    }

    /** The word in $text that $stem points to ("plumb" in "Quick Plumbers ZA" → "Plumbers"). */
    public static function wordFor(string $text, string $stem): string
    {
        $re = '/(?<![\p{L}\p{N}])(' . str_replace(' ', '[\s\-]+', preg_quote($stem, '/')) . '[\p{L}\p{N}]*)/iu';
        return preg_match($re, $text, $m) ? $m[1] : $stem;
    }
}
