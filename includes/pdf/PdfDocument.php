<?php
/**
 * PdfDocument — a small, dependency-free PDF 1.7 writer with real fonts.
 *
 * Draws text in embedded TrueType fonts (subset per document, Unicode via
 * Identity-H + ToUnicode, so text copies and searches correctly), with a
 * per-character fallback font for glyphs the main font lacks. Shapes with
 * rounded corners, lines, circles, opacity, rotation, images (anything GD
 * reads: PNG, JPEG, GIF, WebP — transparency kept as a soft mask) and link
 * annotations.
 *
 * Coordinates are points from the TOP-LEFT of the page (y grows downwards);
 * text y is the baseline. Colours are '#rgb' / '#rrggbb'.
 */

require_once __DIR__ . '/TtfFont.php';

final class PdfDocument
{
    public const SIZES = ['A4' => [595.28, 841.89], 'Letter' => [612.0, 792.0]];

    public float $width;
    public float $height;

    private array $info;
    /** @var array<string, array{font:TtfFont, res:string, used:array<int,int>}> */
    private array $fonts = [];
    private ?string $fallback = null;
    /** @var array<int, array{content:string, annots:array}> */
    private array $pages = [];
    private int $page = -1;
    /** @var array<string, array{res:string, w:int, h:int, rgb:string, alpha:?string}> */
    private array $images = [];
    /** @var array<string, string> opacity key => resource name */
    private array $gstates = [];

    public function __construct(string $size = 'A4', array $info = [])
    {
        [$this->width, $this->height] = self::SIZES[$size] ?? self::SIZES['A4'];
        $this->info = $info;
    }

    // ------------------------------------------------------------------ fonts

    /** Register a font file under a key (e.g. 'body', 'body-bold'). */
    public function addFont(string $key, string $path): void
    {
        if (isset($this->fonts[$key])) return;
        $this->fonts[$key] = ['font' => TtfFont::load($path), 'res' => 'F' . (count($this->fonts) + 1), 'used' => []];
    }

    public function hasFont(string $key): bool
    {
        return isset($this->fonts[$key]);
    }

    /** Font used for characters the requested font cannot draw. */
    public function setFallbackFont(string $path): void
    {
        $this->addFont('__fallback', $path);
        $this->fallback = '__fallback';
    }

    public function font(string $key): TtfFont
    {
        return $this->fonts[$key]['font'] ?? $this->fonts[array_key_first($this->fonts)]['font'];
    }

    /** Width of $text in points. */
    public function textWidth(string $text, string $font, float $size, float $spacing = 0.0): float
    {
        $w = 0.0;
        $n = 0;
        foreach ($this->runs($text, $font) as [$key, $gids]) {
            $f = $this->fonts[$key]['font'];
            foreach ($gids as $gid) { $w += $f->advance($gid); $n++; }
        }
        return $w * $size / 1000 + max(0, $n - 1) * $spacing;
    }

    /** Ascent (above baseline) and descent (below, positive) for a font size, in points. */
    public function metrics(string $font, float $size): array
    {
        $f = $this->font($font);
        return [$f->scale($f->ascent) * $size / 1000, -$f->scale($f->descent) * $size / 1000, $f->scale($f->capHeight) * $size / 1000];
    }

    /**
     * Break text into lines that fit $maxWidth. Honours hard line breaks;
     * words longer than a line are split.
     *
     * @return string[]
     */
    public function wrap(string $text, string $font, float $size, float $maxWidth, float $spacing = 0.0): array
    {
        $out = [];
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/(?<=\s)/u', $para) ?: [$para];
            $line = '';
            foreach ($words as $word) {
                $try = $line . $word;
                if ($line !== '' && $this->textWidth(rtrim($try), $font, $size, $spacing) > $maxWidth) {
                    $out[] = rtrim($line);
                    $line = '';
                    $try = $word;
                }
                // A single word wider than the line: hard-split it
                while ($this->textWidth(rtrim($try), $font, $size, $spacing) > $maxWidth && mb_strlen(rtrim($try)) > 1) {
                    $cut = mb_strlen($try);
                    while ($cut > 1 && $this->textWidth(mb_substr($try, 0, $cut), $font, $size, $spacing) > $maxWidth) $cut--;
                    $out[] = mb_substr($try, 0, $cut);
                    $try = mb_substr($try, $cut);
                }
                $line = $try;
            }
            $out[] = rtrim($line);
        }
        return $out;
    }

    /** Shorten text with an ellipsis so it fits $maxWidth. */
    public function fit(string $text, string $font, float $size, float $maxWidth, float $spacing = 0.0): string
    {
        if ($this->textWidth($text, $font, $size, $spacing) <= $maxWidth) return $text;
        $len = mb_strlen($text);
        while ($len > 0 && $this->textWidth(mb_substr($text, 0, $len) . '…', $font, $size, $spacing) > $maxWidth) $len--;
        return rtrim(mb_substr($text, 0, $len)) . '…';
    }

    // ------------------------------------------------------------------ pages

    public function addPage(): int
    {
        $this->pages[] = ['content' => '', 'annots' => []];
        return $this->page = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Switch drawing to an existing page (0-based), e.g. to stamp page numbers. */
    public function setPage(int $index): void
    {
        if (isset($this->pages[$index])) $this->page = $index;
    }

    // --------------------------------------------------------------- drawing

    /**
     * Draw one line of text. $opt: font, size, color, spacing (letter
     * spacing, pt), align (left|right|center — x is then the right edge /
     * centre), opacity, rotate (degrees, around x/y).
     */
    public function text(float $x, float $y, string $text, array $opt = []): float
    {
        $font = $opt['font'] ?? array_key_first($this->fonts);
        $size = (float)($opt['size'] ?? 10);
        $spacing = (float)($opt['spacing'] ?? 0);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        if ($text === '') return 0.0;
        $width = $this->textWidth($text, $font, $size, $spacing);
        $align = $opt['align'] ?? 'left';
        if ($align === 'right') $x -= $width;
        elseif ($align === 'center') $x -= $width / 2;

        $s = 'q ' . $this->gs($opt['opacity'] ?? null) . $this->fillColor($opt['color'] ?? '#000000') . ' BT ';
        if (!empty($opt['rotate'])) {
            $a = deg2rad((float)$opt['rotate']);
            $s .= sprintf('%.4F %.4F %.4F %.4F %.2F %.2F Tm ', cos($a), sin($a), -sin($a), cos($a), $x, $this->height - $y);
        } else {
            $s .= sprintf('1 0 0 1 %.2F %.2F Tm ', $x, $this->height - $y);
        }
        if ($spacing) $s .= sprintf('%.3F Tc ', $spacing);
        foreach ($this->runs($text, $font) as [$key, $gids]) {
            $res = $this->fonts[$key]['res'];
            $hex = '';
            foreach ($gids as $gid) $hex .= sprintf('%04X', $gid);
            $s .= sprintf('/%s %.2F Tf <%s> Tj ', $res, $size, $hex);
        }
        $this->out($s . "ET Q\n");
        return $width;
    }

    /** Filled and/or stroked rectangle; 'radius' rounds the corners (number or [tl, tr, br, bl]). */
    public function rect(float $x, float $y, float $w, float $h, array $opt = []): void
    {
        if ($w <= 0 || $h <= 0) return;
        $r = $opt['radius'] ?? 0;
        $radii = is_array($r) ? array_pad(array_map('floatval', $r), 4, 0.0) : array_fill(0, 4, (float)$r);
        $max = min($w, $h) / 2;
        $radii = array_map(fn($v) => max(0.0, min($max, $v)), $radii);
        $path = array_sum($radii) > 0 ? $this->roundedPath($x, $y, $w, $h, $radii)
            : sprintf('%.2F %.2F %.2F %.2F re ', $x, $this->height - $y - $h, $w, $h);
        $this->paint($path, $opt);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $opt = []): void
    {
        $s = 'q ' . $this->gs($opt['opacity'] ?? null) . $this->strokeColor($opt['color'] ?? '#000000')
           . sprintf(' %.2F w ', (float)($opt['width'] ?? 0.5));
        if (!empty($opt['dash'])) $s .= '[' . implode(' ', array_map(fn($v) => sprintf('%.2F', $v), (array)$opt['dash'])) . '] 0 d ';
        if (!empty($opt['cap'])) $s .= '1 J ';
        $s .= sprintf('%.2F %.2F m %.2F %.2F l S Q', $x1, $this->height - $y1, $x2, $this->height - $y2);
        $this->out($s . "\n");
    }

    public function circle(float $cx, float $cy, float $r, array $opt = []): void
    {
        $k = 0.5523 * $r;
        $y = $this->height - $cy;
        $p = sprintf('%.2F %.2F m ', $cx + $r, $y)
           . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r)
           . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y)
           . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r)
           . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h ', $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);
        $this->paint($p, $opt);
    }

    /** Start a rotated drawing context around ($cx, $cy); close it with endTransform(). */
    public function beginRotation(float $deg, float $cx, float $cy): void
    {
        $a = deg2rad($deg);
        $py = $this->height - $cy;
        $cos = cos($a);
        $sin = sin($a);
        // translate(cx, py) · rotate(a) · translate(-cx, -py)
        $this->out(sprintf("q %.4F %.4F %.4F %.4F %.4F %.4F cm\n", $cos, $sin, -$sin, $cos,
            $cx - $cos * $cx + $sin * $py, $py - $sin * $cx - $cos * $py));
    }

    public function endTransform(): void
    {
        $this->out("Q\n");
    }

    /** A small diamond (lozenge), filled. */
    public function diamond(float $cx, float $cy, float $r, array $opt = []): void
    {
        $y = $this->height - $cy;
        $this->paint(sprintf('%.2F %.2F m %.2F %.2F l %.2F %.2F l %.2F %.2F l h ', $cx, $y + $r, $cx + $r, $y, $cx, $y - $r, $cx - $r, $y), $opt);
    }

    /**
     * Register an image (file path or raw bytes) once; returns its key, or
     * null when GD cannot read it. $maxPx caps the embedded resolution.
     */
    public function loadImage(string $source, int $maxPx = 1200): ?array
    {
        $key = md5($source) . $maxPx;
        if (isset($this->images[$key])) return ['key' => $key, 'w' => $this->images[$key]['w'], 'h' => $this->images[$key]['h']];
        if (!function_exists('imagecreatefromstring')) return null;
        $bytes = (strlen($source) < 1024 && is_file($source)) ? @file_get_contents($source) : $source;
        if (!is_string($bytes) || $bytes === '') return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 25000000) return null;
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = min(1.0, $maxPx / max($w, $h));
        if ($scale < 1.0) {
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $dst;
            $w = $nw;
            $h = $nh;
        } elseif (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $c = imagecolorat($im, $px, $py);
                $a = ($c >> 24) & 0x7F;
                $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $alpha .= chr(255 - (int)round($a * 255 / 127));
                if ($a) $hasAlpha = true;
            }
        }
        imagedestroy($im);
        $this->images[$key] = ['res' => 'Im' . (count($this->images) + 1), 'w' => $w, 'h' => $h, 'rgb' => $rgb, 'alpha' => $hasAlpha ? $alpha : null];
        return ['key' => $key, 'w' => $w, 'h' => $h];
    }

    public function image(string $key, float $x, float $y, float $w, float $h, ?float $opacity = null): void
    {
        if (!isset($this->images[$key])) return;
        $this->out(sprintf("q %s%.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $this->gs($opacity), $w, $h, $x, $this->height - $y - $h, $this->images[$key]['res']));
    }

    /** Clickable area that opens a URL. */
    public function link(float $x, float $y, float $w, float $h, string $url): void
    {
        if ($this->page < 0 || !preg_match('#^(https?://|mailto:)#i', $url)) return;
        $this->pages[$this->page]['annots'][] = [$x, $this->height - $y - $h, $x + $w, $this->height - $y, $url];
    }

    // ----------------------------------------------------------------- output

    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $objects = [];
        $add = function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
        $reserve = function () use (&$objects): int { $objects[] = null; return count($objects); };

        $catalogId = $reserve();
        $pagesId = $reserve();

        // Fonts (subset to the glyphs actually drawn)
        $fontRefs = [];
        foreach ($this->fonts as $key => $f) {
            if (!$f['used']) continue;
            $fontRefs[$f['res']] = $this->writeFont($f, $add);
        }
        $xobj = [];
        foreach ($this->images as $img) {
            $smask = '';
            if ($img['alpha'] !== null) {
                $data = gzcompress($img['alpha'], 6);
                $sid = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $img['w'], $img['h'], strlen($data), $data));
                $smask = " /SMask $sid 0 R";
            }
            $data = gzcompress($img['rgb'], 6);
            $xobj[$img['res']] = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8%s /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                $img['w'], $img['h'], $smask, strlen($data), $data));
        }
        $res = '<< /ProcSet [/PDF /Text /ImageB /ImageC]';
        if ($fontRefs) $res .= ' /Font << ' . implode(' ', array_map(fn($n, $id) => "/$n $id 0 R", array_keys($fontRefs), $fontRefs)) . ' >>';
        if ($xobj) $res .= ' /XObject << ' . implode(' ', array_map(fn($n, $id) => "/$n $id 0 R", array_keys($xobj), $xobj)) . ' >>';
        if ($this->gstates) {
            $res .= ' /ExtGState <<';
            foreach ($this->gstates as $opacity => $name) $res .= sprintf(' /%s << /Type /ExtGState /ca %.3F /CA %.3F >>', $name, (float)$opacity, (float)$opacity);
            $res .= ' >>';
        }
        $resId = $add($res . ' >>');

        $kids = [];
        foreach ($this->pages as $p) {
            $data = gzcompress($p['content'], 6);
            $contentId = $add(sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($data), $data));
            $annots = [];
            foreach ($p['annots'] as [$x1, $y1, $x2, $y2, $url]) {
                $annots[] = $add(sprintf('<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI %s >> >>', $x1, $y1, $x2, $y2, self::str($url)));
            }
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %d 0 R /Contents %d 0 R%s >>',
                $pagesId, $this->width, $this->height, $resId, $contentId, $annots ? ' /Annots [' . implode(' ', array_map(fn($i) => "$i 0 R", $annots)) . ']' : ''));
        }
        $objects[$pagesId - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn($i) => "$i 0 R", $kids)), count($kids));
        $objects[$catalogId - 1] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);

        $info = ['Producer' => 'Flowwork', 'CreationDate' => 'D:' . date('YmdHis')];
        foreach (['title' => 'Title', 'author' => 'Author', 'subject' => 'Subject', 'creator' => 'Creator'] as $k => $name) {
            if (!empty($this->info[$k])) $info[$name] = (string)$this->info[$k];
        }
        $infoId = $add('<< ' . implode(' ', array_map(fn($k, $v) => "/$k " . self::str($v), array_keys($info), $info)) . ' >>');

        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        $pdf .= sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $catalogId, $infoId, $xref);
        return $pdf;
    }

    // --------------------------------------------------------------- internals

    private function out(string $s): void
    {
        if ($this->page < 0) $this->addPage();
        $this->pages[$this->page]['content'] .= $s;
    }

    /**
     * Split text into [fontKey, glyphIds] runs: each character uses the
     * requested font when it has the glyph, else the fallback font.
     */
    private function runs(string $text, string $key): array
    {
        if (!isset($this->fonts[$key])) $key = array_key_first($this->fonts);
        $primary = $this->fonts[$key]['font'];
        $fb = $this->fallback !== null ? $this->fonts[$this->fallback]['font'] : null;
        $runs = [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) continue;
            if ($cp === 0xA0) $cp = 0x20;
            $useKey = $key;
            $gid = $primary->glyphId($cp);
            if ($gid === 0 && $fb !== null && $fb->hasGlyph($cp)) {
                $useKey = $this->fallback;
                $gid = $fb->glyphId($cp);
            }
            if ($gid === 0) {
                $gid = $primary->glyphId(0x3F); // '?'
                $useKey = $key;
            }
            $this->fonts[$useKey]['used'][$gid] = $cp;
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][0] === $useKey) $runs[$last][1][] = $gid;
            else $runs[] = [$useKey, [$gid]];
        }
        return $runs;
    }

    private function writeFont(array $f, callable $add): int
    {
        /** @var TtfFont $font */
        $font = $f['font'];
        $used = $f['used'];
        ksort($used);
        $tag = '';
        $seed = crc32($font->postscriptName . implode(',', array_keys($used)));
        for ($i = 0; $i < 6; $i++) { $tag .= chr(65 + ($seed % 26)); $seed = intdiv($seed, 26) + 7 * $i; }
        $name = $tag . '+' . $font->postscriptName;

        $file = $font->strip(array_keys($used));
        $data = gzcompress($file, 6);
        $fileId = $add(sprintf("<< /Length %d /Length1 %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($data), strlen($file), $data));

        $flags = 32 | ($font->fixedPitch ? 1 : 0) | ($font->italicAngle != 0 ? 64 : 0);
        $bbox = array_map(fn($v) => (int)round($font->scale($v)), $font->bbox);
        $descId = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %.2F /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
            $name, $flags, $bbox[0], $bbox[1], $bbox[2], $bbox[3], $font->italicAngle,
            (int)round($font->scale($font->ascent)), (int)round($font->scale($font->descent)), (int)round($font->scale($font->capHeight)),
            $font->weight >= 600 ? 120 : 80, $fileId));

        $w = '';
        foreach ($used as $gid => $cp) $w .= sprintf('%d [%d] ', $gid, (int)round($font->advance($gid)));
        $cidId = $add(sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>',
            $name, $descId, trim($w)));

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($used, 100, true) as $chunk) {
            $cmap .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $cp) {
                $u = mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8');
                $cmap .= sprintf("<%04X> <%s>\n", $gid, strtoupper(bin2hex($u)));
            }
            $cmap .= "endbfchar\n";
        }
        $cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $cdata = gzcompress($cmap, 6);
        $toUni = $add(sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($cdata), $cdata));

        return $add(sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>', $name, $cidId, $toUni));
    }

    private function roundedPath(float $x, float $y, float $w, float $h, array $r): string
    {
        [$tl, $tr, $br, $bl] = $r;
        $k = 0.5523;
        $top = $this->height - $y;
        $bot = $top - $h;
        $p = sprintf('%.2F %.2F m ', $x + $tl, $top);
        $p .= sprintf('%.2F %.2F l ', $x + $w - $tr, $top);
        if ($tr) $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $w - $tr + $tr * $k, $top, $x + $w, $top - $tr + $tr * $k, $x + $w, $top - $tr);
        $p .= sprintf('%.2F %.2F l ', $x + $w, $bot + $br);
        if ($br) $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $w, $bot + $br - $br * $k, $x + $w - $br + $br * $k, $bot, $x + $w - $br, $bot);
        $p .= sprintf('%.2F %.2F l ', $x + $bl, $bot);
        if ($bl) $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $bl - $bl * $k, $bot, $x, $bot + $bl - $bl * $k, $x, $bot + $bl);
        $p .= sprintf('%.2F %.2F l ', $x, $top - $tl);
        if ($tl) $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x, $top - $tl + $tl * $k, $x + $tl - $tl * $k, $top, $x + $tl, $top);
        return $p . 'h ';
    }

    private function paint(string $path, array $opt): void
    {
        $fill = $opt['fill'] ?? null;
        $stroke = $opt['stroke'] ?? null;
        if ($fill === null && $stroke === null) $fill = '#000000';
        $s = 'q ' . $this->gs($opt['opacity'] ?? null);
        if ($fill !== null) $s .= $this->fillColor($fill) . ' ';
        if ($stroke !== null) $s .= $this->strokeColor($stroke) . sprintf(' %.2F w ', (float)($opt['width'] ?? 0.5));
        if (!empty($opt['dash'])) $s .= '[' . implode(' ', array_map(fn($v) => sprintf('%.2F', $v), (array)$opt['dash'])) . '] 0 d ';
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->out($s . $path . $op . " Q\n");
    }

    private function gs(?float $opacity): string
    {
        if ($opacity === null || $opacity >= 1) return '';
        $key = number_format(max(0.0, $opacity), 3, '.', '');
        $this->gstates[$key] ??= 'GS' . (count($this->gstates) + 1);
        return '/' . $this->gstates[$key] . ' gs ';
    }

    public static function rgb(string $hex): array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (!preg_match('/^[0-9a-fA-F]{6}/', $h)) $h = '000000';
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    private function fillColor(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        return sprintf('%.3F %.3F %.3F rg', $r, $g, $b);
    }

    private function strokeColor(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        return sprintf('%.3F %.3F %.3F RG', $r, $g, $b);
    }

    /** PDF text string: UTF-16BE with BOM when needed, escaped. */
    private static function str(string $s): string
    {
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
        }
        return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }
}
