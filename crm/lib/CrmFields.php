<?php
// crm/lib/CrmFields.php
//
// Fields a company adds to its customers, suppliers, contacts and deals.
// Definitions live in crm_custom_fields; values in crm_custom_values (one row
// per field and record, stored as text: dates as Y-m-d, numbers as plain
// decimals, multi-choice as a JSON list, checkboxes as '1' / '0').

require_once __DIR__ . '/Crm.php';

final class CrmFields
{
    public const ENTITIES = ['account', 'contact', 'deal'];
    public const TYPES = [
        'text'        => 'Short text',
        'textarea'    => 'Long text',
        'number'      => 'Number',
        'money'       => 'Amount (R)',
        'date'        => 'Date',
        'select'      => 'Dropdown',
        'multiselect' => 'Multiple choice',
        'checkbox'    => 'Yes / no',
        'url'         => 'Link',
        'email'       => 'Email',
        'phone'       => 'Phone',
    ];

    /** Field definitions for an entity (archived ones left out unless asked). */
    public static function defs(PDO $db, int $companyId, ?string $entity = null, bool $withArchived = false): array
    {
        $key = $companyId . ':' . ($entity ?? '*') . ':' . (int)$withArchived;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $sql = "SELECT * FROM crm_custom_fields WHERE company_id = ?";
        $params = [$companyId];
        if ($entity !== null) { $sql .= " AND entity = ?"; $params[] = $entity; }
        if (!$withArchived) $sql .= " AND archived = 0";
        try {
            $st = $db->prepare($sql . " ORDER BY entity, sort_order, id");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }
        return self::$cache[$key] = array_map([self::class, 'shape'], $rows);
    }

    /** Forget cached definitions (after they change). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    private static array $cache = [];

    public static function shape(array $r): array
    {
        $opts = json_decode((string)($r['options_json'] ?? ''), true);
        return [
            'id' => (int)$r['id'],
            'entity' => (string)$r['entity'],
            'applies_to' => (string)$r['applies_to'],
            'key' => (string)$r['field_key'],
            'label' => (string)$r['label'],
            'type' => isset(self::TYPES[$r['field_type']]) ? (string)$r['field_type'] : 'text',
            'options' => is_array($opts) ? array_values(array_map('strval', $opts)) : [],
            'help' => (string)($r['help'] ?? ''),
            'required' => (bool)$r['required'],
            'show_in_list' => (bool)$r['show_in_list'],
            'archived' => (bool)$r['archived'],
        ];
    }

    /** Definitions that apply to an account of $type ('customer' / 'supplier'). */
    public static function forAccountType(PDO $db, int $companyId, string $type): array
    {
        return array_values(array_filter(self::defs($db, $companyId, 'account'),
            fn($f) => $f['applies_to'] === 'all' || $f['applies_to'] === $type));
    }

    /** Values for records: [entity_id => [field_id => display-ready value]]. */
    public static function values(PDO $db, int $companyId, array $fieldIds, array $entityIds): array
    {
        $fieldIds = array_values(array_unique(array_map('intval', $fieldIds)));
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        if (!$fieldIds || !$entityIds) return [];
        $st = $db->prepare("SELECT field_id, entity_id, value FROM crm_custom_values
                             WHERE company_id = ? AND field_id IN (" . implode(',', $fieldIds) . ")
                               AND entity_id IN (" . implode(',', $entityIds) . ")");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['entity_id']][(int)$r['field_id']] = $r['value'];
        }
        return $out;
    }

    /** Values for one record keyed by field id, decoded (multiselect → array, checkbox → bool). */
    public static function valuesFor(PDO $db, int $companyId, array $defs, int $entityId): array
    {
        $raw = self::values($db, $companyId, array_column($defs, 'id'), [$entityId])[$entityId] ?? [];
        $out = [];
        foreach ($defs as $f) $out[$f['id']] = self::decode($f, $raw[$f['id']] ?? null);
        return $out;
    }

    public static function decode(array $f, $v)
    {
        if ($v === null) return $f['type'] === 'multiselect' ? [] : ($f['type'] === 'checkbox' ? false : '');
        if ($f['type'] === 'multiselect') { $a = json_decode((string)$v, true); return is_array($a) ? $a : []; }
        if ($f['type'] === 'checkbox') return $v === '1';
        return (string)$v;
    }

    /** Human text for lists and exports. */
    public static function display(array $f, $v): string
    {
        $v = self::decode($f, $v);
        if ($f['type'] === 'multiselect') return implode(', ', $v);
        if ($f['type'] === 'checkbox') return $v ? 'Yes' : 'No';
        if ($f['type'] === 'money' && $v !== '') return 'R ' . number_format((float)$v, 2, '.', ',');
        return (string)$v;
    }

    /**
     * Validate submitted values ([field_id or key => value]) against $defs.
     * @return array<int, ?string> field_id => stored text (null = clear)
     * @throws CrmUserError with a message naming the field
     */
    public static function validate(array $defs, array $input, bool $creating): array
    {
        $byKey = [];
        foreach ($input as $k => $v) $byKey[(string)$k] = $v;
        $out = [];
        foreach ($defs as $f) {
            $has = array_key_exists((string)$f['id'], $byKey) || array_key_exists($f['key'], $byKey);
            if (!$has) {
                if ($creating && $f['required'] && $f['type'] !== 'checkbox') throw new CrmUserError($f['label'] . ' is required.');
                continue;
            }
            $v = array_key_exists((string)$f['id'], $byKey) ? $byKey[(string)$f['id']] : $byKey[$f['key']];
            $out[$f['id']] = self::clean($f, $v);
            if ($f['required'] && $f['type'] !== 'checkbox' && ($out[$f['id']] === null || $out[$f['id']] === '' || $out[$f['id']] === '[]')) {
                throw new CrmUserError($f['label'] . ' is required.');
            }
        }
        return $out;
    }

    public static function clean(array $f, $v): ?string
    {
        $label = $f['label'];
        if ($f['type'] === 'multiselect') {
            $list = is_array($v) ? $v : (trim((string)$v) === '' ? [] : array_map('trim', explode(',', (string)$v)));
            $list = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $list), fn($x) => $x !== '')));
            foreach ($list as $x) if ($f['options'] && !in_array($x, $f['options'], true)) throw new CrmUserError("“{$x}” is not a choice for {$label}.");
            return $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($f['type'] === 'checkbox') {
            return (is_bool($v) ? $v : in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'y', 'on', 'ja'], true)) ? '1' : '0';
        }
        if (is_array($v)) throw new CrmUserError("{$label} has an invalid value.");
        $s = trim((string)$v);
        if ($s === '') return null;
        switch ($f['type']) {
            case 'number':
            case 'money':
                $n = str_replace([' ', ',', 'R'], ['', '', ''], $s);
                if (!is_numeric($n)) throw new CrmUserError("{$label} must be a number.");
                return (string)round((float)$n, $f['type'] === 'money' ? 2 : 6);
            case 'date':
                $d = Crm::ymd($s) ?? (($t = strtotime($s)) ? date('Y-m-d', $t) : null);
                if ($d === null) throw new CrmUserError("{$label} must be a date.");
                return $d;
            case 'select':
                if ($f['options'] && !in_array($s, $f['options'], true)) throw new CrmUserError("“{$s}” is not a choice for {$label}.");
                return mb_substr($s, 0, 255);
            case 'url':
                if (!preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
                if (!filter_var($s, FILTER_VALIDATE_URL)) throw new CrmUserError("{$label} must be a web address.");
                return mb_substr($s, 0, 500);
            case 'email':
                if (!filter_var($s, FILTER_VALIDATE_EMAIL)) throw new CrmUserError("{$label} must be an email address.");
                return mb_substr(strtolower($s), 0, 190);
            case 'phone':
                return mb_substr($s, 0, 64);
            case 'textarea':
                return mb_substr($s, 0, 5000);
            default:
                return mb_substr($s, 0, 500);
        }
    }

    /** Store validated values for one record. */
    public static function save(PDO $db, int $companyId, int $entityId, array $values): void
    {
        if (!$values) return;
        $up = $db->prepare("INSERT INTO crm_custom_values (field_id, entity_id, company_id, value, updated_at) VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()");
        $del = $db->prepare("DELETE FROM crm_custom_values WHERE field_id = ? AND entity_id = ? AND company_id = ?");
        foreach ($values as $fieldId => $v) {
            if ($v === null) $del->execute([(int)$fieldId, $entityId, $companyId]);
            else $up->execute([(int)$fieldId, $entityId, $companyId, $v]);
        }
    }

    /** Remove a record's values (when the record itself goes). */
    public static function forget(PDO $db, int $companyId, string $entity, int $entityId): void
    {
        $db->prepare("DELETE v FROM crm_custom_values v JOIN crm_custom_fields f ON f.id = v.field_id
                       WHERE v.company_id = ? AND v.entity_id = ? AND f.entity = ?")
           ->execute([$companyId, $entityId, $entity]);
    }

    /** Move values from one record to another (merge), keeping the winner's where both have one. */
    public static function move(PDO $db, int $companyId, string $entity, int $fromId, int $toId): void
    {
        $db->prepare("INSERT IGNORE INTO crm_custom_values (field_id, entity_id, company_id, value, updated_at)
                      SELECT v.field_id, ?, v.company_id, v.value, NOW() FROM crm_custom_values v
                        JOIN crm_custom_fields f ON f.id = v.field_id AND f.entity = ?
                       WHERE v.company_id = ? AND v.entity_id = ?")
           ->execute([$toId, $entity, $companyId, $fromId]);
        self::forget($db, $companyId, $entity, $fromId);
    }

    // ------------------------------------------------------------ definitions

    /** Create or update a definition. Returns its id. */
    public static function saveDef(PDO $db, int $companyId, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 80);
        if ($label === '') throw new CrmUserError('Give the field a name.');
        $type = (string)($in['type'] ?? 'text');
        if (!isset(self::TYPES[$type])) throw new CrmUserError('Choose a field type.');
        $entity = (string)($in['entity'] ?? 'account');
        if (!in_array($entity, self::ENTITIES, true)) throw new CrmUserError('Choose where the field appears.');
        $applies = in_array($in['applies_to'] ?? '', ['all', 'customer', 'supplier'], true) ? $in['applies_to'] : 'all';
        if ($entity !== 'account') $applies = 'all';
        $options = [];
        if (in_array($type, ['select', 'multiselect'], true)) {
            $raw = is_array($in['options'] ?? null) ? $in['options'] : preg_split('/\r\n|\n|,/', (string)($in['options'] ?? ''));
            $options = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $raw), fn($x) => $x !== '')));
            if (!$options) throw new CrmUserError('Add at least one choice.');
            if (count($options) > 100) throw new CrmUserError('A field can have at most 100 choices.');
        }
        $help = mb_substr(trim((string)($in['help'] ?? '')), 0, 255);
        $required = !empty($in['required']) ? 1 : 0;
        $inList = !empty($in['show_in_list']) ? 1 : 0;

        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM crm_custom_fields WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new CrmUserError('Field not found.');
            // The type of a field with values can only change between compatible text types.
            if ($cur['field_type'] !== $type) {
                $n = $db->prepare("SELECT COUNT(*) FROM crm_custom_values WHERE field_id = ? AND company_id = ?");
                $n->execute([$id, $companyId]);
                $textish = ['text', 'textarea', 'url', 'email', 'phone', 'select'];
                if ((int)$n->fetchColumn() > 0 && !(in_array($cur['field_type'], $textish, true) && in_array($type, ['text', 'textarea'], true))) {
                    throw new CrmUserError('This field already has values, so its type can only change to text.');
                }
            }
            $db->prepare("UPDATE crm_custom_fields SET label = ?, field_type = ?, applies_to = ?, options_json = ?, help = ?, required = ?, show_in_list = ?
                           WHERE id = ? AND company_id = ?")
               ->execute([$label, $type, $applies, $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null, $help ?: null, $required, $inList, $id, $companyId]);
            return $id;
        }
        $n = $db->prepare("SELECT COUNT(*) FROM crm_custom_fields WHERE company_id = ? AND entity = ?");
        $n->execute([$companyId, $entity]);
        if ((int)$n->fetchColumn() >= 60) throw new CrmUserError('You can have at most 60 fields here.');
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label)), '_') ?: 'field';
        $base = substr($base, 0, 34);
        $key = $base;
        $chk = $db->prepare("SELECT 1 FROM crm_custom_fields WHERE company_id = ? AND entity = ? AND field_key = ?");
        for ($i = 2; ; $i++) {
            $chk->execute([$companyId, $entity, $key]);
            if (!$chk->fetchColumn()) break;
            $key = $base . '_' . $i;
        }
        $max = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM crm_custom_fields WHERE company_id = ? AND entity = ?");
        $max->execute([$companyId, $entity]);
        $db->prepare("INSERT INTO crm_custom_fields (company_id, entity, applies_to, field_key, label, field_type, options_json, help, required, show_in_list, sort_order, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$companyId, $entity, $applies, $key, $label, $type, $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                      $help ?: null, $required, $inList, (int)$max->fetchColumn() + 10]);
        return (int)$db->lastInsertId();
    }

    /** Archive (keeps values) or delete (removes values) a definition. */
    public static function removeDef(PDO $db, int $companyId, int $id, bool $deleteValues): void
    {
        if ($deleteValues) {
            $db->prepare("DELETE FROM crm_custom_values WHERE field_id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->prepare("DELETE FROM crm_custom_fields WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        } else {
            $db->prepare("UPDATE crm_custom_fields SET archived = 1 WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        }
    }

    public static function restoreDef(PDO $db, int $companyId, int $id): void
    {
        $db->prepare("UPDATE crm_custom_fields SET archived = 0 WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
    }

    /** New order for an entity's fields (ids in display order). */
    public static function reorder(PDO $db, int $companyId, array $ids): void
    {
        $st = $db->prepare("UPDATE crm_custom_fields SET sort_order = ? WHERE id = ? AND company_id = ?");
        foreach (array_values($ids) as $i => $id) $st->execute([($i + 1) * 10, (int)$id, $companyId]);
    }
}
