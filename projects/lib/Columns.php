<?php
// projects/lib/Columns.php
//
// Column types: what each stores in board_item_values.value, how a column's
// config is read (every shape older boards saved is accepted) and written, and
// how a value is validated, decoded for the browser and turned into text.
//
// Stored values (unchanged from the old boards, so existing data keeps working):
//   status     label key ('done', 'working' …) or, on custom label sets, the label text
//   priority   'low' | 'medium' | 'high' | 'urgent'
//   people     user ids, comma separated ("3" or "3,7")
//   date       'Y-m-d'
//   timeline   {"start":"Y-m-d","end":"Y-m-d"}
//   dropdown   option text; several options as a JSON array
//   tags       JSON array of strings (a comma list is read too)
//   link       URL, or {"url":…, "text":…}
//   checkbox   '1' / '0'
//   number     plain number ('1250.5'); formula: the computed result
//   progress   0–100 (manual) — or computed from status columns / subitems
//   rating     0–5
//   supplier   crm_accounts.id of a supplier
//   files      — (attachments live in board_item_attachments)
//   created / updated  — read from the item itself

final class Columns
{
    public const TYPES = [
        'status'   => ['label' => 'Status',        'icon' => 'status',   'hint' => 'Coloured labels like Working on it, Stuck, Done'],
        'people'   => ['label' => 'People',        'icon' => 'users',    'hint' => 'Assign one or more teammates'],
        'date'     => ['label' => 'Date',          'icon' => 'calendar', 'hint' => 'A deadline or any date'],
        'timeline' => ['label' => 'Timeline',      'icon' => 'timeline', 'hint' => 'Start and end dates'],
        'priority' => ['label' => 'Priority',      'icon' => 'flag',     'hint' => 'Low, Medium, High, Urgent'],
        'text'     => ['label' => 'Text',          'icon' => 'text',     'hint' => 'A short line of text'],
        'longtext' => ['label' => 'Long text',     'icon' => 'file-text','hint' => 'Notes and descriptions'],
        'number'   => ['label' => 'Numbers',       'icon' => 'hash',     'hint' => 'Quantities, amounts and money, with totals'],
        'formula'  => ['label' => 'Formula',       'icon' => 'function', 'hint' => 'Calculate from other columns'],
        'progress' => ['label' => 'Progress',      'icon' => 'progress', 'hint' => 'Percent complete, typed or from status columns'],
        'dropdown' => ['label' => 'Dropdown',      'icon' => 'chev-down','hint' => 'Pick from a list of options'],
        'tags'     => ['label' => 'Tags',          'icon' => 'tag',      'hint' => 'Free keywords'],
        'checkbox' => ['label' => 'Checkbox',      'icon' => 'check-square', 'hint' => 'Yes or no'],
        'rating'   => ['label' => 'Rating',        'icon' => 'star',     'hint' => 'One to five stars'],
        'link'     => ['label' => 'Link',          'icon' => 'link',     'hint' => 'A web address'],
        'email'    => ['label' => 'Email',         'icon' => 'mail',     'hint' => 'An email address'],
        'phone'    => ['label' => 'Phone',         'icon' => 'phone',    'hint' => 'A phone number'],
        'supplier' => ['label' => 'Supplier',      'icon' => 'building', 'hint' => 'A supplier from the CRM'],
        'files'    => ['label' => 'Files',         'icon' => 'clip',     'hint' => 'Photos, drawings and documents'],
        'created'  => ['label' => 'Created',       'icon' => 'clock',    'hint' => 'Who added the item and when'],
        'updated'  => ['label' => 'Last updated',  'icon' => 'refresh',  'hint' => 'When the item last changed'],
    ];

    /** Types whose value is computed, never typed in. */
    public const COMPUTED = ['formula', 'created', 'updated', 'files'];

    public const DEFAULT_STATUS = [
        ['key' => 'todo',    'label' => 'To do',         'color' => '#85766b'],
        ['key' => 'working', 'label' => 'Working on it', 'color' => '#d39a32'],
        ['key' => 'stuck',   'label' => 'Stuck',         'color' => '#c2412f'],
        ['key' => 'done',    'label' => 'Done',          'color' => '#2f8a5f', 'done' => true],
    ];

    public const PRIORITIES = [
        ['key' => 'urgent', 'label' => 'Urgent', 'color' => '#9e1f2e', 'rank' => 4],
        ['key' => 'high',   'label' => 'High',   'color' => '#c2412f', 'rank' => 3],
        ['key' => 'medium', 'label' => 'Medium', 'color' => '#b08d57', 'rank' => 2],
        ['key' => 'low',    'label' => 'Low',    'color' => '#5f7a8c', 'rank' => 1],
    ];

    public const PALETTE = ['#2f8a5f', '#d39a32', '#c2412f', '#2f5c84', '#7a5ab8', '#b4577a', '#1f7a7a', '#85766b',
                            '#7a1f30', '#b08d57', '#4b4fa8', '#6b7d2b', '#c06a2b', '#3f8fb5', '#9e1f2e', '#5b6670'];

    private const DONE_WORDS = '/^(done|complete|completed|finished|closed|paid|approved|delivered|installed|signed off|resolved)\b/i';

    public static function isType(string $t): bool { return isset(self::TYPES[$t]); }

    // ======================================================================= config

    /** A column's config as a clean array, whatever shape was stored. */
    public static function config(array $col): array
    {
        $raw = $col['config'] ?? null;
        $cfg = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
        if (!is_array($cfg)) $cfg = [];
        $type = (string)$col['type'];
        switch ($type) {
            case 'status':
                $cfg['labels'] = self::statusLabels($cfg);
                break;
            case 'dropdown':
                $cfg['options'] = self::dropdownOptions($cfg);
                $cfg['multi'] = !empty($cfg['multi']);
                break;
            case 'number':
            case 'formula':
                // Older boards: format 'percentage', {affix, affixPosition: prefix|suffix}.
                $fmt = ($cfg['format'] ?? '') === 'percentage' ? 'percent' : ($cfg['format'] ?? '');
                $cfg['format'] = in_array($fmt, $type === 'number' ? ['decimal', 'currency', 'percent', 'integer'] : ['decimal', 'currency', 'percent'], true) ? $fmt : 'decimal';
                $cfg['precision'] = max(0, min(6, (int)($cfg['precision'] ?? ($cfg['format'] === 'integer' ? 0 : 2))));
                $cfg['agg'] = self::agg($cfg['agg'] ?? 'sum');
                $unit = $cfg['unit'] ?? ($cfg['affix'] ?? ($cfg['format'] === 'currency' ? 'R' : ''));
                $cfg['unit'] = mb_substr(trim((string)$unit), 0, 8);
                $cfg['unit_pos'] = (($cfg['unit_pos'] ?? $cfg['affixPosition'] ?? 'prefix') === 'suffix') ? 'suffix' : 'prefix';
                if ($type === 'formula') $cfg['formula'] = (string)($cfg['formula'] ?? '');
                unset($cfg['affix'], $cfg['affixPosition']);
                break;
            case 'progress':
                $cfg['mode'] = in_array($cfg['mode'] ?? '', ['manual', 'status', 'subitems'], true) ? $cfg['mode'] : 'manual';
                $cfg['sources'] = array_values(array_map('intval', is_array($cfg['sources'] ?? null) ? $cfg['sources'] : []));
                break;
            case 'rating':
                $cfg['max'] = max(3, min(10, (int)($cfg['max'] ?? 5)));
                break;
            case 'date':
                $cfg['reminders'] = !array_key_exists('reminders', $cfg) || !empty($cfg['reminders']);
                break;
        }
        return $cfg;
    }

    /** Status labels as [{key, label, color, done}] from any stored shape. */
    public static function statusLabels(array $cfg): array
    {
        $labels = $cfg['labels'] ?? null;
        $out = [];
        if (is_array($labels) && $labels) {
            if (array_is_list($labels)) {
                foreach ($labels as $l) {
                    if (!is_array($l)) continue;
                    $label = mb_substr(trim((string)($l['label'] ?? $l['key'] ?? '')), 0, 60);
                    if ($label === '') continue;
                    $key = mb_substr(trim((string)($l['key'] ?? $label)), 0, 60);
                    $out[] = ['key' => $key, 'label' => $label, 'color' => self::color($l['color'] ?? null, count($out)),
                              'done' => array_key_exists('done', $l) ? !empty($l['done']) : (bool)preg_match(self::DONE_WORDS, $label)];
                }
            } else {
                // Legacy: {"Working on it": "#fdab3d", "Done": "#00c875"}
                foreach ($labels as $label => $color) {
                    $label = mb_substr(trim((string)$label), 0, 60);
                    if ($label === '') continue;
                    $out[] = ['key' => $label, 'label' => $label, 'color' => self::color($color, count($out)),
                              'done' => (bool)preg_match(self::DONE_WORDS, $label)];
                }
            }
        }
        if (!$out) {
            foreach (self::DEFAULT_STATUS as $d) $out[] = $d + ['done' => false];
        }
        return $out;
    }

    /** Dropdown options as [{label, color}] (stored as plain strings or objects). */
    public static function dropdownOptions(array $cfg): array
    {
        $out = [];
        $legacyColors = is_array($cfg['optionColors'] ?? null) ? $cfg['optionColors'] : [];
        foreach ((array)($cfg['options'] ?? []) as $o) {
            $label = is_array($o) ? (string)($o['label'] ?? '') : (string)$o;
            $label = mb_substr(trim($label), 0, 80);
            if ($label === '') continue;
            $c = is_array($o) ? ($o['color'] ?? null) : ($legacyColors[$label] ?? null);
            $out[] = ['label' => $label, 'color' => self::color($c, count($out) + 3)];
        }
        return $out;
    }

    /** Validate a config posted by the column editor. Returns the JSON to store (or null). */
    public static function encodeConfig(string $type, array $in): ?string
    {
        switch ($type) {
            case 'status':
                $labels = [];
                $seen = [];
                foreach ((array)($in['labels'] ?? []) as $l) {
                    if (!is_array($l)) continue;
                    $label = mb_substr(trim((string)($l['label'] ?? '')), 0, 60);
                    if ($label === '') continue;
                    $key = mb_substr(trim((string)($l['key'] ?? '')), 0, 60) ?: $label;
                    if (isset($seen[mb_strtolower($key)])) continue;
                    $seen[mb_strtolower($key)] = true;
                    $labels[] = ['key' => $key, 'label' => $label, 'color' => self::color($l['color'] ?? null, count($labels)), 'done' => !empty($l['done'])];
                    if (count($labels) >= 40) break;
                }
                if (!$labels) throw new ProjectUserError('A status column needs at least one label.');
                return json_encode(['labels' => $labels], JSON_UNESCAPED_UNICODE);
            case 'dropdown':
                $opts = [];
                $seen = [];
                foreach ((array)($in['options'] ?? []) as $o) {
                    $label = mb_substr(trim((string)(is_array($o) ? ($o['label'] ?? '') : $o)), 0, 80);
                    if ($label === '' || isset($seen[mb_strtolower($label)])) continue;
                    $seen[mb_strtolower($label)] = true;
                    $opts[] = ['label' => $label, 'color' => self::color(is_array($o) ? ($o['color'] ?? null) : null, count($opts))];
                    if (count($opts) >= 200) break;
                }
                return json_encode(['options' => $opts, 'multi' => !empty($in['multi'])], JSON_UNESCAPED_UNICODE);
            case 'number':
                $format = in_array($in['format'] ?? '', ['decimal', 'currency', 'percent', 'integer'], true) ? $in['format'] : 'decimal';
                return json_encode(['format' => $format, 'precision' => max(0, min(6, (int)($in['precision'] ?? 2))),
                                    'agg' => self::agg($in['agg'] ?? 'sum'), 'unit' => mb_substr(trim((string)($in['unit'] ?? '')), 0, 8),
                                    'unit_pos' => ($in['unit_pos'] ?? '') === 'suffix' ? 'suffix' : 'prefix'], JSON_UNESCAPED_UNICODE);
            case 'formula':
                $f = mb_substr(trim((string)($in['formula'] ?? '')), 0, 1000);
                $format = in_array($in['format'] ?? '', ['decimal', 'currency', 'percent'], true) ? $in['format'] : 'decimal';
                return json_encode(['formula' => $f, 'precision' => max(0, min(6, (int)($in['precision'] ?? 2))),
                                    'agg' => self::agg($in['agg'] ?? 'sum'), 'format' => $format,
                                    'unit' => mb_substr(trim((string)($in['unit'] ?? '')), 0, 8),
                                    'unit_pos' => ($in['unit_pos'] ?? '') === 'suffix' ? 'suffix' : 'prefix'], JSON_UNESCAPED_UNICODE);
            case 'progress':
                $mode = in_array($in['mode'] ?? '', ['manual', 'status', 'subitems'], true) ? $in['mode'] : 'manual';
                return json_encode(['mode' => $mode, 'sources' => array_values(array_unique(array_map('intval', (array)($in['sources'] ?? []))))]);
            case 'rating':
                return json_encode(['max' => max(3, min(10, (int)($in['max'] ?? 5)))]);
            case 'date':
                return json_encode(['reminders' => !array_key_exists('reminders', $in) || !empty($in['reminders'])]);
        }
        return null;
    }

    private static function agg($a): string
    {
        return in_array($a, ['sum', 'avg', 'min', 'max', 'count', 'none'], true) ? $a : 'sum';
    }

    public static function color($c, int $i = 0): string
    {
        $c = is_string($c) ? trim($c) : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : self::PALETTE[$i % count(self::PALETTE)];
    }

    // ======================================================================= values

    /**
     * Validate input for a cell and return what to store ('' or null clears).
     * @param array $ctx ['users' => [id => true], 'suppliers' => [id => true]] for the checks that need them
     */
    public static function encodeValue(array $col, $v, array $ctx = []): ?string
    {
        $type = (string)$col['type'];
        if (in_array($type, self::COMPUTED, true)) throw new ProjectUserError('This column is calculated automatically.');
        if ($v === null || $v === '' || $v === []) return null;
        $cfg = self::config($col);
        switch ($type) {
            case 'status':
                $v = trim((string)$v);
                foreach ($cfg['labels'] as $l) {
                    if (strcasecmp($l['key'], $v) === 0 || strcasecmp($l['label'], $v) === 0) return $l['key'];
                }
                throw new ProjectUserError('That label is not on this status column.');
            case 'priority':
                $v = strtolower(trim((string)$v));
                if ($v === 'critical') $v = 'urgent';
                foreach (self::PRIORITIES as $p) if ($p['key'] === $v || strtolower($p['label']) === $v) return $p['key'];
                throw new ProjectUserError('Choose Low, Medium, High or Urgent.');
            case 'people':
                $ids = is_array($v) ? $v : preg_split('/[\s,]+/', (string)$v);
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
                if (isset($ctx['users'])) $ids = array_values(array_filter($ids, fn($x) => isset($ctx['users'][$x])));
                return $ids ? implode(',', array_slice($ids, 0, 30)) : null;
            case 'date':
                $d = self::date($v);
                if ($d === null) throw new ProjectUserError('That is not a valid date.');
                return $d;
            case 'timeline':
                $a = is_array($v) ? $v : json_decode((string)$v, true);
                if (!is_array($a)) throw new ProjectUserError('A timeline needs a start and an end date.');
                $s = self::date($a['start'] ?? $a['from'] ?? null);
                $e = self::date($a['end'] ?? $a['to'] ?? null) ?? $s;
                $s = $s ?? $e;
                if ($s === null) return null;
                if ($e < $s) [$s, $e] = [$e, $s];
                return json_encode(['start' => $s, 'end' => $e]);
            case 'text':
                return self::str($v, 2000);
            case 'longtext':
                return self::str($v, 20000);
            case 'email':
                $s = self::str($v, 255);
                if ($s !== null && !filter_var($s, FILTER_VALIDATE_EMAIL)) throw new ProjectUserError('That is not a valid email address.');
                return $s;
            case 'phone':
                $s = self::str($v, 40);
                if ($s !== null && !preg_match('/^[0-9+()\-\s.\/x]{3,40}$/i', $s)) throw new ProjectUserError('That is not a valid phone number.');
                return $s;
            case 'link':
                $a = is_array($v) ? $v : ['url' => (string)$v];
                $url = trim((string)($a['url'] ?? ''));
                if ($url === '') return null;
                if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) $url = 'https://' . $url;
                if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) throw new ProjectUserError('Links must be web addresses (https://…).');
                $text = self::str($a['text'] ?? '', 200);
                return $text ? json_encode(['url' => mb_substr($url, 0, 1000), 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : mb_substr($url, 0, 1000);
            case 'number':
                $s = str_replace([' ', ',', 'R', '%'], ['', '', '', ''], trim((string)$v));
                if (!is_numeric($s)) throw new ProjectUserError('Numbers only, please.');
                $n = (float)$s;
                if (abs($n) > 1e13) throw new ProjectUserError('That number is too large.');
                return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.') ?: '0';
            case 'progress':
                if (!is_numeric($v)) throw new ProjectUserError('Progress is a percentage from 0 to 100.');
                return (string)max(0, min(100, (int)round((float)$v)));
            case 'rating':
                if (!is_numeric($v)) throw new ProjectUserError('Choose a rating.');
                $r = max(0, min($cfg['max'] ?? 5, (int)$v));
                return $r ? (string)$r : null;
            case 'checkbox':
                return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
            case 'dropdown':
                $picked = is_array($v) ? $v : (str_starts_with(trim((string)$v), '[') ? (json_decode((string)$v, true) ?: []) : [(string)$v]);
                $valid = [];
                foreach ($picked as $p) {
                    $p = trim((string)$p);
                    foreach ($cfg['options'] as $o) {
                        if (strcasecmp($o['label'], $p) === 0) { $valid[$o['label']] = true; break; }
                    }
                }
                $valid = array_keys($valid);
                if (!$valid) throw new ProjectUserError('That option is not on this dropdown.');
                if (!$cfg['multi']) return $valid[0];
                return count($valid) === 1 ? $valid[0] : json_encode($valid, JSON_UNESCAPED_UNICODE);
            case 'tags':
                $list = is_array($v) ? $v : (str_starts_with(trim((string)$v), '[') ? (json_decode((string)$v, true) ?: []) : explode(',', (string)$v));
                $out = [];
                foreach ($list as $t) {
                    $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$t)), 0, 40);
                    if ($t !== '') $out[mb_strtolower($t)] = $t;
                }
                return $out ? json_encode(array_slice(array_values($out), 0, 30), JSON_UNESCAPED_UNICODE) : null;
            case 'supplier':
                $id = (int)$v;
                if ($id <= 0) return null;
                if (isset($ctx['suppliers']) && !isset($ctx['suppliers'][$id])) throw new ProjectUserError('That supplier was not found.');
                return (string)$id;
        }
        throw new ProjectUserError('Unknown column type.');
    }

    /** Stored value → what the browser works with. */
    public static function decode(array $col, $raw)
    {
        if ($raw === null || $raw === '') return null;
        $raw = (string)$raw;
        switch ((string)$col['type']) {
            case 'people':
                $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', trim($raw, "[] \t")))));
                return $ids ?: null;
            case 'timeline':
                $a = json_decode($raw, true);
                if (is_array($a) && ($s = self::date($a['start'] ?? null))) return ['start' => $s, 'end' => self::date($a['end'] ?? null) ?? $s];
                if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*(?:\||to|,|–)\s*(\d{4}-\d{2}-\d{2})$/', $raw, $m)) return ['start' => $m[1], 'end' => $m[2]];
                return null;
            case 'date':
                return self::date($raw);
            case 'number':
            case 'progress':
            case 'rating':
                $s = str_replace([' ', ','], '', $raw);
                return is_numeric($s) ? (float)$s : null;
            case 'formula':
                return is_numeric($raw) ? (float)$raw : $raw;
            case 'checkbox':
                return $raw === '1' || strtolower($raw) === 'true';
            case 'tags':
                $a = str_starts_with($raw, '[') ? json_decode($raw, true) : explode(',', $raw);
                $a = array_values(array_filter(array_map(fn($x) => trim((string)$x), is_array($a) ? $a : []), 'strlen'));
                return $a ?: null;
            case 'dropdown':
                if (str_starts_with($raw, '[')) {
                    $a = json_decode($raw, true);
                    if (is_array($a)) return array_values(array_map('strval', $a));
                }
                return [$raw];
            case 'link':
                if (str_starts_with($raw, '{')) {
                    $a = json_decode($raw, true);
                    if (is_array($a) && !empty($a['url'])) return ['url' => (string)$a['url'], 'text' => (string)($a['text'] ?? '')];
                }
                return ['url' => $raw, 'text' => ''];
            case 'supplier':
                return (int)$raw ?: null;
            case 'priority':
                $p = strtolower(trim($raw));
                return $p === 'critical' ? 'urgent' : (in_array($p, ['low', 'medium', 'high', 'urgent'], true) ? $p : null);
        }
        return $raw;
    }

    /** Plain text of a value (search, exports, notifications, activity). */
    public static function text(array $col, $raw, array $ctx = []): string
    {
        $v = self::decode($col, $raw);
        if ($v === null) return '';
        $cfg = self::config($col);
        switch ((string)$col['type']) {
            case 'status':
                $l = self::statusOf($cfg, (string)$v);
                return $l ? $l['label'] : (string)$v;
            case 'priority':
                foreach (self::PRIORITIES as $p) if ($p['key'] === $v) return $p['label'];
                return ucfirst((string)$v);
            case 'people':
                return implode(', ', array_map(fn($id) => $ctx['userNames'][$id] ?? ('User ' . $id), $v));
            case 'timeline':
                return $v['start'] === $v['end'] ? $v['start'] : $v['start'] . ' – ' . $v['end'];
            case 'checkbox':
                return $v ? 'Yes' : 'No';
            case 'tags':
            case 'dropdown':
                return implode(', ', $v);
            case 'link':
                return $v['text'] ?: $v['url'];
            case 'number':
            case 'formula':
                if (!is_float($v)) return (string)$v;
                $p = (int)($cfg['precision'] ?? 2);
                $s = number_format($v, $p, '.', ' ');
                if (($cfg['format'] ?? '') === 'percent') return $s . '%';
                $u = (string)($cfg['unit'] ?? '');
                if ($u === '') return $s;
                return ($cfg['unit_pos'] ?? 'prefix') === 'suffix' ? $s . ' ' . $u : $u . ' ' . $s;
            case 'progress':
                return (int)$v . '%';
            case 'rating':
                return str_repeat('★', (int)$v);
            case 'supplier':
                return $ctx['supplierNames'][$v] ?? ('Supplier ' . $v);
        }
        return is_scalar($v) ? (string)$v : '';
    }

    /** The old boards' built-in keys and the label texts they mean. */
    private const STATUS_ALIASES = [
        'todo' => ['to do', 'not started', 'open', 'new'],
        'working' => ['working on it', 'in progress', 'busy', 'working'],
        'stuck' => ['stuck', 'blocked', 'on hold'],
        'done' => ['done', 'complete', 'completed', 'finished'],
    ];

    public static function statusOf(array $cfg, string $key): ?array
    {
        foreach ($cfg['labels'] ?? [] as $l) {
            if ($l['key'] === $key) return $l;
        }
        foreach ($cfg['labels'] ?? [] as $l) {
            if (strcasecmp($l['key'], $key) === 0 || strcasecmp($l['label'], $key) === 0) return $l;
        }
        // A built-in key ('working') stored on a column whose labels are texts ("Working on it").
        $aliases = self::STATUS_ALIASES[strtolower(trim($key))] ?? null;
        if ($aliases) {
            foreach ($cfg['labels'] ?? [] as $l) {
                if (in_array(mb_strtolower($l['label']), $aliases, true) || in_array(mb_strtolower($l['key']), $aliases, true)) return $l;
            }
        }
        return null;
    }

    public static function date($v): ?string
    {
        $v = is_scalar($v) ? substr(trim((string)$v), 0, 10) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || $v === '0000-00-00') return null;
        return $v;
    }

    private static function str($v, int $max): ?string
    {
        if (!is_scalar($v)) return null;
        $s = trim(str_replace("\0", '', (string)$v));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    // ======================================================================= mirrors

    /**
     * The item's own fields that other modules read (Calendar, Home, My work,
     * reminders): status_label, assigned_to, priority, progress, start/end/due
     * date — taken from the board's first column of each kind.
     * @param array $cols board columns (ordered); $values column_id => raw value
     * @return array<string, mixed> board_items field => value
     */
    public static function mirrors(array $cols, array $values, ?float $subitemDone = null): array
    {
        $m = ['status_label' => null, 'assigned_to' => null, 'priority' => 'medium', 'progress' => 0,
              'start_date' => null, 'end_date' => null, 'due_date' => null];
        $done = false;
        $have = [];
        $dateCol = self::primaryDateColumn($cols);
        foreach ($cols as $c) {
            $t = (string)$c['type'];
            $raw = $values[(int)$c['column_id']] ?? null;
            if (isset($have[$t]) && $t !== 'progress') continue;
            switch ($t) {
                case 'status':
                    $have[$t] = true;
                    if ($raw !== null && $raw !== '') {
                        $l = self::statusOf(self::config($c), (string)$raw);
                        $m['status_label'] = mb_substr($l ? $l['label'] : (string)$raw, 0, 100);
                        $done = $l ? !empty($l['done']) : (bool)preg_match(self::DONE_WORDS, (string)$raw);
                    }
                    break;
                case 'people':
                    $have[$t] = true;
                    $ids = self::decode($c, $raw);
                    $m['assigned_to'] = $ids ? (int)$ids[0] : null;
                    break;
                case 'priority':
                    $have[$t] = true;
                    if ($p = self::decode($c, $raw)) $m['priority'] = $p;
                    break;
                case 'timeline':
                    $have[$t] = true;
                    if ($tl = self::decode($c, $raw)) { $m['start_date'] = $tl['start']; $m['end_date'] = $tl['end']; }
                    break;
                case 'progress':
                    if (isset($have[$t])) break;
                    $have[$t] = true;
                    $p = self::progressValue($c, $cols, $values, $subitemDone);
                    if ($p !== null) $m['progress'] = (int)round($p);
                    break;
            }
        }
        if ($dateCol) $m['due_date'] = self::date($values[(int)$dateCol['column_id']] ?? null);
        if (!$m['due_date'] && $m['end_date']) $m['due_date'] = $m['end_date'];
        if ($done) $m['progress'] = 100;
        return $m;
    }

    /** The date column that counts as the item's due date: one named like a deadline, else the first. */
    public static function primaryDateColumn(array $cols): ?array
    {
        $first = null;
        foreach ($cols as $c) {
            if ($c['type'] !== 'date') continue;
            if (preg_match('/due|deadline|target|finish|end|complet/i', (string)$c['name'])) return $c;
            $first = $first ?? $c;
        }
        return $first;
    }

    /** Progress column value: typed, or the share of done statuses / subitems. */
    public static function progressValue(array $col, array $cols, array $values, ?float $subitemDone): ?float
    {
        $cfg = self::config($col);
        if ($cfg['mode'] === 'subitems') return $subitemDone;
        if ($cfg['mode'] === 'status') {
            $sources = $cfg['sources'];
            $total = 0;
            $done = 0;
            foreach ($cols as $c) {
                if ($c['type'] !== 'status') continue;
                if ($sources && !in_array((int)$c['column_id'], $sources, true)) continue;
                $total++;
                $raw = $values[(int)$c['column_id']] ?? null;
                if ($raw !== null && $raw !== '' && ($l = self::statusOf(self::config($c), (string)$raw)) && !empty($l['done'])) $done++;
            }
            return $total ? round($done * 100 / $total) : null;
        }
        $v = $values[(int)$col['column_id']] ?? null;
        return is_numeric($v) ? max(0.0, min(100.0, (float)$v)) : null;
    }
}
