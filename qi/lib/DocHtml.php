<?php
/**
 * DocHtml — renders a QiDocument model as the "paper" HTML used on screen, on
 * the printed page, on the public link and in the designer's live preview.
 *
 * Layout is expressed in points (pt) with the same numbers DocPdf uses, so the
 * PDF and the screen match. The design arrives resolved (QiTheme::normalize);
 * colours become CSS custom properties on the paper, layout choices become
 * data-attributes that /qi/css/doc.css styles. Everything printed is escaped.
 */

require_once __DIR__ . '/QiTheme.php';
require_once __DIR__ . '/QiMoney.php';

final class DocHtml
{
    /** Layout constants shared with DocPdf (points). */
    public const MARGINS = ['compact' => 36, 'normal' => 48, 'roomy' => 62];
    public const TITLE_SIZES = ['sm' => 16, 'md' => 21, 'lg' => 27, 'xl' => 34];

    /**
     * @param array $m      QiDocument model
     * @param array $design normalised design
     * @param array $opt    ['editable' => bool (designer hit-areas), 'stamp' => bool]
     */
    public static function render(array $m, array $design, array $opt = []): string
    {
        $type = $m['type'];
        $doc = QiTheme::forDocument($design, $type, $m['company']['raw'] ?? []);
        $c = self::palette($design);
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $money = fn(float $v, bool $sym = true) => $e(QiMoney::format($v, $m['symbol'], $sym));
        $L = $design['labels'];
        $h = $design['header'];
        $t = $design['table'];
        $scale = $design['fonts']['scale'] / 100;
        $margin = self::MARGINS[$design['page']['margins']] ?? 48;
        $logoW = round($h['logo_size'] * 0.75, 1); // designer px → pt

        $vars = [
            '--qd-accent' => $c['accent'], '--qd-accent2' => $c['accent2'], '--qd-heading' => $c['heading'],
            '--qd-text' => $c['text'], '--qd-muted' => $c['muted'], '--qd-paper' => $c['paper'], '--qd-rule' => $c['rule'],
            '--qd-head-bg' => $c['head_bg'], '--qd-head-text' => $c['head_text'], '--qd-stripe' => $c['stripe'],
            '--qd-soft' => $c['soft'], '--qd-soft-text' => $c['soft_text'], '--qd-band-text' => $c['band_text'],
            '--qd-band-muted' => $c['band_muted'], '--qd-total-text' => $c['total_text'],
            '--qd-font-h' => QiTheme::fontStack($design['fonts']['heading']), '--qd-font-b' => QiTheme::fontStack($design['fonts']['body']),
            '--qd-scale' => (string)$scale, '--qd-margin' => $margin . 'pt', '--qd-radius' => $t['radius'] . 'pt',
            '--qd-logo-w' => $logoW . 'pt', '--qd-title' => self::TITLE_SIZES[$h['title_size']] . 'pt',
        ];
        $style = implode(';', array_map(fn($k, $v) => $k . ':' . $v, array_keys($vars), $vars));

        $hasDiscount = false;
        $hasUnit = false;
        $rates = [];
        foreach ($m['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            if ($r['discount'] > 0.004) $hasDiscount = true;
            if ($r['unit'] !== '' && strtolower($r['unit']) !== 'ea') $hasUnit = true;
            $rates[QiMoney::rate($r['rate'])] = true;
        }
        $cols = [
            'num' => $t['cols']['num'],
            'qty' => $t['cols']['qty'],
            'unit' => $t['cols']['unit'] && $t['cols']['qty'],
            'price' => $t['cols']['price'],
            'discount' => $t['cols']['discount'] === 'on' || ($t['cols']['discount'] === 'auto' && $hasDiscount),
            'vat' => $t['cols']['vat'] === 'on' || ($t['cols']['vat'] === 'auto' && count($rates) > 1),
        ];

        $hit = !empty($opt['editable']) ? fn(string $zone) => ' data-zone="' . $zone . '"' : fn(string $zone) => '';
        $layout = $h['layout'];
        $swap = in_array($layout, ['split', 'banner', 'band'], true) && $h['logo_position'] === 'right';

        $o = '<article class="qd" style="' . $e($style) . '" data-layout="' . $layout . '" data-logo="' . $h['logo_position'] . '"'
           . ' data-swap="' . ($swap ? '1' : '0') . '" data-table="' . $t['style'] . '" data-head="' . $t['head'] . '"'
           . ' data-heading-row="' . $t['heading_row'] . '" data-density="' . $t['density'] . '" data-totals="' . $design['totals']['style'] . '"'
           . ' data-bar="' . $h['accent_bar'] . '" data-size="' . $design['page']['size'] . '" data-type="' . $type . '">';
        if ($h['accent_bar'] !== 'none') $o .= '<i class="qd-bar" aria-hidden="true"></i>';

        // ---------------- letterhead + title
        $co = $m['company'];
        $letter = '<div class="qd-letter"' . $hit('logo') . '>';
        if ($co['logo_url']) {
            $letter .= '<img class="qd-logo" src="' . $e($co['logo_url']) . '" alt="' . $e($co['name']) . '">';
        }
        if ($h['show_name'] || !$co['logo_url']) {
            $letter .= '<div class="qd-company">' . $e($co['name']) . '</div>';
        }
        $details = self::companyLines($co, $doc['company']);
        if ($details) {
            $letter .= '<div class="qd-company-lines"' . $hit('company') . '>' . implode('', array_map(fn($l) => '<div>' . $e($l) . '</div>', $details)) . '</div>';
        }
        $letter .= '</div>';

        $titleBlock = '<div class="qd-titleblock"' . $hit('title') . '>'
            . '<h1 class="qd-title">' . $e($doc['title']) . '</h1>'
            . '<div class="qd-number">' . $e($L['number']) . ' ' . $e($m['number'] !== '' ? $m['number'] : '—') . '</div>';
        if ($h['ornament']) $titleBlock .= '<i class="qd-ornament" aria-hidden="true"></i>';
        $titleBlock .= '</div>';

        $o .= '<header class="qd-head">' . ($swap ? $titleBlock . $letter : $letter . $titleBlock) . '</header>';

        // ---------------- parties + meta
        $cu = $m['customer'];
        $bill = '<div class="qd-bill"' . $hit('customer') . '><div class="qd-eyebrow">' . $e($L['bill_to']) . '</div>'
              . '<div class="qd-bill-name">' . $e($cu['name']) . '</div>';
        foreach (self::customerLines($cu, $doc['customer']) as $line) $bill .= '<div class="qd-bill-line">' . $e($line) . '</div>';
        $bill .= '</div>';

        $meta = '<dl class="qd-meta"' . $hit('meta') . '>';
        foreach (self::metaRows($m, $L) as [$label, $value]) {
            $meta .= '<div><dt>' . $e($label) . '</dt><dd>' . $e($value) . '</dd></div>';
        }
        $meta .= '</dl>';
        $o .= '<section class="qd-parties">' . $bill . $meta . '</section>';

        // ---------------- lines
        $o .= '<table class="qd-lines"' . $hit('table') . '><thead><tr>';
        if ($cols['num']) $o .= '<th class="qd-c-num">#</th>';
        $o .= '<th class="qd-c-desc">' . $e($L['description']) . '</th>';
        if ($cols['qty']) $o .= '<th class="qd-c-qty">' . $e($L['qty']) . '</th>';
        if ($cols['unit']) $o .= '<th class="qd-c-unit">' . $e($L['unit']) . '</th>';
        if ($cols['price']) $o .= '<th class="qd-c-num-r">' . $e($L['price']) . '</th>';
        if ($cols['discount']) $o .= '<th class="qd-c-num-r">' . $e($L['discount']) . '</th>';
        if ($cols['vat']) $o .= '<th class="qd-c-vat">' . $e($L['vat']) . '</th>';
        $o .= '<th class="qd-c-num-r qd-c-amount">' . $e($L['amount']) . '</th></tr></thead><tbody>';
        $span = 2 + (int)$cols['num'] + (int)$cols['qty'] + (int)$cols['unit'] + (int)$cols['price'] + (int)$cols['discount'] + (int)$cols['vat'];
        $n = 0;
        $stripe = 0;
        foreach ($m['rows'] as $r) {
            if ($r['kind'] === 'heading') {
                $stripe = 0;
                $o .= '<tr class="qd-row-heading"><td colspan="' . $span . '"><span>' . $e($r['title']) . '</span></td></tr>';
                continue;
            }
            if ($r['kind'] === 'section_total') {
                if (!$t['section_totals']) continue;
                $o .= '<tr class="qd-row-section"><td colspan="' . ($span - 1) . '">' . $e($r['title'] !== '' ? $r['title'] . ' · ' . $L['section_total'] : $L['section_total']) . '</td>'
                    . '<td class="qd-c-num-r">' . $money($r['net']) . '</td></tr>';
                continue;
            }
            $n++;
            $stripe++;
            $parts = explode("\n", str_replace("\r", '', $r['description']), 2);
            $o .= '<tr class="qd-row' . ($stripe % 2 === 0 ? ' is-even' : '') . '">';
            if ($cols['num']) $o .= '<td class="qd-c-num">' . $n . '</td>';
            $o .= '<td class="qd-c-desc"><div class="qd-desc">' . $e($parts[0]) . '</div>'
                . (isset($parts[1]) && trim($parts[1]) !== '' ? '<div class="qd-desc-sub">' . nl2br($e(trim($parts[1]))) . '</div>' : '') . '</td>';
            if ($cols['qty']) $o .= '<td class="qd-c-qty">' . $e(QiMoney::qty($r['qty'])) . (!$cols['unit'] && $hasUnit && $r['unit'] !== '' && strtolower($r['unit']) !== 'ea' ? ' <small>' . $e($r['unit']) . '</small>' : '') . '</td>';
            if ($cols['unit']) $o .= '<td class="qd-c-unit">' . $e($r['unit'] !== '' ? $r['unit'] : '—') . '</td>';
            if ($cols['price']) $o .= '<td class="qd-c-num-r">' . $money($r['price'], false) . '</td>';
            if ($cols['discount']) $o .= '<td class="qd-c-num-r">' . ($r['discount'] > 0.004 ? '−' . $money($r['discount'], false) : '—') . '</td>';
            if ($cols['vat']) $o .= '<td class="qd-c-vat">' . $e(QiMoney::rate($r['rate'])) . '%</td>';
            $o .= '<td class="qd-c-num-r qd-c-amount">' . $money($r['net'], false) . '</td></tr>';
        }
        if ($n === 0) $o .= '<tr class="qd-row"><td colspan="' . $span . '" class="qd-empty">No lines yet</td></tr>';
        $o .= '</tbody></table>';

        // ---------------- totals
        $tot = $m['totals'];
        $rows = self::totalRows($m, $design);
        $o .= '<section class="qd-totals-wrap"><div class="qd-totals-side">';
        if ($design['sections']['notes'] && $m['notes'] !== '') {
            $o .= '<div class="qd-note"' . $hit('notes') . '><div class="qd-eyebrow">' . $e($L['notes']) . '</div><p>' . nl2br($e($m['notes'])) . '</p></div>';
        }
        $o .= '</div><div class="qd-totals"' . $hit('totals') . '>';
        foreach ($rows as $row) {
            $o .= '<div class="qd-trow' . ($row['class'] ? ' ' . $row['class'] : '') . '"><span>' . $e($row['label']) . '</span><span>' . $money($row['value']) . '</span></div>';
        }
        $o .= '</div></section>';

        // ---------------- payment details + online link
        $cards = '';
        $bank = $m['company']['bank'];
        if ($design['sections']['payment'] && $type !== 'credit_note' && ($bank['account'] !== '' || $bank['name'] !== '')) {
            $cards .= '<div class="qd-card"' . $hit('payment') . '><div class="qd-eyebrow">' . $e($L['payment']) . '</div><dl class="qd-kv">';
            foreach ([['Bank', $bank['name']], ['Account holder', $bank['holder']], ['Account no.', $bank['account']], ['Branch code', $bank['branch']], ['Reference', $m['number']]] as [$k, $v]) {
                if (trim((string)$v) !== '') $cards .= '<div><dt>' . $e($k) . '</dt><dd>' . $e($v) . '</dd></div>';
            }
            $cards .= '</dl></div>';
        }
        $link = self::onlineLink($m, $design);
        if ($link) {
            $cards .= '<a class="qd-card qd-cta" href="' . $e($link['url']) . '" target="_blank" rel="noopener"' . $hit('link') . '><div class="qd-eyebrow">' . $e($link['eyebrow']) . '</div>'
                    . '<div class="qd-cta-title">' . $e($link['title']) . ' <span aria-hidden="true">→</span></div><div class="qd-cta-url">' . $e(self::shortUrl($link['url'])) . '</div></a>';
        }
        if ($cards !== '') $o .= '<section class="qd-cards">' . $cards . '</section>';

        // ---------------- schedule, payments, credit-note reason
        if ($design['sections']['milestones'] && $m['milestones']) {
            $o .= '<section class="qd-block"><div class="qd-eyebrow">' . $e($L['schedule']) . '</div><table class="qd-mini"><tbody>';
            foreach ($m['milestones'] as $ms) {
                $o .= '<tr><td>' . $e($ms['label']) . '</td><td class="qd-c-num-r">' . $e(QiMoney::rate($ms['percentage'])) . '%</td>'
                    . '<td>' . $e($ms['due_date'] ? self::date($ms['due_date']) : '') . '</td><td class="qd-c-num-r">' . $money($ms['amount']) . '</td>'
                    . '<td class="qd-c-num-r qd-ms-' . $e($ms['status']) . '">' . $e(ucfirst($ms['status'])) . '</td></tr>';
            }
            $o .= '</tbody></table></section>';
        }
        if ($type === 'invoice' && $design['sections']['payments'] && ($m['payments'] || $m['credits'])) {
            $o .= '<section class="qd-block"><div class="qd-eyebrow">' . $e($L['payments']) . '</div><table class="qd-mini"><tbody>';
            foreach ($m['payments'] as $p) {
                $o .= '<tr><td>' . $e(self::date($p['date'])) . '</td><td>' . $e($p['method']) . '</td><td>' . $e($p['reference']) . '</td><td class="qd-c-num-r">' . $money($p['amount']) . '</td></tr>';
            }
            foreach ($m['credits'] as $cr) {
                $o .= '<tr><td>' . $e(self::date($cr['date'])) . '</td><td>Credit note</td><td>' . $e($cr['number']) . '</td><td class="qd-c-num-r">' . $money($cr['amount']) . '</td></tr>';
            }
            $o .= '</tbody></table></section>';
        }
        if ($type === 'credit_note' && ($m['reason'] ?? '') !== '') {
            $o .= '<section class="qd-block"><div class="qd-eyebrow">Reason</div><p>' . nl2br($e($m['reason'])) . '</p></section>';
        }
        if ($design['sections']['terms'] && $m['terms'] !== '') {
            $o .= '<section class="qd-block"' . $hit('terms') . '><div class="qd-eyebrow">' . $e($L['terms']) . '</div><p>' . nl2br($e($m['terms'])) . '</p></section>';
        }
        if ($type === 'quote' && $design['sections']['signature']) {
            $acc = $m['accepted'] ?? null;
            $o .= '<section class="qd-sign"' . $hit('signature') . '>';
            if ($acc) {
                $o .= '<div class="qd-sign-done">' . $e($L['accept']) . ' <strong>' . $e($acc['name'] !== '' ? $acc['name'] : 'the customer') . '</strong> · ' . $e(self::date(substr((string)$acc['at'], 0, 10))) . '</div>';
            } else {
                $o .= '<div class="qd-sign-line"><span></span><small>' . $e($L['accept']) . '</small></div><div class="qd-sign-line"><span></span><small>' . $e($L['date']) . '</small></div>';
            }
            $o .= '</section>';
        }

        if ($doc['footer'] !== '') $o .= '<footer class="qd-foot"' . $hit('footer') . '>' . nl2br($e($doc['footer'])) . '</footer>';

        $stamp = ($opt['stamp'] ?? true) && $design['stamp']['status'] ? ($m['status']['stamp'] ?? null) : null;
        if ($stamp) $o .= '<div class="qd-stamp qd-stamp--' . $e(strtolower(str_replace(' ', '-', $stamp))) . '" aria-hidden="true">' . $e($stamp) . '</div>';
        $o .= '</article>';
        return $o;
    }

    /** The CSS custom properties block for company custom CSS + fonts, emitted once per page. */
    public static function headTags(array $design, string $cssVersion = ''): string
    {
        $o = QiTheme::fontLinks($design);
        $o .= '<link rel="stylesheet" href="/qi/css/doc.css' . ($cssVersion !== '' ? '?v=' . htmlspecialchars($cssVersion) : '') . '">';
        if ($design['custom_css'] !== '') $o .= '<style>' . $design['custom_css'] . '</style>';
        return $o;
    }

    // --------------------------------------------------------------- shared

    /** Colours the renderers need, with legibility guards. */
    public static function palette(array $design): array
    {
        $c = $design['colors'];
        $soft = QiTheme::mix($c['accent'], $c['paper'], 0.9);
        $bandBg = $design['header']['layout'] === 'banner' ? $c['accent'] : $soft;
        $c['soft'] = $soft;
        $c['soft_text'] = QiTheme::readable($c['accent'], $soft, 3.0);
        $c['band_bg'] = $bandBg;
        $c['band_text'] = $design['header']['layout'] === 'banner' ? QiTheme::idealInk($c['accent']) : QiTheme::readable($c['heading'], $soft, 4.5);
        $c['band_muted'] = $design['header']['layout'] === 'banner' ? QiTheme::mix($c['band_text'], $c['accent'], 0.28) : $c['muted'];
        $c['head_text'] = QiTheme::readable($c['head_text'], $c['head_bg'], 3.0);
        $c['total_text'] = QiTheme::idealInk($c['accent']);
        return $c;
    }

    public static function companyLines(array $co, array $show): array
    {
        $out = [];
        if ($show['address']) foreach ($co['address'] as $l) $out[] = $l;
        $contact = array_filter([$show['phone'] ? $co['phone'] : '', $show['email'] ? $co['email'] : '']);
        if ($contact) $out[] = implode('  ·  ', $contact);
        if ($show['website'] && $co['website'] !== '') $out[] = preg_replace('#^https?://#i', '', $co['website']);
        $ids = array_filter([
            $show['vat'] && $co['vat'] !== '' ? 'VAT ' . $co['vat'] : '',
            $show['reg'] && $co['reg'] !== '' ? 'Reg ' . $co['reg'] : '',
            $show['tax'] && $co['tax'] !== '' ? 'Tax ' . $co['tax'] : '',
        ]);
        if ($ids) $out[] = implode('  ·  ', $ids);
        return $out;
    }

    public static function customerLines(array $cu, array $show): array
    {
        $out = [];
        if (($cu['legal_name'] ?? '') !== '') $out[] = $cu['legal_name'];
        if ($show['contact'] && $cu['contact'] !== '') $out[] = 'Attn: ' . $cu['contact'];
        if ($show['address']) foreach ($cu['address'] as $l) $out[] = $l;
        $contact = array_filter([$show['phone'] ? $cu['phone'] : '', $show['email'] ? $cu['email'] : '']);
        if ($contact) $out[] = implode('  ·  ', $contact);
        $ids = array_filter([$show['vat'] && $cu['vat'] !== '' ? 'VAT ' . $cu['vat'] : '', $show['reg'] && $cu['reg'] !== '' ? 'Reg ' . $cu['reg'] : '']);
        if ($ids) $out[] = implode('  ·  ', $ids);
        return $out;
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function metaRows(array $m, array $L): array
    {
        $rows = [[$L['date'], self::date($m['issue_date'])]];
        if ($m['type'] === 'quote' && !empty($m['expiry_date'])) $rows[] = [$L['valid_until'], self::date($m['expiry_date'])];
        if ($m['type'] === 'invoice' && !empty($m['due_date'])) $rows[] = [$L['due_date'], self::date($m['due_date'])];
        if ($m['type'] === 'credit_note' && !empty($m['invoice'])) $rows[] = [$L['invoice'], $m['invoice']['number']];
        if ($m['type'] === 'invoice' && !empty($m['quote'])) $rows[] = [$L['quote'], $m['quote']['number']];
        if ($m['reference'] !== '') $rows[] = [$L['reference'], $m['reference']];
        if (!empty($m['project'])) $rows[] = ['Project', $m['project']];
        if ($m['currency'] !== 'ZAR') $rows[] = ['Currency', $m['currency']];
        return $rows;
    }

    /** Totals rows in order: label, value, css class. */
    public static function totalRows(array $m, array $design): array
    {
        $L = $design['labels'];
        $t = $m['totals'];
        $rows = [];
        $showDiscount = $t['discount'] > 0.004 || $design['totals']['show_zero_discount'];
        if ($showDiscount) {
            $rows[] = ['label' => $L['subtotal'], 'value' => $t['before'], 'class' => ''];
            $rows[] = ['label' => $L['discount'], 'value' => -$t['discount'], 'class' => 'is-discount'];
            $rows[] = ['label' => $L['subtotal'] . ' (excl. ' . $L['vat'] . ')', 'value' => $t['net'], 'class' => ''];
        } else {
            $rows[] = ['label' => $L['subtotal'], 'value' => $t['net'], 'class' => ''];
        }
        $byRate = $t['by_rate'] ?? [];
        $vatRows = array_filter($byRate, fn($v, $k) => (float)$k > 0, ARRAY_FILTER_USE_BOTH);
        if (count($vatRows) > 1) {
            foreach ($vatRows as $rate => $v) $rows[] = ['label' => $L['vat'] . ' ' . $rate . '%', 'value' => $v['vat'], 'class' => ''];
        } else {
            $rate = $vatRows ? array_key_first($vatRows) : null;
            $rows[] = ['label' => $L['vat'] . ($rate !== null ? ' ' . $rate . '%' : ''), 'value' => $t['vat'], 'class' => ''];
        }
        $rows[] = ['label' => $L['total'], 'value' => $t['total'], 'class' => 'is-grand'];
        if ($m['type'] === 'invoice') {
            $paid = array_sum(array_column($m['payments'], 'amount'));
            $credited = array_sum(array_column($m['credits'], 'amount'));
            if ($paid > 0.004) $rows[] = ['label' => $L['paid'], 'value' => -$paid, 'class' => 'is-paid'];
            if ($credited > 0.004) $rows[] = ['label' => $L['credit_note'], 'value' => -$credited, 'class' => 'is-paid'];
            if ($paid > 0.004 || $credited > 0.004 || abs(($t['balance'] ?? $t['total']) - $t['total']) > 0.004) {
                $rows[] = ['label' => $L['balance_due'], 'value' => (float)($t['balance'] ?? 0), 'class' => 'is-balance'];
            }
        }
        return $rows;
    }

    /** The "accept online" / "pay online" call to action, when the design wants it and a link exists. */
    public static function onlineLink(array $m, array $design): ?array
    {
        if (!$design['sections']['online_link']) return null;
        $L = $design['labels'];
        if ($m['type'] === 'quote' && !empty($m['links']['public']) && in_array($m['status']['key'] ?? '', ['sent', 'viewed', 'draft'], true)) {
            return ['url' => $m['links']['public'], 'eyebrow' => 'Online', 'title' => $L['view_online']];
        }
        if ($m['type'] === 'invoice' && !empty($m['links']['pay']) && (float)($m['totals']['balance'] ?? 0) > 0.004) {
            return ['url' => $m['links']['pay'], 'eyebrow' => 'Card or instant EFT', 'title' => $L['pay_online']];
        }
        return null;
    }

    public static function date(?string $ymd): string
    {
        if (!$ymd || $ymd < '1000-01-01') return '';
        $ts = strtotime($ymd);
        return $ts ? date('j M Y', $ts) : '';
    }

    public static function shortUrl(string $url): string
    {
        $u = preg_replace('#^https?://(www\.)?#i', '', $url);
        return mb_strlen($u) > 48 ? mb_substr($u, 0, 46) . '…' : $u;
    }
}
