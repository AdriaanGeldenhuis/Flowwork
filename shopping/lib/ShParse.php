<?php
// /shopping/lib/ShParse.php — a typed or pasted list → items (no database; unit tested).
//
//   ShParse::items("10 bags cement @ R95\n2x PVC elbow 20mm\nmilk 2L, bread, eggs!")
//   → [['name' => 'Cement', 'qty' => 10, 'unit' => 'bag', 'price_cents' => 9500, 'priority' => 'med', 'category' => 'hardware'],
//      ['name' => 'PVC elbow 20mm', 'qty' => 2, 'unit' => 'ea', …, 'category' => 'plumbing'],
//      ['name' => 'Milk 2L', 'qty' => 1, …], ['name' => 'Bread', …], ['name' => 'Eggs', 'priority' => 'high', …]]
//
// One item a line (also split on ";" and, when every part is an item, on commas).
// A count goes first ("2x", "2 ×", "10 bags of", "6m") or last ("x 10", "(10)",
// "- 10", "10 bags"). Sizes stay in the name: "PVC elbow 20mm" is one elbow,
// "milk 2L" one bottle of milk. "@ R95" or "R95" is the price of one.
// "!", "urgent" or "asap" makes it urgent. Bullets, numbering and ticks are dropped.

final class ShParse
{
    /** Units: canonical => label. */
    public const UNITS = [
        'ea' => 'each', 'pack' => 'pack', 'box' => 'box', 'bag' => 'bag', 'roll' => 'roll', 'pair' => 'pair', 'set' => 'set',
        'dozen' => 'dozen', 'sheet' => 'sheet', 'tin' => 'tin', 'bottle' => 'bottle', 'can' => 'can', 'length' => 'length',
        'bundle' => 'bundle', 'tube' => 'tube', 'carton' => 'carton', 'crate' => 'crate', 'tray' => 'tray',
        'm' => 'm', 'mm' => 'mm', 'cm' => 'cm', 'm²' => 'm²', 'm³' => 'm³', 'kg' => 'kg', 'g' => 'g', 'L' => 'L', 'ml' => 'ml',
    ];
    /** Units that count things (a size like "20mm" is never a count). */
    public const COUNT_UNITS = ['ea', 'pack', 'box', 'bag', 'roll', 'pair', 'set', 'dozen', 'sheet', 'tin', 'bottle', 'can', 'length', 'bundle', 'tube', 'carton', 'crate', 'tray'];

    private const ALIASES = [
        'ea' => 'ea', 'each' => 'ea', 'pc' => 'ea', 'pcs' => 'ea', 'piece' => 'ea', 'pieces' => 'ea', 'item' => 'ea', 'items' => 'ea', 'stuk' => 'ea', 'stukke' => 'ea', 'unit' => 'ea', 'units' => 'ea',
        'pack' => 'pack', 'packs' => 'pack', 'pk' => 'pack', 'pkt' => 'pack', 'pkts' => 'pack', 'packet' => 'pack', 'packets' => 'pack', 'pak' => 'pack', 'pakke' => 'pack', 'pakkie' => 'pack', 'pakkies' => 'pack',
        'box' => 'box', 'boxes' => 'box', 'bx' => 'box', 'doos' => 'box', 'dose' => 'box', 'dosie' => 'box',
        'bag' => 'bag', 'bags' => 'bag', 'sak' => 'bag', 'sakke' => 'bag', 'sakkie' => 'bag', 'sakkies' => 'bag',
        'roll' => 'roll', 'rolls' => 'roll', 'rol' => 'roll', 'rolle' => 'roll', 'rolletjie' => 'roll',
        'pair' => 'pair', 'pairs' => 'pair', 'pr' => 'pair', 'paar' => 'pair',
        'set' => 'set', 'sets' => 'set', 'stel' => 'set', 'stelle' => 'set',
        'dozen' => 'dozen', 'doz' => 'dozen', 'dosyn' => 'dozen',
        'sheet' => 'sheet', 'sheets' => 'sheet', 'vel' => 'sheet', 'velle' => 'sheet',
        'tin' => 'tin', 'tins' => 'tin', 'blik' => 'tin', 'blikke' => 'tin', 'blikkie' => 'tin', 'blikkies' => 'tin',
        'bottle' => 'bottle', 'bottles' => 'bottle', 'bottel' => 'bottle', 'bottels' => 'bottle',
        'can' => 'can', 'cans' => 'can',
        'length' => 'length', 'lengths' => 'length', 'lengte' => 'length', 'lengtes' => 'length',
        'bundle' => 'bundle', 'bundles' => 'bundle', 'bondel' => 'bundle', 'bondels' => 'bundle',
        'tube' => 'tube', 'tubes' => 'tube', 'buis' => 'tube', 'buise' => 'tube',
        'carton' => 'carton', 'cartons' => 'carton', 'karton' => 'carton', 'kartonne' => 'carton',
        'crate' => 'crate', 'crates' => 'crate', 'krat' => 'crate', 'kratte' => 'crate',
        'tray' => 'tray', 'trays' => 'tray',
        'm' => 'm', 'meter' => 'm', 'meters' => 'm', 'metre' => 'm', 'metres' => 'm',
        'mm' => 'mm', 'cm' => 'cm',
        'm2' => 'm²', 'm²' => 'm²', 'sqm' => 'm²',
        'm3' => 'm³', 'm³' => 'm³', 'cube' => 'm³', 'cubes' => 'm³', 'kubiek' => 'm³',
        'kg' => 'kg', 'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
        'g' => 'g', 'gram' => 'g', 'grams' => 'g',
        'l' => 'L', 'lt' => 'L', 'ltr' => 'L', 'ltrs' => 'L', 'litre' => 'L', 'litres' => 'L', 'liter' => 'L', 'liters' => 'L',
        'ml' => 'ml',
    ];

    /** Aisles: key => [label, icon, colour, words (a phrase counts double)]. The first with the most hits wins. */
    public const CATEGORIES = [
        'hardware' => ['Building & hardware', 'is-brick', '#9a6512', ['cement', 'sand', 'stone', 'gravel', 'brick', 'bricks', 'block', 'blocks', 'timber', 'plank', 'planks', 'board', 'boards', 'plywood', 'nail', 'nails', 'screw', 'screws', 'bolt', 'bolts', 'nut', 'nuts', 'washer', 'washers', 'hinge', 'hinges', 'glue', 'silicone', 'sealant', 'paint', 'primer', 'brush', 'roller', 'sandpaper', 'drill', 'bit', 'bits', 'blade', 'blades', 'tape', 'rope', 'chain', 'mesh', 'steel', 'rebar', 'plaster', 'rhinolite', 'gypsum', 'ceiling', 'tile', 'tiles', 'grout', 'adhesive', 'ladder', 'wheelbarrow', 'spade', 'shovel', 'hammer', 'trowel', 'level', 'chisel', 'saw', 'anchor', 'anchors', 'rawl', 'rawlplug', 'rawlplugs', 'padlock', 'lock', 'handle', 'putty', 'filler', 'thinners', 'turps', 'varnish', 'dpc', 'lintel', 'aggregate', 'readymix', 'binding wire', 'angle iron', 'cable ties', 'duct tape', 'masking tape', 'sement', 'stene', 'hout', 'spykers', 'skroewe', 'verf']],
        'electrical' => ['Electrical', 'is-bolt', '#2f5c84', ['cable', 'cables', 'conduit', 'led', 'globe', 'globes', 'bulb', 'bulbs', 'lamp', 'downlight', 'downlights', 'plug', 'plugs', 'socket', 'sockets', 'switch', 'switches', 'breaker', 'breakers', 'isolator', 'extension', 'lugs', 'trunking', 'inverter', 'solar', 'fuse', 'fuses', 'earth', 'surfix', 'flex', 'adaptor', 'adapter', 'battery', 'batteries', 'electrical', 'db board', 'circuit breaker', 'earth leakage', 'junction box', 'gland', 'glands', 'ferrule', 'ferrules', 'kabel', 'prop']],
        'plumbing' => ['Plumbing', 'is-drop', '#2f6b6a', ['pipe', 'pipes', 'elbow', 'elbows', 'tee', 'tees', 'coupling', 'couplings', 'valve', 'valves', 'tap', 'taps', 'geyser', 'toilet', 'basin', 'drain', 'pvc', 'hdpe', 'copper', 'fitting', 'fittings', 'reducer', 'reducers', 'trap', 'cistern', 'hose', 'mixer', 'gutter', 'gutters', 'downpipe', 'thread tape', 'ptfe', 'compression', 'pyp', 'krane']],
        'ppe' => ['Safety & PPE', 'is-helmet', '#a4552c', ['gloves', 'glove', 'boots', 'helmet', 'helmets', 'vest', 'vests', 'reflective', 'goggles', 'earplugs', 'overall', 'overalls', 'respirator', 'ppe', 'hard hat', 'hard hats', 'safety glasses', 'safety boots', 'dust mask', 'dust masks', 'first aid', 'hi-vis', 'hi vis', 'handskoene']],
        'office' => ['Office & stationery', 'is-clip', '#5a4636', ['paper', 'a4', 'a3', 'toner', 'ink', 'cartridge', 'pen', 'pens', 'pencil', 'pencils', 'stapler', 'staples', 'folder', 'folders', 'envelope', 'envelopes', 'notebook', 'notebooks', 'marker', 'markers', 'highlighter', 'highlighters', 'files', 'printer', 'sticky notes', 'post-it', 'post-its', 'labels', 'calculator', 'whiteboard', 'tipex', 'prestik', 'sellotape', 'koki', 'kokis', 'papier']],
        'cleaning' => ['Cleaning', 'is-spray', '#3d5a8a', ['bleach', 'soap', 'detergent', 'sponge', 'sponges', 'mop', 'broom', 'sanitiser', 'sanitizer', 'handwash', 'dishwash', 'dishwashing', 'cloth', 'cloths', 'jik', 'domestos', 'handy andy', 'sunlight', 'bin bags', 'refuse bags', 'black bags', 'toilet paper', 'toilet roll', 'toilet rolls', 'paper towel', 'paper towels', 'serviettes', 'air freshener', 'polish', 'squeegee', 'dustpan', 'vim', 'seep', 'lappe']],
        'groceries' => ['Groceries', 'is-basket', '#2f7457', ['milk', 'bread', 'eggs', 'egg', 'butter', 'margarine', 'cheese', 'coffee', 'tea', 'rooibos', 'sugar', 'rice', 'pasta', 'meat', 'mince', 'chicken', 'beef', 'pork', 'boerewors', 'fish', 'fruit', 'apple', 'apples', 'banana', 'bananas', 'orange', 'oranges', 'vegetables', 'veg', 'potato', 'potatoes', 'onion', 'onions', 'tomato', 'tomatoes', 'lettuce', 'carrot', 'carrots', 'water', 'juice', 'coke', 'cola', 'cooldrink', 'cooldrinks', 'snacks', 'chips', 'biscuits', 'rusks', 'cereal', 'oats', 'flour', 'salt', 'pepper', 'maize', 'mealie', 'mealiemeel', 'pap', 'yoghurt', 'yogurt', 'jam', 'peanut butter', 'creamer', 'cremora', 'sauce', 'spices', 'ice', 'melk', 'brood', 'eiers', 'botter', 'kaas', 'koffie', 'suiker', 'vleis', 'hoender', 'vrugte', 'groente', 'aartappels', 'uie', 'water', 'sap']],
        'vehicle' => ['Vehicle & fuel', 'is-car', '#4f5d6b', ['diesel', 'petrol', 'fuel', 'tyre', 'tyres', 'tire', 'tires', 'wiper', 'wipers', 'coolant', 'brake', 'brakes', 'oil filter', 'air filter', 'engine oil', 'spark plug', 'spark plugs', 'gas', 'lpg']],
    ];
    public const OTHER = ['Everything else', 'is-tag', '#85766b'];

    /** @return list<array{name:string, qty:float, unit:string, price_cents:?int, priority:string, category:string}> */
    public static function items(string $text, int $max = 200): array
    {
        $out = [];
        foreach (self::lines($text) as $line) {
            $it = self::line($line);
            if ($it !== null) $out[] = $it;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /** The text, one candidate item a line. */
    public static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", ';', "\u{2022}"], ["\n", "\n", "\n", "\n•"], $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = preg_split('/(?<!\d),|,(?!\d)/u', $line) ?: [$line];
            $parts = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
            if (count($parts) > 1 && count(array_filter($parts, [self::class, 'looksLikeItem'])) === count($parts)) {
                foreach ($parts as $p) $out[] = $p;
            } else {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** Has a word that is neither a unit nor a size — so "50kg bag" alone is not an item, "bread" is. */
    private static function looksLikeItem(string $part): bool
    {
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($part), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w) >= 2 && !isset(self::ALIASES[$w]) && !in_array($w, ['x', 'of', 'van', 'and', 'en', 'at'], true)) return true;
        }
        return false;
    }

    public static function line(string $line): ?array
    {
        $s = trim($line);
        // Bullets, numbering, check boxes and ticks
        $s = preg_replace('/^(?:[-*•·▪◦‣–—>]+\s*|\d{1,3}[.)]\s+|\[\s*[xX✓✔]?\s*\]\s*|[☐☑✓✔✗]\s*)+/u', '', $s) ?? $s;
        $s = trim($s);
        if ($s === '') return null;

        $priority = 'med';
        if (preg_match('/(^!+\s*|\s*!+$|\b(?:urgent|asap|dringend)\b)/iu', $s)) {
            $priority = 'high';
            $s = trim(preg_replace('/^!+\s*|\s*!+$|\s*[-–(]?\s*\b(?:urgent|asap|dringend)\b\s*\)?/iu', ' ', $s) ?? $s);
        }

        // The price of one: "@ R95", "@ 95", "R 1 299,99", "R1,299.99", "R95 each"
        $price = null;
        $money = '(\d[\d \x{00A0}.,]*\d|\d)';
        if (preg_match('/\s*@\s*R?\s?' . $money . '(?:\s*(?:each|ea|per\s+\p{L}+))?\s*$/iu', $s, $m)
            || preg_match('/\s*(?<![\p{L}\d])R\s?' . $money . '(?:\s*(?:each|ea|per\s+\p{L}+))?\s*$/u', $s, $m)) {
            $c = self::money($m[1]);
            if ($c !== null) {
                $price = $c;
                $s = trim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m[0])));
            }
        }

        $qty = 1.0;
        $unit = 'ea';
        $num = '(\d+\/\d+|\d+(?:[.,]\d+)?(?:\s?[½¼¾])?|[½¼¾])';
        $unitAlt = self::unitPattern();
        $countAlt = self::unitPattern(true);
        if (preg_match('/^' . $num . '\s*[x×*]\s*(?=[^\d\s])(.+)$/iu', $s, $m)) {                   // 2x PVC elbow · 2 × bread
            [$qty, $s] = [self::num($m[1]), $m[2]];
        } elseif (preg_match('/^' . $num . '\s*(' . $unitAlt . ')\.?(?:\s+(?:of|van)\b)?\s+(.+)$/iu', $s, $m)) {   // 10 bags of cement · 6m conduit
            [$qty, $unit, $s] = [self::num($m[1]), self::unit($m[2]), $m[3]];
        } elseif (preg_match('/^' . $num . '\s+(.+)$/u', $s, $m)) {                                   // 3 bread
            [$qty, $s] = [self::num($m[1]), $m[2]];
        } elseif (preg_match('/^(.+?)(?:\s+[x×*]\s*' . $num . '|\s*\(\s*' . $num . '\s*\)|\s+[-–]\s*' . $num . '|\s*:\s*' . $num . ')\s*(' . $countAlt . ')?\.?$/iu', $s, $m)) { // cement x 10 · cement (10) · cement - 10 bags · cement: 10
            $raw = '';
            foreach ([2, 3, 4, 5] as $g) if (($m[$g] ?? '') !== '') { $raw = $m[$g]; break; }
            $qty = self::num($raw);
            $s = $m[1];
            if (!empty($m[6])) $unit = self::unit($m[6]);
        } elseif (preg_match('/^(.+?)\s+' . $num . '\s*(' . $countAlt . ')\.?$/iu', $s, $m)) {          // cement 10 bags
            [$s, $qty, $unit] = [$m[1], self::num($m[2]), self::unit($m[3])];
        }
        if ($qty === null || $qty <= 0) $qty = 1.0;
        $qty = min(1000000.0, round($qty, 3));

        $name = trim(preg_replace('/\s+/u', ' ', $s) ?? $s, " \t-–—:,.;*");
        if ($name === '' || !preg_match('/[\p{L}\d]/u', $name)) return null;
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        $name = mb_substr($name, 0, 200);
        return ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'price_cents' => $price, 'priority' => $priority, 'category' => self::category($name)];
    }

    private static function unitPattern(bool $countOnly = false): string
    {
        static $cache = [];
        if (isset($cache[$countOnly])) return $cache[$countOnly];
        $keys = array_keys(array_filter(self::ALIASES, fn($u) => !$countOnly || in_array($u, self::COUNT_UNITS, true)));
        usort($keys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $cache[$countOnly] = implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys)) . '(?![\p{L}\d])';
    }

    /** "1 299,99", "1,299.99", "95" → cents, or null. */
    public static function money(string $raw): ?int
    {
        $s = str_replace([' ', "\u{00A0}"], '', trim($raw));
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $s)) $s = str_replace(',', '', $s);
        elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
        elseif (preg_match('/^\d+,\d{1,2}$/', $s)) $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
        $c = (int)round((float)$s * 100);
        return $c > 0 && $c <= 1000000000 ? $c : null;
    }

    /** "2,5" → 2.5, "½" → 0.5, "1½" → 1.5, "3/4" → 0.75 */
    public static function num(string $s): ?float
    {
        $s = trim($s);
        $frac = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75];
        $add = 0.0;
        foreach ($frac as $f => $v) {
            if (str_contains($s, $f)) { $add = $v; $s = trim(str_replace($f, '', $s)); }
        }
        if ($s === '') return $add ?: null;
        if (preg_match('/^(\d+)\/(\d+)$/', $s, $m)) return (int)$m[2] > 0 ? (int)$m[1] / (int)$m[2] : null;
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s + $add : null;
    }

    /** Any spelling of a unit → its canonical form ('ea' when unknown). */
    public static function unit($u): string
    {
        $u = trim((string)$u);
        if (isset(self::UNITS[$u])) return $u;
        return self::ALIASES[mb_strtolower(rtrim($u, '.'))] ?? 'ea';
    }

    /** The aisle a name belongs in. */
    public static function category(string $name): string
    {
        $text = ' ' . mb_strtolower(preg_replace('/[^\p{L}\d\- ]+/u', ' ', $name) ?? $name) . ' ';
        $words = array_flip(preg_split('/[^\p{L}\d\-]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $best = 'other';
        $bestScore = 0;
        foreach (self::CATEGORIES as $key => [, , , $list]) {
            $score = 0;
            foreach ($list as $w) {
                if (str_contains($w, ' ') || str_contains($w, '-')) { if (str_contains($text, ' ' . $w . ' ')) $score += 2; }
                elseif (isset($words[$w])) $score += 1;
            }
            if ($score > $bestScore) { $best = $key; $bestScore = $score; }
        }
        return $best;
    }

    public static function categoryLabel(string $key): string
    {
        return self::CATEGORIES[$key][0] ?? self::OTHER[0];
    }

    /** What a name is matched on: lower case, letters and digits only. */
    public static function norm(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/[^\p{L}\d]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** One item across lists, stores and time (price history). */
    public static function fingerprint(string $name, string $unit): string
    {
        return md5(self::norm($name) . '|' . self::unit($unit));
    }

    /** The words worth searching supplier bills for ("PVC elbow 20mm" → ["pvc", "elbow"]). */
    public static function keywords(string $name): array
    {
        static $stop = ['the', 'and', 'for', 'with', 'of', 'a', 'an', 'new', 'large', 'small', 'medium', 'big', 'white', 'black', 'grey', 'gray', 'red', 'blue', 'green'];
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, $stop, true) && !isset(self::ALIASES[$w])) $out[] = $w;
        }
        return array_slice(array_values(array_unique($out)), 0, 4);
    }
}
