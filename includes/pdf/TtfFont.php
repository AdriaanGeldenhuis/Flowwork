<?php
/**
 * TtfFont — reads a TrueType (glyf-outline) font file for PDF embedding.
 *
 * Gives the PDF writer what it needs to typeset real fonts instead of the 14
 * standard PDF fonts: glyph lookup (cmap formats 4 and 12), advance widths,
 * vertical metrics and the font descriptor values, plus two subsetting modes:
 *
 *  - strip(): keeps every glyph id but empties the outlines of glyphs that
 *    are not used, so the ids written into a page while it is laid out stay
 *    valid (CIDToGIDMap /Identity). Used for every PDF.
 *  - subset(): renumbers to only the requested characters and writes a new,
 *    smaller font file with its own cmap. Used once, to vendor the Latin
 *    subsets of the document fonts in includes/pdf/fonts/.
 *
 * CFF-flavoured OpenType (.otf, "OTTO") is not supported; TrueType only.
 */
final class TtfFont
{
    public string $path;
    public string $postscriptName = 'Font';
    public int $unitsPerEm = 1000;
    public array $bbox = [0, 0, 1000, 1000];
    public int $ascent = 800;
    public int $descent = -200;
    public int $lineGap = 0;
    public int $capHeight = 700;
    public float $italicAngle = 0.0;
    public bool $fixedPitch = false;
    public int $weight = 400;
    public int $numGlyphs = 0;

    private string $data;
    /** @var array<string, array{0:int,1:int}> tag => [offset, length] */
    private array $tables = [];
    /** @var int[] advance width per glyph id (font units) */
    private array $advances = [];
    /** @var array<int,int> code point => glyph id */
    private array $cmap = [];
    /** @var int[]|null glyph offsets into glyf (numGlyphs + 1) */
    private ?array $loca = null;

    /** @var array<string, TtfFont> */
    private static array $cache = [];

    public static function load(string $path): self
    {
        $real = realpath($path) ?: $path;
        return self::$cache[$real] ??= new self($real);
    }

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            throw new RuntimeException('Cannot read font ' . basename($path));
        }
        $this->path = $path;
        $this->data = $data;
        $version = substr($data, 0, 4);
        if ($version === 'OTTO') {
            throw new RuntimeException(basename($path) . ' is a CFF OpenType font; only TrueType outlines are supported');
        }
        if ($version !== "\x00\x01\x00\x00" && $version !== 'true') {
            throw new RuntimeException(basename($path) . ' is not a TrueType font');
        }
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $this->tables[substr($data, $rec, 4)] = [$this->u32($rec + 8), $this->u32($rec + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new RuntimeException(basename($path) . " has no $required table");
            }
        }
        $this->readHead();
        $this->readHhea();
        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
        $this->readHmtx();
        $this->readCmap();
        $this->readOs2();
        $this->readPost();
        $this->readName();
    }

    // ------------------------------------------------------------------ lookup

    public function glyphId(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }

    public function hasGlyph(int $codePoint): bool
    {
        return ($this->cmap[$codePoint] ?? 0) !== 0;
    }

    /** Advance width of a glyph in PDF glyph space (1/1000 em). */
    public function advance(int $gid): float
    {
        $w = $this->advances[$gid] ?? ($this->advances[count($this->advances) - 1] ?? 0);
        return $w * 1000 / $this->unitsPerEm;
    }

    /** @return array<int,int> code point => glyph id */
    public function cmap(): array
    {
        return $this->cmap;
    }

    public function scale(int $fontUnits): float
    {
        return $fontUnits * 1000 / $this->unitsPerEm;
    }

    // --------------------------------------------------------------- subsetting

    /**
     * The font with every glyph that is not in $keep emptied (ids unchanged).
     * Composite glyphs pull in their components. Hinting tables are kept, the
     * layout tables (GSUB, GPOS, kern …) are dropped: the PDF never uses them.
     *
     * @param int[] $keep glyph ids
     */
    public function strip(array $keep): string
    {
        $keep = $this->withComponents(array_merge([0], $keep));
        $flags = array_fill_keys($keep, true);
        $glyf = '';
        $loca = [];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca[] = strlen($glyf);
            if (isset($flags[$g])) {
                $glyf .= $this->glyphData($g);
                $glyf .= str_repeat("\0", (4 - strlen($glyf) % 4) % 4);
            }
        }
        $loca[] = strlen($glyf);
        return $this->build($glyf, $loca, null, null);
    }

    /**
     * A new, renumbered font containing only the glyphs for $codePoints (and
     * .notdef), with a cmap for them. Used to vendor compact font files.
     *
     * @param int[] $codePoints
     */
    public function subset(array $codePoints): string
    {
        $chars = [];
        foreach ($codePoints as $cp) {
            if (($gid = $this->cmap[$cp] ?? 0) !== 0) $chars[$cp] = $gid;
        }
        ksort($chars);
        $old = $this->withComponents(array_merge([0], array_values($chars)));
        sort($old);
        $map = array_flip($old); // old gid => new gid
        $glyf = '';
        $loca = [];
        foreach ($old as $g) {
            $loca[] = strlen($glyf);
            $glyf .= $this->remapComposite($this->glyphData($g), $map);
            $glyf .= str_repeat("\0", (4 - strlen($glyf) % 4) % 4);
        }
        $loca[] = strlen($glyf);
        $newCmap = [];
        foreach ($chars as $cp => $g) $newCmap[$cp] = $map[$g];
        $metrics = [];
        foreach ($old as $g) {
            $metrics[] = [$this->advances[$g] ?? end($this->advances), $this->lsb($g)];
        }
        return $this->build($glyf, $loca, $metrics, $newCmap);
    }

    // ---------------------------------------------------------------- internals

    private function u8(int $o): int { return ord($this->data[$o]); }
    private function u16(int $o): int { return unpack('n', $this->data, $o)[1]; }
    private function i16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int { return unpack('N', $this->data, $o)[1]; }

    private function table(string $tag): string
    {
        if (!isset($this->tables[$tag])) return '';
        [$off, $len] = $this->tables[$tag];
        return substr($this->data, $off, $len);
    }

    private function readHead(): void
    {
        $o = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($o + 18) ?: 1000;
        $this->bbox = [$this->i16($o + 36), $this->i16($o + 38), $this->i16($o + 40), $this->i16($o + 42)];
    }

    private function readHhea(): void
    {
        $o = $this->tables['hhea'][0];
        $this->ascent = $this->i16($o + 4);
        $this->descent = $this->i16($o + 6);
        $this->lineGap = $this->i16($o + 8);
    }

    private function readHmtx(): void
    {
        $n = $this->u16($this->tables['hhea'][0] + 34);
        $o = $this->tables['hmtx'][0];
        $adv = [];
        for ($i = 0; $i < $n; $i++) $adv[] = $this->u16($o + $i * 4);
        $last = $adv ? end($adv) : 0;
        for ($i = $n; $i < $this->numGlyphs; $i++) $adv[] = $last;
        $this->advances = $adv;
    }

    private function lsb(int $gid): int
    {
        $n = $this->u16($this->tables['hhea'][0] + 34);
        $o = $this->tables['hmtx'][0];
        if ($gid < $n) return $this->i16($o + $gid * 4 + 2);
        return $this->i16($o + $n * 4 + ($gid - $n) * 2);
    }

    private function readCmap(): void
    {
        $base = $this->tables['cmap'][0];
        $count = $this->u16($base + 2);
        $best = null;
        $bestRank = 0;
        for ($i = 0; $i < $count; $i++) {
            $rec = $base + 4 + $i * 8;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $off = $base + $this->u32($rec + 4);
            $format = $this->u16($off);
            $rank = match (true) {
                $platform === 3 && $encoding === 10 && $format === 12 => 4,
                $platform === 0 && $format === 12 => 3,
                $platform === 3 && $encoding === 1 && $format === 4 => 2,
                $platform === 0 && $format === 4 => 1,
                default => 0,
            };
            if ($rank > $bestRank) { $bestRank = $rank; $best = $off; }
        }
        if ($best === null) throw new RuntimeException(basename($this->path) . ' has no Unicode cmap');
        $format = $this->u16($best);
        $map = [];
        if ($format === 12) {
            $groups = $this->u32($best + 12);
            for ($g = 0; $g < $groups; $g++) {
                $r = $best + 16 + $g * 12;
                $start = $this->u32($r);
                $end = $this->u32($r + 4);
                $gid = $this->u32($r + 8);
                if ($end - $start > 0x10000) continue;
                for ($c = $start; $c <= $end; $c++) $map[$c] = $gid + ($c - $start);
            }
        } else {
            $segX2 = $this->u16($best + 6);
            $ends = $best + 14;
            $starts = $ends + $segX2 + 2;
            $deltas = $starts + $segX2;
            $ranges = $deltas + $segX2;
            for ($s = 0; $s < $segX2 / 2; $s++) {
                $end = $this->u16($ends + $s * 2);
                $start = $this->u16($starts + $s * 2);
                $delta = $this->i16($deltas + $s * 2);
                $rangeOff = $this->u16($ranges + $s * 2);
                if ($start === 0xFFFF) break;
                for ($c = $start; $c <= $end; $c++) {
                    if ($rangeOff === 0) {
                        $gid = ($c + $delta) & 0xFFFF;
                    } else {
                        $p = $ranges + $s * 2 + $rangeOff + ($c - $start) * 2;
                        $gid = $p + 1 < strlen($this->data) ? $this->u16($p) : 0;
                        if ($gid !== 0) $gid = ($gid + $delta) & 0xFFFF;
                    }
                    if ($gid !== 0) $map[$c] = $gid;
                }
            }
        }
        $this->cmap = $map;
    }

    private function readOs2(): void
    {
        if (!isset($this->tables['OS/2'])) {
            $this->capHeight = (int)round($this->ascent * 0.7);
            return;
        }
        [$o, $len] = $this->tables['OS/2'];
        $version = $this->u16($o);
        $this->weight = $this->u16($o + 4) ?: 400;
        if ($len >= 72) {
            $typoAsc = $this->i16($o + 68);
            $typoDesc = $this->i16($o + 70);
            // USE_TYPO_METRICS (fsSelection bit 7) — then the typo values are authoritative
            if (($this->u16($o + 62) & 0x80) && $typoAsc > 0) {
                $this->ascent = $typoAsc;
                $this->descent = $typoDesc;
                $this->lineGap = $this->i16($o + 72);
            }
        }
        $this->capHeight = ($version >= 2 && $len >= 90) ? ($this->i16($o + 88) ?: (int)round($this->ascent * 0.7)) : (int)round($this->ascent * 0.7);
    }

    private function readPost(): void
    {
        if (!isset($this->tables['post'])) return;
        $o = $this->tables['post'][0];
        $this->italicAngle = $this->i16($o + 4) + $this->u16($o + 6) / 65536;
        $this->fixedPitch = $this->u32($o + 12) !== 0;
    }

    private function readName(): void
    {
        if (!isset($this->tables['name'])) return;
        $o = $this->tables['name'][0];
        $count = $this->u16($o + 2);
        $strings = $o + $this->u16($o + 4);
        for ($i = 0; $i < $count; $i++) {
            $r = $o + 6 + $i * 12;
            if ($this->u16($r + 6) !== 6) continue; // postscript name
            $platform = $this->u16($r);
            $raw = substr($this->data, $strings + $this->u16($r + 10), $this->u16($r + 8));
            $name = $platform === 3 || $platform === 0 ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $name = preg_replace('/[^A-Za-z0-9\-]/', '', $name);
            if ($name !== '') { $this->postscriptName = $name; return; }
        }
    }

    private function locaTable(): array
    {
        if ($this->loca !== null) return $this->loca;
        if (!isset($this->tables['loca'], $this->tables['glyf'])) {
            throw new RuntimeException(basename($this->path) . ' has no glyf/loca tables');
        }
        $long = $this->i16($this->tables['head'][0] + 50) === 1;
        $o = $this->tables['loca'][0];
        $loca = [];
        for ($i = 0; $i <= $this->numGlyphs; $i++) {
            $loca[] = $long ? $this->u32($o + $i * 4) : $this->u16($o + $i * 2) * 2;
        }
        return $this->loca = $loca;
    }

    private function glyphData(int $gid): string
    {
        $loca = $this->locaTable();
        if ($gid < 0 || $gid >= $this->numGlyphs) return '';
        $start = $loca[$gid];
        $len = $loca[$gid + 1] - $start;
        return $len > 0 ? substr($this->data, $this->tables['glyf'][0] + $start, $len) : '';
    }

    /** $gids plus every component used by composite glyphs among them (recursively). */
    private function withComponents(array $gids): array
    {
        $seen = [];
        $stack = array_values(array_unique(array_map('intval', $gids)));
        while ($stack) {
            $g = array_pop($stack);
            if (isset($seen[$g]) || $g < 0 || $g >= $this->numGlyphs) continue;
            $seen[$g] = true;
            foreach ($this->components($this->glyphData($g)) as [$component]) {
                if (!isset($seen[$component])) $stack[] = $component;
            }
        }
        $out = array_keys($seen);
        sort($out);
        return $out;
    }

    /** @return array<int, array{0:int,1:int}> [component gid, byte offset of its index] */
    private function components(string $glyph): array
    {
        if (strlen($glyph) < 10 || unpack('n', $glyph, 0)[1] < 0x8000) return [];
        $out = [];
        $p = 10;
        do {
            if ($p + 4 > strlen($glyph)) break;
            $flags = unpack('n', $glyph, $p)[1];
            $out[] = [unpack('n', $glyph, $p + 2)[1], $p + 2];
            $p += 4 + (($flags & 0x0001) ? 4 : 2);
            if ($flags & 0x0008) $p += 2;
            elseif ($flags & 0x0040) $p += 4;
            elseif ($flags & 0x0080) $p += 8;
        } while ($flags & 0x0020);
        return $out;
    }

    private function remapComposite(string $glyph, array $map): string
    {
        foreach ($this->components($glyph) as [$old, $pos]) {
            $glyph = substr_replace($glyph, pack('n', $map[$old] ?? 0), $pos, 2);
        }
        return $glyph;
    }

    /**
     * Assemble a font file. $metrics/$cmap null = keep the original glyph
     * order (strip mode); otherwise they describe the renumbered glyph set.
     */
    private function build(string $glyf, array $loca, ?array $metrics, ?array $cmap): string
    {
        $numGlyphs = count($loca) - 1;
        $tables = [];

        $head = $this->table('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);           // checkSumAdjustment, set below
        $head = substr_replace($head, pack('n', 1), 50, 2);        // long loca
        $tables['head'] = $head;

        $hhea = $this->table('hhea');
        if ($metrics !== null) {
            $hhea = substr_replace($hhea, pack('n', count($metrics)), 34, 2);
            $hmtx = '';
            foreach ($metrics as [$adv, $lsb]) $hmtx .= pack('n', $adv) . pack('n', $lsb & 0xFFFF);
            $tables['hmtx'] = $hmtx;
        } else {
            $tables['hmtx'] = $this->table('hmtx');
        }
        $tables['hhea'] = $hhea;

        $maxp = $this->table('maxp');
        $tables['maxp'] = substr_replace($maxp, pack('n', $numGlyphs), 4, 2);

        $locaBin = '';
        foreach ($loca as $off) $locaBin .= pack('N', $off);
        $tables['loca'] = $locaBin;
        $tables['glyf'] = $glyf;

        $tables['cmap'] = $cmap !== null ? self::buildCmap($cmap) : $this->table('cmap');
        // post format 3: no glyph names (they would reference the old glyph order)
        $post = $this->table('post');
        $tables['post'] = strlen($post) >= 32 ? "\x00\x03\x00\x00" . substr($post, 4, 28) : $post;

        foreach (['OS/2', 'name', 'cvt ', 'fpgm', 'prep', 'gasp'] as $tag) {
            if (isset($this->tables[$tag])) $tables[$tag] = $this->table($tag);
        }
        ksort($tables, SORT_STRING);

        $n = count($tables);
        $entrySelector = (int)floor(log($n, 2));
        $searchRange = (2 ** $entrySelector) * 16;
        $out = "\x00\x01\x00\x00" . pack('nnnn', $n, $searchRange, $entrySelector, $n * 16 - $searchRange);
        $offset = 12 + $n * 16;
        $dir = '';
        $body = '';
        $headOffset = 0;
        foreach ($tables as $tag => $bytes) {
            $padded = $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
            $dir .= $tag . pack('NNN', self::checksum($padded), $offset, strlen($bytes));
            if ($tag === 'head') $headOffset = $offset;
            $body .= $padded;
            $offset += strlen($padded);
        }
        $font = $out . $dir . $body;
        $adjust = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        return substr_replace($font, pack('N', $adjust), $headOffset + 8, 4);
    }

    private static function checksum(string $bytes): int
    {
        $bytes .= str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $bytes) as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
        return $sum;
    }

    /** cmap with a format 4 (BMP) subtable for 3/1 and 0/3. */
    private static function buildCmap(array $map): string
    {
        ksort($map);
        $segments = [];
        foreach ($map as $cp => $gid) {
            if ($cp > 0xFFFE) continue;
            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last][1] === $cp - 1 && $segments[$last][2] + ($cp - $segments[$last][0]) === $gid) {
                $segments[$last][1] = $cp;
            } else {
                $segments[] = [$cp, $cp, $gid];
            }
        }
        $segments[] = [0xFFFF, 0xFFFF, 0]; // required end segment (idDelta 1 → .notdef)
        $segCount = count($segments);
        $entrySelector = (int)floor(log($segCount, 2));
        $searchRange = (2 ** $entrySelector) * 2;
        $ends = $starts = $deltas = $ranges = '';
        foreach ($segments as [$start, $end, $gid]) {
            $ends .= pack('n', $end);
            $starts .= pack('n', $start);
            $deltas .= pack('n', ($gid - $start) & 0xFFFF);
            $ranges .= pack('n', 0);
        }
        $sub = pack('nnnnnnn', 4, 0, 0, $segCount * 2, $searchRange, $entrySelector, $segCount * 2 - $searchRange)
             . $ends . pack('n', 0) . $starts . $deltas . $ranges;
        $sub = substr_replace($sub, pack('n', strlen($sub)), 2, 2);
        return pack('nn', 0, 2) . pack('nnN', 0, 3, 20) . pack('nnN', 3, 1, 20) . $sub;
    }
}
