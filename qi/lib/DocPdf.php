<?php
/**
 * DocPdf — renders a QiDocument model to PDF bytes with the company's design.
 *
 * Mirrors DocHtml + /qi/css/doc.css measure for measure (all in points), using
 * the design's real fonts (embedded, subset) so the emailed / downloaded PDF
 * looks like the document on screen. Long tables break across pages with the
 * head repeated; a line taller than a page continues on the next one; blocks
 * that fit on a page are never split; page numbers are added when there is
 * more than one page.
 */

require_once __DIR__ . '/QiTheme.php';
require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/DocHtml.php';
require_once dirname(__DIR__, 2) . '/includes/pdf/PdfDocument.php';

final class DocPdf
{
    private PdfDocument $pdf;
    private array $m;
    private array $d;
    private array $c;
    private array $doc;
    private array $L;
    private float $s;      // font scale
    private float $W;
    private float $H;
    private float $M;
    private float $x0;
    private float $x1;
    private float $y = 0;
    private float $limit;
    private string $sym;
    private ?array $tableCols = null; // set while the line table is being drawn (repeat head on new page)

    public static function render(array $m, array $design): string
    {
        return (new self($m, $design))->build();
    }

    private function __construct(array $m, array $design)
    {
        $this->m = $m;
        $this->d = $design;
        $this->c = DocHtml::palette($design);
        $this->doc = QiTheme::forDocument($design, $m['type'], $m['company']['raw'] ?? []);
        $this->L = $design['labels'];
        $this->s = $design['fonts']['scale'] / 100;
        $this->pdf = new PdfDocument($design['page']['size'], [
            'title' => $this->doc['title'] . ' ' . $m['number'],
            'author' => $m['company']['name'],
            'subject' => $m['customer']['name'],
            'creator' => 'Flowwork',
        ]);
        $this->W = $this->pdf->width;
        $this->H = $this->pdf->height;
        $this->M = (float)(DocHtml::MARGINS[$design['page']['margins']] ?? 48);
        $this->x0 = $this->M;
        $this->x1 = $this->W - $this->M;
        $this->limit = $this->H - $this->M;

        $fh = $design['fonts']['heading'];
        $fb = $design['fonts']['body'];
        $this->pdf->addFont('h', QiTheme::fontFile($fh, 'regular'));
        $this->pdf->addFont('hb', QiTheme::fontFile($fh, 'bold'));
        $this->pdf->addFont('b', QiTheme::fontFile($fb, 'regular'));
        $this->pdf->addFont('bb', QiTheme::fontFile($fb, 'bold'));
        $this->pdf->addFont('bi', QiTheme::fontFile($fb, 'italic'));
        $this->pdf->setFallbackFont(QiTheme::fallbackFontFile());
        $this->sym = $this->drawable($m['symbol']) ? $m['symbol'] : $m['currency'];
    }

    private function build(): string
    {
        $this->page();
        $this->head();
        $this->parties();
        $this->lines();
        $this->totals();
        $this->cards();
        $this->schedule();
        $this->paymentsBlock();
        $this->reason();
        $this->terms();
        $this->signature();
        $this->footer();
        $this->stamp();
        $this->pageNumbers();
        return $this->pdf->output();
    }

    // =============================================================== pages

    private function page(): void
    {
        $this->pdf->addPage();
        $c = $this->c;
        if (strtolower($c['paper']) !== '#ffffff') $this->pdf->rect(0, 0, $this->W, $this->H, ['fill' => $c['paper']]);
        $bar = $this->d['header']['accent_bar'];
        if ($bar === 'top') $this->pdf->rect(0, 0, $this->W, 5, ['fill' => $c['accent']]);
        if ($bar === 'bottom') $this->pdf->rect(0, $this->H - 5, $this->W, 5, ['fill' => $c['accent']]);
        if ($bar === 'left') $this->pdf->rect(0, 0, 7, $this->H, ['fill' => $c['accent']]);
        $this->y = $this->M;
    }

    /** Make room for $h points; starts a new page (repeating the table head) when needed. */
    private function room(float $h): void
    {
        if ($this->y + $h <= $this->limit) return;
        $this->page();
        if ($this->tableCols !== null) $this->tableHead($this->tableCols);
    }

    // ============================================================== text kit

    private function fs(float $pt): float { return $pt * $this->s; }

    /** Baseline for a line box starting at $top with line height $lh. */
    private function base(float $top, string $font, float $size, float $lh): float
    {
        [$asc, $desc] = $this->pdf->metrics($font, $size);
        return $top + ($lh - ($asc + $desc)) / 2 + $asc;
    }

    private function t(float $x, float $top, string $text, string $font, float $size, string $color, float $lh = 1.45, array $opt = []): float
    {
        $line = $size * $lh;
        $this->pdf->text($x, $this->base($top, $font, $size, $line), $text, $opt + ['font' => $font, 'size' => $size, 'color' => $color]);
        return $line;
    }

    /** Wrapped paragraph; returns its height. */
    private function para(float $x, float $top, float $w, string $text, string $font, float $size, string $color, float $lh = 1.45, string $align = 'left'): float
    {
        $line = $size * $lh;
        $yy = $top;
        foreach ($this->pdf->wrap($text, $font, $size, $w) as $l) {
            $ax = $align === 'right' ? $x + $w : ($align === 'center' ? $x + $w / 2 : $x);
            $this->pdf->text($ax, $this->base($yy, $font, $size, $line), $l, ['font' => $font, 'size' => $size, 'color' => $color, 'align' => $align]);
            $yy += $line;
        }
        return $yy - $top;
    }

    private function paraHeight(float $w, string $text, string $font, float $size, float $lh = 1.45): float
    {
        return count($this->pdf->wrap($text, $font, $size, $w)) * $size * $lh;
    }

    private function eyebrow(float $x, float $top, string $text, ?string $color = null, string $align = 'left'): float
    {
        $size = $this->fs(6.8);
        $txt = mb_strtoupper($text);
        $sp = $size * 0.14;
        $ax = $align === 'right' ? $x : $x;
        $this->pdf->text($ax, $this->base($top, 'bb', $size, $size * 1.45), $txt, ['font' => 'bb', 'size' => $size, 'color' => $color ?? $this->c['accent'], 'spacing' => $sp, 'align' => $align]);
        return $size * 1.45 + 5;
    }

    private function money(float $v, bool $sym = true): string
    {
        return QiMoney::format($v, $sym ? $this->sym : '', $sym);
    }

    private function drawable(string $s): bool
    {
        $main = $this->pdf->font('b');
        $fb = $this->pdf->font('__fallback');
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch);
            if (!$main->hasGlyph($cp) && !$fb->hasGlyph($cp)) return false;
        }
        return true;
    }

    // ============================================================== header

    private function head(): void
    {
        $h = $this->d['header'];
        $layout = $h['layout'];
        $cw = $this->x1 - $this->x0;
        $swap = in_array($layout, ['split', 'banner', 'band'], true) && $h['logo_position'] === 'right';
        $banner = in_array($layout, ['banner', 'band'], true);
        $onBand = $layout === 'banner';

        $letterW = $layout === 'split' || $banner ? $cw * 0.62 : $cw;
        $titleW = $layout === 'split' || $banner ? $cw * 0.48 : $cw;
        $letterH = $this->letter(0, 0, $letterW, 'left', $onBand, true);
        $titleH = $this->titleBlock(0, 0, $titleW, 'right', $onBand, true);

        if ($banner) {
            $padV = $this->M * 0.75;
            $inner = max($letterH, $titleH);
            $bandH = $padV * 2 + $inner;
            $this->pdf->rect(0, 0, $this->W, $bandH, ['fill' => $layout === 'banner' ? $this->c['accent'] : $this->c['soft']]);
            $top = $padV;
            $ly = $top + ($inner - $letterH) / 2;
            $ty = $top + ($inner - $titleH) / 2;
            if ($swap) {
                $this->titleBlock($this->x0, $ty, $titleW, 'left', $onBand);
                $this->letter($this->x1 - $letterW, $ly, $letterW, 'right', $onBand);
            } else {
                $this->letter($this->x0, $ly, $letterW, 'left', $onBand);
                $this->titleBlock($this->x1 - $titleW, $ty, $titleW, 'right', $onBand);
            }
            $this->y = $bandH;
            return;
        }
        $top = $this->y;
        if ($layout === 'split') {
            if ($swap) {
                $this->titleBlock($this->x0, $top, $titleW, 'left', false);
                $this->letter($this->x1 - $letterW, $top, $letterW, 'right', false);
            } else {
                $this->letter($this->x0, $top, $letterW, 'left', false);
                $this->titleBlock($this->x1 - $titleW, $top, $titleW, 'right', false);
            }
            $this->y = $top + max($letterH, $titleH);
        } elseif ($layout === 'stacked') {
            $align = $h['logo_position'];
            $this->letter($this->x0, $top, $cw, $align, false);
            $this->y = $top + $letterH + 22;
            $this->titleBlock($this->x0, $this->y, $cw, 'left', false);
            $this->y += $titleH;
        } else { // centered
            $this->letter($this->x0, $top, $cw, 'center', false);
            $this->y = $top + $letterH + 16;
            $this->titleBlock($this->x0, $this->y, $cw, 'center', false);
            $this->y += $titleH;
        }
    }

    /** Letterhead (logo, name, detail lines). Returns its height; $measure skips drawing. */
    private function letter(float $x, float $top, float $w, string $align, bool $onBand, bool $measure = false): float
    {
        $co = $this->m['company'];
        $h = $this->d['header'];
        $y = $top;
        $ax = fn(float $bw) => $align === 'right' ? $x + $w - $bw : ($align === 'center' ? $x + ($w - $bw) / 2 : $x);
        if ($co['logo_path']) {
            $img = $this->pdf->loadImage($co['logo_path'], 1000);
            if ($img) {
                $maxW = $h['logo_size'] * 0.75;
                $maxH = 64.0;
                $r = min($maxW / $img['w'], $maxH / $img['h']);
                $lw = $img['w'] * $r;
                $lh = $img['h'] * $r;
                $chip = $this->d['header']['layout'] === 'banner';
                if (!$measure) {
                    if ($chip) {
                        $this->pdf->rect($ax($lw + 16), $y, $lw + 16, $lh + 12, ['fill' => '#ffffff', 'radius' => $this->d['table']['radius'] * 0.6 + 2]);
                        $this->pdf->image($img['key'], $ax($lw + 16) + 8, $y + 6, $lw, $lh);
                    } else {
                        $this->pdf->image($img['key'], $ax($lw), $y, $lw, $lh);
                    }
                }
                $y += $lh + ($chip ? 12 : 0) + 8;
            }
        }
        $nameColor = $onBand ? $this->c['band_text'] : ($this->d['header']['layout'] === 'band' ? $this->c['band_text'] : $this->c['heading']);
        if ($h['show_name'] || !$co['logo_path']) {
            $size = $this->fs(15);
            $lines = $this->pdf->wrap($co['name'], 'hb', $size, $w);
            foreach ($lines as $l) {
                if (!$measure) {
                    $tx = $align === 'right' ? $x + $w : ($align === 'center' ? $x + $w / 2 : $x);
                    $this->pdf->text($tx, $this->base($y, 'hb', $size, $size * 1.2), $l, ['font' => 'hb', 'size' => $size, 'color' => $nameColor, 'align' => $align]);
                }
                $y += $size * 1.2;
            }
            $y += 4;
        }
        $details = DocHtml::companyLines($co, $this->doc['company']);
        if ($details) {
            $size = $this->fs(8);
            $color = $onBand ? $this->c['band_muted'] : $this->c['muted'];
            foreach ($details as $d) {
                foreach ($this->pdf->wrap($d, 'b', $size, $w) as $l) {
                    if (!$measure) {
                        $tx = $align === 'right' ? $x + $w : ($align === 'center' ? $x + $w / 2 : $x);
                        $this->pdf->text($tx, $this->base($y, 'b', $size, $size * 1.55), $l, ['font' => 'b', 'size' => $size, 'color' => $color, 'align' => $align]);
                    }
                    $y += $size * 1.55;
                }
            }
        }
        return $y - $top;
    }

    private function titleBlock(float $x, float $top, float $w, string $align, bool $onBand, bool $measure = false): float
    {
        $h = $this->d['header'];
        $size = DocHtml::TITLE_SIZES[$h['title_size']] * $this->s;
        $titleColor = $onBand || $h['layout'] === 'band' ? $this->c['band_text'] : $this->c['heading'];
        $numColor = $onBand ? $this->c['band_text'] : $this->c['accent'];
        $tx = $align === 'right' ? $x + $w : ($align === 'center' ? $x + $w / 2 : $x);
        $y = $top;
        $title = $this->pdf->fit($this->doc['title'], 'h', $size, $w, -0.02 * $size);
        if (!$measure) $this->pdf->text($tx, $this->base($y, 'h', $size, $size * 1.02), $title, ['font' => 'h', 'size' => $size, 'color' => $titleColor, 'align' => $align, 'spacing' => -0.02 * $size]);
        $y += $size * 1.02 + 6;
        $ns = $this->fs(9);
        $num = $this->L['number'] . ' ' . ($this->m['number'] !== '' ? $this->m['number'] : '—');
        if (!$measure) $this->pdf->text($tx, $this->base($y, 'bb', $ns, $ns * 1.45), $num, ['font' => 'bb', 'size' => $ns, 'color' => $numColor, 'align' => $align, 'spacing' => $ns * 0.04]);
        $y += $ns * 1.45;
        if ($h['ornament']) {
            $y += 9;
            if (!$measure) {
                $ow = 74;
                $ox = $align === 'right' ? $x + $w - $ow : ($align === 'center' ? $x + ($w - $ow) / 2 : $x);
                $this->ornament($ox, $y + 3.5, $ow, $onBand ? $this->c['band_text'] : $this->c['accent2'], $onBand ? 0.7 : 1.0);
            }
            $y += 7;
        }
        return $y - $top;
    }

    /** The gold lozenge rule (same proportions as the CSS mask). */
    private function ornament(float $x, float $cy, float $w, string $color, float $opacity = 1.0): void
    {
        $k = $w / 92;
        $o = ['color' => $color, 'width' => 0.8, 'opacity' => $opacity];
        $this->pdf->line($x, $cy, $x + 38 * $k, $cy, $o);
        $this->pdf->line($x + 54 * $k, $cy, $x + $w, $cy, $o);
        $this->pdf->diamond($x + 46 * $k, $cy, 3.7 * $k, ['fill' => $color, 'opacity' => $opacity]);
        $this->pdf->circle($x + 40 * $k, $cy, 0.9 * $k, ['fill' => $color, 'opacity' => $opacity]);
        $this->pdf->circle($x + 52 * $k, $cy, 0.9 * $k, ['fill' => $color, 'opacity' => $opacity]);
    }

    // ============================================================= parties

    private function parties(): void
    {
        $layout = $this->d['header']['layout'];
        $this->y += 26;
        if (in_array($layout, ['split', 'stacked'], true)) {
            $this->pdf->line($this->x0, $this->y, $this->x1, $this->y, ['color' => $this->c['rule'], 'width' => 0.75]);
            $this->y += 16;
        }
        $top = $this->y;
        $cw = $this->x1 - $this->x0;
        $billW = $cw * 0.58;
        $cu = $this->m['customer'];

        // Bill to
        $y = $top + $this->eyebrow($this->x0, $top, $this->L['bill_to']);
        $ns = $this->fs(12);
        foreach ($this->pdf->wrap($cu['name'], 'hb', $ns, $billW) as $l) {
            $this->pdf->text($this->x0, $this->base($y, 'hb', $ns, $ns * 1.25), $l, ['font' => 'hb', 'size' => $ns, 'color' => $this->c['heading']]);
            $y += $ns * 1.25;
        }
        $y += 3;
        $ls = $this->fs(8.5);
        foreach (DocHtml::customerLines($cu, $this->doc['customer']) as $line) {
            $y += $this->para($this->x0, $y, $billW, $line, 'b', $ls, $this->c['text'], 1.5);
        }
        $billBottom = $y;

        // Meta (dt right-aligned, dd right-aligned)
        $ms = $this->fs(8.5);
        $rows = DocHtml::metaRows($this->m, $this->L);
        $vw = 0;
        foreach ($rows as [$k, $v]) $vw = max($vw, $this->pdf->textWidth($v, 'bb', $ms));
        $vw = min($vw, $cw * 0.4);
        $lineH = $ms * 1.45;
        $y = $top;
        foreach ($rows as [$k, $v]) {
            $base = $this->base($y, 'b', $ms, $lineH);
            $this->pdf->text($this->x1, $base, $this->pdf->fit($v, 'bb', $ms, $vw), ['font' => 'bb', 'size' => $ms, 'color' => $this->c['heading'], 'align' => 'right']);
            $this->pdf->text($this->x1 - $vw - 16, $base, $k, ['font' => 'b', 'size' => $ms, 'color' => $this->c['muted'], 'align' => 'right']);
            $y += $lineH + 3;
        }
        $this->y = max($billBottom, $y) + 18;
    }

    // =============================================================== lines

    private function columns(): array
    {
        $t = $this->d['table'];
        $hasDiscount = false;
        $hasUnit = false;
        $rates = [];
        foreach ($this->m['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            if ($r['discount'] > 0.004) $hasDiscount = true;
            if ($r['unit'] !== '' && strtolower($r['unit']) !== 'ea') $hasUnit = true;
            $rates[QiMoney::rate($r['rate'])] = true;
        }
        $show = [
            'num' => $t['cols']['num'], 'qty' => $t['cols']['qty'], 'unit' => $t['cols']['unit'] && $t['cols']['qty'],
            'price' => $t['cols']['price'],
            'discount' => $t['cols']['discount'] === 'on' || ($t['cols']['discount'] === 'auto' && $hasDiscount),
            'vat' => $t['cols']['vat'] === 'on' || ($t['cols']['vat'] === 'auto' && count($rates) > 1),
        ];
        $defs = [];
        if ($show['num']) $defs[] = ['key' => 'num', 'w' => 22, 'align' => 'left', 'label' => '#'];
        $defs[] = ['key' => 'desc', 'w' => 0, 'align' => 'left', 'label' => $this->L['description']];
        if ($show['qty']) $defs[] = ['key' => 'qty', 'w' => 44, 'align' => 'center', 'label' => $this->L['qty']];
        if ($show['unit']) $defs[] = ['key' => 'unit', 'w' => 44, 'align' => 'center', 'label' => $this->L['unit']];
        if ($show['price']) $defs[] = ['key' => 'price', 'w' => 72, 'align' => 'right', 'label' => $this->L['price']];
        if ($show['discount']) $defs[] = ['key' => 'discount', 'w' => 72, 'align' => 'right', 'label' => $this->L['discount']];
        if ($show['vat']) $defs[] = ['key' => 'vat', 'w' => 44, 'align' => 'center', 'label' => $this->L['vat']];
        $defs[] = ['key' => 'amount', 'w' => 80, 'align' => 'right', 'label' => $this->L['amount']];
        $fixed = array_sum(array_column($defs, 'w'));
        $x = $this->x0;
        foreach ($defs as $i => &$col) {
            if ($col['key'] === 'desc') $col['w'] = max(80, ($this->x1 - $this->x0) - $fixed);
            $col['x'] = $x;
            $col['padL'] = $i === 0 ? 8 : 6;
            $col['padR'] = $i === count($defs) - 1 ? 8 : 6;
            $x += $col['w'];
        }
        unset($col);
        return ['defs' => $defs, 'unitInQty' => !$show['unit'] && $hasUnit];
    }

    private function tableHead(array $cols): void
    {
        $t = $this->d['table'];
        $size = $this->fs(6.8);
        $h = 7 + $size * 1.45 + 7;
        $style = $t['head'];
        $r = (float)$t['radius'];
        $boxed = $t['style'] === 'boxed';
        $top = $this->y;
        $color = $this->c['heading'];
        if ($style === 'filled' || $style === 'soft') {
            $fill = $style === 'filled' ? $this->c['head_bg'] : $this->c['soft'];
            $color = $style === 'filled' ? $this->c['head_text'] : $this->c['soft_text'];
            $this->pdf->rect($this->x0, $top, $this->x1 - $this->x0, $h, ['fill' => $fill, 'radius' => $boxed ? [$r, $r, 0, 0] : $r]);
        } elseif ($style === 'underline') {
            $this->pdf->line($this->x0, $top + $h - 0.6, $this->x1, $top + $h - 0.6, ['color' => $this->c['accent2'], 'width' => 1.2]);
        } else {
            $color = $this->c['muted'];
            $this->pdf->line($this->x0, $top + $h - 0.4, $this->x1, $top + $h - 0.4, ['color' => $this->c['rule'], 'width' => 0.75]);
        }
        $base = $this->base($top + 7, 'bb', $size, $size * 1.45);
        foreach ($cols['defs'] as $col) {
            $label = mb_strtoupper($col['label']);
            $tx = $col['align'] === 'right' ? $col['x'] + $col['w'] - $col['padR'] : ($col['align'] === 'center' ? $col['x'] + $col['w'] / 2 : $col['x'] + $col['padL']);
            $this->pdf->text($tx, $base, $label, ['font' => 'bb', 'size' => $size, 'color' => $color, 'spacing' => $size * 0.12, 'align' => $col['align']]);
        }
        if ($boxed) {
            // outer box is drawn per page segment in lines(); remember where it starts
            $this->boxTop = $top;
        }
        $this->y = $top + $h;
    }

    private float $boxTop = 0;

    private function lines(): void
    {
        $t = $this->d['table'];
        $cols = $this->columns();
        $boxed = $t['style'] === 'boxed';
        $pad = ['compact' => 4.5, 'normal' => 7, 'airy' => 10][$t['density']] ?? 7;
        if ($t['style'] === 'minimal') $pad = max($pad, 9);
        $size = $this->fs(9);
        $lh = $size * 1.45;
        $subSize = $size * 0.9;
        $subLh = $subSize * 1.45;
        $radius = (float)$t['radius'];

        $this->room(60);
        $this->tableCols = $cols;
        $this->tableHead($cols);
        $pageOfBox = $this->pdf->pageCount();
        $closeBox = function () use (&$pageOfBox, $boxed, $radius) {
            if (!$boxed) return;
            $this->pdf->rect($this->x0, $this->boxTop, $this->x1 - $this->x0, $this->y - $this->boxTop, ['stroke' => $this->c['rule'], 'width' => 0.75, 'radius' => $radius]);
        };

        $n = 0;
        $stripe = 0;
        $descCol = null;
        foreach ($cols['defs'] as $col) if ($col['key'] === 'desc') $descCol = $col;
        $descW = $descCol['w'] - $descCol['padL'] - $descCol['padR'];

        $rows = $this->m['rows'];
        $items = 0;
        foreach ($rows as $r) if ($r['kind'] === 'item') $items++;
        if ($items === 0) {
            $this->room($lh + 2 * $pad);
            $this->pdf->text(($this->x0 + $this->x1) / 2, $this->base($this->y + $pad + 8, 'bi', $size, $lh), 'No lines yet', ['font' => 'bi', 'size' => $size, 'color' => $this->c['muted'], 'align' => 'center']);
            $this->y += $lh + 2 * $pad + 16;
        }

        foreach ($rows as $r) {
            if ($r['kind'] === 'heading') {
                $stripe = 0;
                $hs = $this->fs(9.5);
                $band = $t['heading_row'] === 'band';
                $padT = $band ? 6 : 12;
                $padB = $band ? 6 : 5;
                $h = $padT + $hs * 1.45 + $padB;
                $this->room($h + $lh + 2 * $pad); // keep a heading with its first line
                $top = $this->y;
                $color = $this->c['accent'];
                if ($band) {
                    $this->pdf->rect($this->x0, $top, $this->x1 - $this->x0, $h, ['fill' => $this->c['soft'], 'radius' => $radius * 0.6]);
                    $color = $this->c['soft_text'];
                }
                $title = $this->pdf->fit($r['title'], 'hb', $hs, $this->x1 - $this->x0 - 16);
                $base = $this->base($top + $padT, 'hb', $hs, $hs * 1.45);
                $this->pdf->text($this->x0 + 8, $base, $title, ['font' => 'hb', 'size' => $hs, 'color' => $color]);
                if ($t['heading_row'] === 'underline') {
                    $tw = $this->pdf->textWidth($title, 'hb', $hs);
                    $this->pdf->line($this->x0 + 8, $base + 3.2, $this->x0 + 8 + $tw, $base + 3.2, ['color' => $this->c['accent2'], 'width' => 1]);
                }
                $this->y = $top + $h;
                continue;
            }
            if ($r['kind'] === 'section_total') {
                if (!$t['section_totals']) continue;
                $ss = $this->fs(8);
                $h = 4 + $ss * 1.45 + 6;
                $this->room($h);
                $amountCol = end($cols['defs']);
                $base = $this->base($this->y + 4, 'b', $ss, $ss * 1.45);
                $label = $r['title'] !== '' ? $r['title'] . ' · ' . $this->L['section_total'] : $this->L['section_total'];
                $this->pdf->text($amountCol['x'] + $amountCol['w'] - $amountCol['padR'], $base, $this->money($r['net']), ['font' => 'bb', 'size' => $ss, 'color' => $this->c['heading'], 'align' => 'right']);
                $this->pdf->text($amountCol['x'] - 6, $base, $this->pdf->fit($label, 'b', $ss, $amountCol['x'] - $this->x0 - 20), ['font' => 'b', 'size' => $ss, 'color' => $this->c['muted'], 'align' => 'right']);
                $this->y += $h;
                continue;
            }

            $n++;
            $stripe++;
            $parts = explode("\n", str_replace("\r", '', $r['description']), 2);
            $main = $this->pdf->wrap($parts[0], 'b', $size, $descW);
            $sub = isset($parts[1]) && trim($parts[1]) !== '' ? $this->pdf->wrap(trim($parts[1]), 'b', $subSize, $descW) : [];
            // Lay the description out as a list of [font, size, lh, text, extraTop]
            $flow = [];
            foreach ($main as $l) $flow[] = ['b', $size, $lh, $l, 0.0, $this->c['heading']];
            foreach ($sub as $i => $l) $flow[] = ['b', $subSize, $subLh, $l, $i === 0 ? 2.0 : 0.0, $this->c['muted']];

            $first = true;
            while ($flow) {
                // How many description lines fit on this page?
                $avail = $this->limit - $this->y - 2 * $pad;
                $take = 0;
                $hh = 0.0;
                foreach ($flow as $f) {
                    if ($hh + $f[2] + $f[4] > $avail && $take > 0) break;
                    $hh += $f[2] + $f[4];
                    $take++;
                }
                $rowH = 2 * $pad + max($hh, $lh);
                if ($first && $this->y + $rowH > $this->limit && $this->y > $this->M + 40) {
                    // whole row does not fit and we are not at the top of a page: move it
                    $closeBox();
                    $this->room($this->limit); // forces a new page with the head
                    continue;
                }
                $top = $this->y;
                if ($t['style'] === 'striped' && $stripe % 2 === 0) {
                    $this->pdf->rect($this->x0, $top, $this->x1 - $this->x0, $rowH, ['fill' => $this->c['stripe']]);
                }
                // description lines
                $yy = $top + $pad;
                foreach (array_slice($flow, 0, $take) as [$font, $fsz, $flh, $text, $extra, $color]) {
                    $yy += $extra;
                    $this->pdf->text($descCol['x'] + $descCol['padL'], $this->base($yy, $font, $fsz, $flh), $text, ['font' => $font, 'size' => $fsz, 'color' => $color]);
                    $yy += $flh;
                }
                if ($first) {
                    $base = $this->base($top + $pad, 'b', $size, $lh);
                    foreach ($cols['defs'] as $col) {
                        if ($col['key'] === 'desc') continue;
                        [$val, $font, $color] = match ($col['key']) {
                            'num' => [(string)$n, 'b', $this->c['muted']],
                            'qty' => [QiMoney::qty($r['qty']) . ($cols['unitInQty'] && $r['unit'] !== '' && strtolower($r['unit']) !== 'ea' ? ' ' . $r['unit'] : ''), 'b', $this->c['text']],
                            'unit' => [$r['unit'] !== '' ? $r['unit'] : '—', 'b', $this->c['text']],
                            'price' => [$this->money($r['price'], false), 'b', $this->c['text']],
                            'discount' => [$r['discount'] > 0.004 ? '−' . $this->money($r['discount'], false) : '—', 'b', $this->c['text']],
                            'vat' => [QiMoney::rate($r['rate']) . '%', 'b', $this->c['text']],
                            'amount' => [$this->money($r['net'], false), 'bb', $this->c['heading']],
                        };
                        $tx = $col['align'] === 'right' ? $col['x'] + $col['w'] - $col['padR'] : ($col['align'] === 'center' ? $col['x'] + $col['w'] / 2 : $col['x'] + $col['padL']);
                        $this->pdf->text($tx, $base, $this->pdf->fit($val, $font, $size, $col['w'] - $col['padL'] - $col['padR']), ['font' => $font, 'size' => $size, 'color' => $color, 'align' => $col['align']]);
                    }
                }
                // row rules
                if ($t['style'] === 'lines') {
                    $this->pdf->line($this->x0, $top + $rowH, $this->x1, $top + $rowH, ['color' => $this->c['rule'], 'width' => 0.6]);
                } elseif ($boxed) {
                    $this->pdf->line($this->x0, $top, $this->x1, $top, ['color' => $this->c['rule'], 'width' => 0.6]);
                    foreach ($cols['defs'] as $i => $col) {
                        if ($i === 0) continue;
                        $this->pdf->line($col['x'], $top, $col['x'], $top + $rowH, ['color' => $this->c['rule'], 'width' => 0.6]);
                    }
                }
                $this->y = $top + $rowH;
                $flow = array_slice($flow, $take);
                $first = false;
                if ($flow) {
                    $closeBox();
                    $this->room($this->limit);
                }
            }
        }
        $closeBox();
        $this->tableCols = null;
    }

    // ============================================================== totals

    private function totals(): void
    {
        $rows = DocHtml::totalRows($this->m, $this->d);
        $style = $this->d['totals']['style'];
        $ts = $this->fs(8.8);
        $rowH = 3.5 + $ts * 1.45 + 3.5;
        $gs = $this->fs(15);
        $grandH = 8 + $gs * 1.45 + 8;
        $h = 0;
        foreach ($rows as $r) {
            $h += $r['class'] === 'is-grand' ? $grandH + 5 + ($style === 'band' ? 2 : 0) : ($r['class'] === 'is-balance' ? $rowH + 6 : $rowH);
        }
        if ($style === 'box') $h += 14;

        $notesW = ($this->x1 - $this->x0) * 0.52;
        $showNotes = $this->d['sections']['notes'] && $this->m['notes'] !== '';
        $notesH = $showNotes ? $this->eyebrowH() + $this->paraHeight($notesW, $this->m['notes'], 'b', $this->fs(8.5), 1.55) : 0;

        $this->y += 14;
        $this->room(max($h, $notesH));
        $top = $this->y;
        if ($showNotes) {
            $yy = $top + $this->eyebrow($this->x0, $top, $this->L['notes']);
            $this->para($this->x0, $yy, $notesW, $this->m['notes'], 'b', $this->fs(8.5), $this->c['text'], 1.55);
        }

        $w = 216.0;
        $x = $this->x1 - $w;
        $r = (float)$this->d['table']['radius'];
        $y = $top;
        if ($style === 'box') {
            $this->pdf->rect($x, $top, $w, $h, ['fill' => $this->c['soft'], 'radius' => $r]);
            $y += 7;
            $x += 4;
            $w -= 8;
        }
        foreach ($rows as $row) {
            $cls = $row['class'];
            if ($cls === 'is-grand') {
                $y += 5;
                if ($style === 'band') {
                    $y += 2;
                    $this->pdf->rect($x, $y, $w, $grandH + 2, ['fill' => $this->c['accent'], 'radius' => $r]);
                    $lc = $vc = $this->c['total_text'];
                    $padX = 10;
                } else {
                    $this->pdf->line($x, $y, $x + $w, $y, ['color' => $style === 'box' ? $this->c['soft_text'] : $this->c['heading'], 'width' => 1]);
                    $lc = $this->c['heading'];
                    $vc = $this->c['accent'];
                    $padX = 8;
                }
                $lsz = $this->fs(10.5);
                $mid = $y + ($style === 'band' ? $grandH + 2 : $grandH) / 2;
                [$a1, $d1] = $this->pdf->metrics('hb', $lsz);
                [$a2, $d2] = $this->pdf->metrics('hb', $gs);
                $this->pdf->text($x + $padX, $mid + ($a1 - $d1) / 2, $row['label'], ['font' => 'hb', 'size' => $lsz, 'color' => $lc]);
                $this->pdf->text($x + $w - $padX, $mid + ($a2 - $d2) / 2, $this->money($row['value']), ['font' => 'hb', 'size' => $gs, 'color' => $vc, 'align' => 'right']);
                $y += $grandH + ($style === 'band' ? 2 : 0);
                continue;
            }
            if ($cls === 'is-balance') {
                $y += 3;
                $this->pdf->line($x, $y, $x + $w, $y, ['color' => $this->c['rule'], 'width' => 0.6, 'dash' => [2, 1.6]]);
                $y += 3;
                $bs = $this->fs(10);
                $base = $this->base($y + 3.5, 'bb', $bs, $ts * 1.45);
                $this->pdf->text($x + 8, $base, $row['label'], ['font' => 'bb', 'size' => $bs, 'color' => $this->c['accent']]);
                $this->pdf->text($x + $w - 8, $base, $this->money($row['value']), ['font' => 'bb', 'size' => $bs, 'color' => $this->c['accent'], 'align' => 'right']);
                $y += $rowH;
                continue;
            }
            $base = $this->base($y + 3.5, 'b', $ts, $ts * 1.45);
            $valColor = $cls === 'is-discount' ? $this->c['accent'] : ($cls === 'is-paid' ? $this->c['muted'] : $this->c['heading']);
            $this->pdf->text($x + 8, $base, $row['label'], ['font' => 'b', 'size' => $ts, 'color' => $this->c['muted']]);
            $this->pdf->text($x + $w - 8, $base, $this->money($row['value']), ['font' => $cls === 'is-paid' ? 'b' : 'bb', 'size' => $ts, 'color' => $valColor, 'align' => 'right']);
            $y += $rowH;
        }
        $this->y = max($top + $h, $top + $notesH);
    }

    private function eyebrowH(): float
    {
        return $this->fs(6.8) * 1.45 + 5;
    }

    // =============================================================== cards

    private function cards(): void
    {
        $cards = [];
        $bank = $this->m['company']['bank'];
        if ($this->d['sections']['payment'] && $this->m['type'] !== 'credit_note' && ($bank['account'] !== '' || $bank['name'] !== '')) {
            $kv = [];
            foreach ([['Bank', $bank['name']], ['Account holder', $bank['holder']], ['Account no.', $bank['account']], ['Branch code', $bank['branch']], ['Reference', $this->m['number']]] as [$k, $v]) {
                if (trim((string)$v) !== '') $kv[] = [$k, (string)$v];
            }
            $cards[] = ['kind' => 'kv', 'eyebrow' => $this->L['payment'], 'kv' => $kv];
        }
        $link = DocHtml::onlineLink($this->m, $this->d);
        if ($link) $cards[] = ['kind' => 'cta', 'eyebrow' => $link['eyebrow'], 'title' => $link['title'], 'url' => $link['url']];
        if (!$cards) return;

        $gap = 14;
        $cw = (($this->x1 - $this->x0) - $gap * (count($cards) - 1)) / count($cards);
        $ks = $this->fs(8.3);
        $heights = [];
        foreach ($cards as $card) {
            if ($card['kind'] === 'kv') $heights[] = 22 + $this->eyebrowH() + count($card['kv']) * ($ks * 1.45 + 2);
            else $heights[] = 22 + $this->eyebrowH() + $this->fs(12) * 1.45 + 3 + $this->fs(7.5) * 1.45 * count($this->pdf->wrap(DocHtml::shortUrl($card['url']), 'b', $this->fs(7.5), $cw - 26));
        }
        $h = max($heights);
        $this->y += 22;
        $this->room($h);
        $top = $this->y;
        $r = (float)$this->d['table']['radius'];
        foreach ($cards as $i => $card) {
            $x = $this->x0 + $i * ($cw + $gap);
            if ($card['kind'] === 'cta') {
                $this->pdf->rect($x, $top, $cw, $h, ['fill' => $this->c['soft'], 'radius' => $r]);
                $this->pdf->link($x, $top, $cw, $h, $card['url']);
            } else {
                $this->pdf->rect($x, $top, $cw, $h, ['stroke' => $this->c['rule'], 'width' => 0.75, 'radius' => $r]);
            }
            $yy = $top + 11 + $this->eyebrow($x + 13, $top + 11, $card['eyebrow']);
            if ($card['kind'] === 'kv') {
                $kw = 0;
                foreach ($card['kv'] as [$k]) $kw = max($kw, $this->pdf->textWidth($k, 'b', $ks));
                foreach ($card['kv'] as [$k, $v]) {
                    $base = $this->base($yy, 'b', $ks, $ks * 1.45);
                    $this->pdf->text($x + 13, $base, $k, ['font' => 'b', 'size' => $ks, 'color' => $this->c['muted']]);
                    $this->pdf->text($x + 13 + $kw + 12, $base, $this->pdf->fit($v, 'bb', $ks, $cw - 26 - $kw - 12), ['font' => 'bb', 'size' => $ks, 'color' => $this->c['heading']]);
                    $yy += $ks * 1.45 + 2;
                }
            } else {
                $ts = $this->fs(12);
                $this->t($x + 13, $yy, $card['title'] . ' →', 'hb', $ts, $this->c['soft_text']);
                $yy += $ts * 1.45 + 3;
                $this->para($x + 13, $yy, $cw - 26, DocHtml::shortUrl($card['url']), 'b', $this->fs(7.5), $this->c['muted']);
            }
        }
        $this->y = $top + $h;
    }

    // ============================================================== blocks

    /** A titled block that is kept together when it fits on a page; returns false when skipped. */
    private function block(string $title, callable $measure, callable $draw): void
    {
        $h = $this->eyebrowH() + $measure();
        $this->y += 20;
        if ($h <= $this->limit - $this->M) $this->room($h);
        else $this->room($this->eyebrowH() + 40);
        $this->y += $this->eyebrow($this->x0, $this->y, $title);
        $draw();
    }

    private function mini(array $rows, array $align): void
    {
        $ms = $this->fs(8.3);
        $rowH = 4 + $ms * 1.45 + 4;
        $n = count($align);
        $cw = ($this->x1 - $this->x0);
        // first column flexible, others share the rest
        $widths = $n === 5 ? [0.38, 0.12, 0.17, 0.18, 0.15] : [0.2, 0.22, 0.34, 0.24];
        foreach ($rows as $cells) {
            $this->room($rowH);
            $x = $this->x0;
            $base = $this->base($this->y + 4, 'b', $ms, $ms * 1.45);
            foreach ($cells as $i => [$text, $color, $font]) {
                $w = $cw * $widths[$i];
                $padL = $i === 0 ? 0 : 6;
                $padR = $i === $n - 1 ? 0 : 6;
                $tx = $align[$i] === 'right' ? $x + $w - $padR : $x + $padL;
                $this->pdf->text($tx, $base, $this->pdf->fit($text, $font, $ms, $w - $padL - $padR), ['font' => $font, 'size' => $ms, 'color' => $color, 'align' => $align[$i]]);
                $x += $w;
            }
            $this->pdf->line($this->x0, $this->y + $rowH, $this->x1, $this->y + $rowH, ['color' => $this->c['rule'], 'width' => 0.6]);
            $this->y += $rowH;
        }
    }

    private function schedule(): void
    {
        if (!$this->d['sections']['milestones'] || !$this->m['milestones']) return;
        $ms = $this->m['milestones'];
        $rowH = 8 + $this->fs(8.3) * 1.45;
        $this->block($this->L['schedule'], fn() => count($ms) * $rowH, function () use ($ms) {
            $rows = [];
            foreach ($ms as $m) {
                $statusColor = $m['status'] === 'paid' ? '#2f7457' : (in_array($m['status'], ['due', 'overdue'], true) ? $this->c['accent'] : $this->c['text']);
                $rows[] = [[$m['label'], $this->c['heading'], 'b'], [QiMoney::rate($m['percentage']) . '%', $this->c['text'], 'b'],
                           [$m['due_date'] ? DocHtml::date($m['due_date']) : '', $this->c['text'], 'b'], [$this->money($m['amount']), $this->c['text'], 'b'],
                           [ucfirst($m['status']), $statusColor, in_array($m['status'], ['paid', 'due', 'overdue'], true) ? 'bb' : 'b']];
            }
            $this->mini($rows, ['left', 'right', 'left', 'right', 'right']);
        });
    }

    private function paymentsBlock(): void
    {
        if ($this->m['type'] !== 'invoice' || !$this->d['sections']['payments'] || (!$this->m['payments'] && !$this->m['credits'])) return;
        $all = [];
        foreach ($this->m['payments'] as $p) $all[] = [[DocHtml::date($p['date']), $this->c['heading'], 'b'], [$p['method'], $this->c['text'], 'b'], [$p['reference'], $this->c['text'], 'b'], [$this->money($p['amount']), $this->c['text'], 'b']];
        foreach ($this->m['credits'] as $cr) $all[] = [[DocHtml::date($cr['date']), $this->c['heading'], 'b'], ['Credit note', $this->c['text'], 'b'], [$cr['number'], $this->c['text'], 'b'], [$this->money($cr['amount']), $this->c['text'], 'b']];
        $rowH = 8 + $this->fs(8.3) * 1.45;
        $this->block($this->L['payments'], fn() => count($all) * $rowH, fn() => $this->mini($all, ['left', 'left', 'left', 'right']));
    }

    private function textBlock(string $title, string $text): void
    {
        $size = $this->fs(8.5);
        $w = $this->x1 - $this->x0;
        $this->block($title, fn() => $this->paraHeight($w, $text, 'b', $size, 1.6), function () use ($text, $size, $w) {
            $line = $size * 1.6;
            foreach ($this->pdf->wrap($text, 'b', $size, $w) as $l) {
                $this->room($line);
                $this->pdf->text($this->x0, $this->base($this->y, 'b', $size, $line), $l, ['font' => 'b', 'size' => $size, 'color' => $this->c['text']]);
                $this->y += $line;
            }
        });
    }

    private function reason(): void
    {
        if ($this->m['type'] === 'credit_note' && ($this->m['reason'] ?? '') !== '') $this->textBlock('Reason', $this->m['reason']);
    }

    private function terms(): void
    {
        if ($this->d['sections']['terms'] && $this->m['terms'] !== '') $this->textBlock($this->L['terms'], $this->m['terms']);
    }

    private function signature(): void
    {
        if ($this->m['type'] !== 'quote' || !$this->d['sections']['signature']) return;
        $this->y += 30;
        $this->room(46);
        $acc = $this->m['accepted'] ?? null;
        $cw = $this->x1 - $this->x0;
        if ($acc) {
            $s = $this->fs(9);
            $h = 20 + $s * 1.45;
            $this->pdf->rect($this->x0, $this->y, $cw, $h, ['fill' => '#2f7457', 'opacity' => 0.09, 'radius' => (float)$this->d['table']['radius']]);
            $who = $acc['name'] !== '' ? $acc['name'] : 'the customer';
            $this->t($this->x0 + 13, $this->y + 10, $this->L['accept'] . ' ' . $who . ' · ' . DocHtml::date(substr((string)$acc['at'], 0, 10)), 'bb', $s, '#2f7457');
            $this->y += $h;
            return;
        }
        $lw = ($cw - 30) / 2;
        foreach ([$this->L['accept'], $this->L['date']] as $i => $label) {
            $x = $this->x0 + $i * ($lw + 30);
            $this->pdf->line($x, $this->y + 26, $x + $lw, $this->y + 26, ['color' => $this->c['heading'], 'width' => 0.75]);
            $ls = $this->fs(7.5);
            $this->pdf->text($x, $this->base($this->y + 30, 'b', $ls, $ls * 1.45), mb_strtoupper($label), ['font' => 'b', 'size' => $ls, 'color' => $this->c['muted'], 'spacing' => $ls * 0.06]);
        }
        $this->y += 30 + $this->fs(7.5) * 1.45 + 4;
    }

    private function footer(): void
    {
        $text = $this->doc['footer'];
        if ($text === '') return;
        $size = $this->fs(7.6);
        $w = $this->x1 - $this->x0;
        $h = 10 + $this->paraHeight($w, $text, 'b', $size, 1.55);
        $this->y += 28;
        $this->room($h);
        $this->pdf->line($this->x0, $this->y, $this->x1, $this->y, ['color' => $this->c['rule'], 'width' => 0.6]);
        $this->para($this->x0, $this->y + 10, $w, $text, 'b', $size, $this->c['muted'], 1.55, 'center');
        $this->y += $h;
    }

    // ============================================================ finishing

    private function stamp(): void
    {
        if (!$this->d['stamp']['status']) return;
        $stamp = $this->m['status']['stamp'] ?? null;
        if (!$stamp) return;
        $key = strtolower(str_replace(' ', '-', $stamp));
        $color = in_array($key, ['paid', 'accepted'], true) ? '#2f7457' : (in_array($key, ['void', 'declined', 'cancelled', 'written-off'], true) ? '#a8281e' : ($key === 'refunded' ? '#9a6512' : '#85766b'));
        $opacity = in_array($key, ['paid', 'accepted'], true) ? 0.26 : 0.22;
        $this->pdf->setPage(0);
        $size = 27.0;
        $text = mb_strtoupper($stamp);
        $sp = $size * 0.12;
        $tw = $this->pdf->textWidth($text, 'hb', $size, $sp);
        $bw = $tw + 28;
        $bh = $size * 1.2 + 8;
        $right = $this->W - 46;
        $top = in_array($this->d['header']['layout'], ['banner', 'band'], true) ? 232 : 150;
        $cx = $right - $bw / 2;
        $cy = $top + $bh / 2;
        $this->pdf->beginRotation(14, $cx, $cy);
        $this->pdf->rect($right - $bw, $top, $bw, $bh, ['stroke' => $color, 'width' => 2.2, 'radius' => 8, 'opacity' => $opacity]);
        [$a, $d] = $this->pdf->metrics('hb', $size);
        $this->pdf->text($cx, $cy + ($a - $d) / 2, $text, ['font' => 'hb', 'size' => $size, 'color' => $color, 'spacing' => $sp, 'align' => 'center', 'opacity' => $opacity]);
        $this->pdf->endTransform();
        $this->pdf->setPage($this->pdf->pageCount() - 1);
    }

    private function pageNumbers(): void
    {
        $n = $this->pdf->pageCount();
        if (!$this->d['footer']['page_numbers'] || $n < 2) return;
        $size = 7.2;
        for ($i = 0; $i < $n; $i++) {
            $this->pdf->setPage($i);
            $label = $this->doc['title'] . ' ' . $this->m['number'] . '  ·  Page ' . ($i + 1) . ' of ' . $n;
            $this->pdf->text($this->x1, $this->H - $this->M / 2, $label, ['font' => 'b', 'size' => $size, 'color' => $this->c['muted'], 'align' => 'right']);
        }
    }
}
