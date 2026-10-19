<?php
// crm/lib/Crm.php
//
// The CRM's shared vocabulary and configuration:
//   - roles: who may read, write and change settings,
//   - the company's look and wording (labels, accent, statuses, which parts of
//     the CRM are switched on, the overview layout, thresholds) and each
//     person's own layout (density, text size, list columns, saved views),
//     stored in crm_prefs (user_id 0 = the company),
//   - the company's deal stages (crm_pipeline_stages, with built-in defaults),
//   - company rules that predate crm_prefs and stay in company_settings,
//   - small helpers: people in the company, phone numbers, audit log.

require_once __DIR__ . '/CrmSchema.php';

final class CrmUserError extends RuntimeException {}
final class CrmForbidden extends RuntimeException {}

final class Crm
{
    public const TYPES = ['customer', 'supplier'];
    public const STATUSES = ['active', 'prospect', 'inactive', 'banned'];
    public const ACTIVITY = ['note', 'call', 'email', 'meeting', 'visit', 'whatsapp', 'issue'];
    public const ADDRESS_TYPES = ['head_office' => 'Head office', 'billing' => 'Billing', 'shipping' => 'Delivery', 'site' => 'Site'];

    public const DEFAULT_STAGES = [
        ['key' => 'prospect',      'label' => 'Prospect',    'color' => '#8a6fb8', 'probability' => 10,  'kind' => 'open'],
        ['key' => 'qualification', 'label' => 'Qualifying',  'color' => '#4f7cac', 'probability' => 25,  'kind' => 'open'],
        ['key' => 'proposal',      'label' => 'Proposal',    'color' => '#2f8f8a', 'probability' => 50,  'kind' => 'open'],
        ['key' => 'negotiation',   'label' => 'Negotiation', 'color' => '#b08d57', 'probability' => 75,  'kind' => 'open'],
        ['key' => 'won',           'label' => 'Won',         'color' => '#2f7457', 'probability' => 100, 'kind' => 'won'],
        ['key' => 'lost',          'label' => 'Lost',        'color' => '#a8281e', 'probability' => 0,   'kind' => 'lost'],
    ];

    /** Overview panels, in their default order. */
    public const OVERVIEW_PANELS = [
        'kpis'       => 'Key numbers',
        'funnel'     => 'Pipeline by stage',
        'forecast'   => 'Forecast',
        'tasks'      => 'My tasks',
        'attention'  => 'Deals needing attention',
        'cold'       => 'Going cold',
        'top'        => 'Top customers',
        'compliance' => 'Compliance',
        'activity'   => 'Recent activity',
    ];

    /** Account page tabs, in their default order. */
    public const ACCOUNT_TABS = [
        'activity'   => 'Activity',
        'contacts'   => 'Contacts',
        'deals'      => 'Deals',
        'documents'  => 'Documents',
        'compliance' => 'Compliance',
        'details'    => 'Details',
    ];

    /** Columns the account lists can show (custom fields add cf_<id>). */
    public const LIST_COLUMNS = [
        'contact'       => 'Primary contact',
        'email'         => 'Email',
        'phone'         => 'Phone',
        'owner'         => 'Owner',
        'tags'          => 'Tags',
        'industry'      => 'Industry',
        'region'        => 'Region',
        'city'          => 'City',
        'balance'       => 'Balance',
        'revenue'       => 'Billed (12 months)',
        'open_deals'    => 'Open deals',
        'compliance'    => 'Compliance',
        'last_activity' => 'Last activity',
        'vat_no'        => 'VAT number',
        'created'       => 'Added',
        'status'        => 'Status',
    ];

    public const COMPANY_DEFAULTS = [
        'labels' => [
            'crm' => 'CRM',
            'customer' => 'Customer', 'customers' => 'Customers',
            'supplier' => 'Supplier', 'suppliers' => 'Suppliers',
            'deal' => 'Deal', 'deals' => 'Deals', 'pipeline' => 'Pipeline',
            'contact' => 'Contact', 'contacts' => 'Contacts',
            'task' => 'Task', 'tasks' => 'Tasks',
        ],
        'modules' => [
            'customers' => true, 'suppliers' => true, 'pipeline' => true, 'tasks' => true,
            'contacts' => true, 'activity' => true, 'compliance' => true,
        ],
        'accent' => '',
        'statuses' => [
            'active'   => ['label' => 'Active',   'color' => '#2f7457'],
            'prospect' => ['label' => 'Prospect', 'color' => '#4f7cac'],
            'inactive' => ['label' => 'Inactive', 'color' => '#85766b'],
            'banned'   => ['label' => 'Blocked',  'color' => '#a8281e'],
        ],
        'activity' => [
            'note' => true, 'call' => true, 'email' => true, 'meeting' => true,
            'visit' => true, 'whatsapp' => true, 'issue' => true,
        ],
        'overview' => ['kpis', 'funnel', 'forecast', 'tasks', 'attention', 'cold', 'top', 'compliance', 'activity'],
        'overview_off' => [],
        'account_tabs' => ['activity', 'contacts', 'deals', 'documents', 'compliance', 'details'],
        'account_tabs_off' => [],
        'cold_days' => 60,
        'stale_days' => 21,
        'loss_reasons' => ['Price', 'Went with a competitor', 'No budget', 'No decision', 'Timing', 'Other'],
        'required' => [],
    ];

    public const USER_DEFAULTS = [
        'density' => 'comfortable',
        'text' => 'md',
        'accent' => '',
        'landing' => 'overview',
        'list_view' => ['customers' => 'table', 'suppliers' => 'table'],
        'columns' => [
            'customers' => ['contact', 'phone', 'tags', 'balance', 'open_deals', 'last_activity', 'status'],
            'suppliers' => ['contact', 'phone', 'tags', 'compliance', 'balance', 'last_activity', 'status'],
        ],
        'pipeline_view' => 'board',
        'pipeline_mine' => false,
        'views' => [],
    ];

    /** Rules kept in company_settings (read by older code too) with their defaults. */
    public const RULES = [
        'crm_default_customer_status' => 'active',
        'crm_default_supplier_status' => 'active',
        'crm_require_vat_number'      => '0',
        'crm_require_reg_number'      => '0',
        'crm_enable_duplicate_check'  => '1',
        'crm_duplicate_threshold'     => '0.85',
        'crm_enable_tags'             => '1',
        'crm_compliance_badge_enable' => '1',
        'crm_block_expired_suppliers' => '0',
        'crm_reminder_days'           => '30,14,7',
        'crm_default_currency'        => '',
        'crm_phone_format'            => 'international',
        'crm_email_template_welcome'  => '',
        'crm_email_template_rfq'      => '',
        'crm_email_template_reminder' => '',
    ];

    /** Fields a company may make compulsory on customers and suppliers. */
    public const REQUIRABLE = [
        'email' => 'Email', 'phone' => 'Phone', 'vat_no' => 'VAT number', 'reg_no' => 'Registration number',
        'industry_id' => 'Industry', 'region_id' => 'Region', 'owner_id' => 'Owner', 'contact' => 'A contact person',
    ];

    // ------------------------------------------------------------------ roles

    public static function canWrite(string $role): bool
    {
        return !in_array(strtolower($role), ['viewer', 'pos', ''], true);
    }

    /** Admins (and bookkeepers, as in Quotes & Invoices) manage settings, owners and merges. */
    public static function isAdmin(string $role): bool
    {
        return in_array(strtolower($role), ['admin', 'owner', 'bookkeeper'], true);
    }

    // ------------------------------------------------------- company & people

    /** The company's look and wording, merged over the defaults. */
    public static function config(PDO $db, int $companyId): array
    {
        if (isset(self::$cache['config:' . $companyId])) return self::$cache['config:' . $companyId];
        $saved = self::readPrefs($db, $companyId, 0);
        $c = self::COMPANY_DEFAULTS;
        foreach (['labels', 'modules', 'activity'] as $k) {
            if (isset($saved[$k]) && is_array($saved[$k])) {
                foreach ($c[$k] as $key => $def) {
                    if (array_key_exists($key, $saved[$k])) {
                        $c[$k][$key] = is_bool($def) ? (bool)$saved[$k][$key] : (trim((string)$saved[$k][$key]) ?: $def);
                    }
                }
            }
        }
        if (isset($saved['statuses']) && is_array($saved['statuses'])) {
            foreach ($c['statuses'] as $key => $def) {
                $s = $saved['statuses'][$key] ?? null;
                if (!is_array($s)) continue;
                $c['statuses'][$key] = [
                    'label' => trim((string)($s['label'] ?? '')) ?: $def['label'],
                    'color' => self::color($s['color'] ?? '') ?: $def['color'],
                ];
            }
        }
        $c['accent'] = self::color($saved['accent'] ?? '') ?: '';
        $c['overview'] = self::ordered($saved['overview'] ?? null, array_keys(self::OVERVIEW_PANELS));
        $c['overview_off'] = array_values(array_intersect((array)($saved['overview_off'] ?? []), array_keys(self::OVERVIEW_PANELS)));
        $c['account_tabs'] = self::ordered($saved['account_tabs'] ?? null, array_keys(self::ACCOUNT_TABS));
        $c['account_tabs_off'] = array_values(array_diff(array_intersect((array)($saved['account_tabs_off'] ?? []), array_keys(self::ACCOUNT_TABS)), ['activity']));
        $c['cold_days'] = max(7, min(365, (int)($saved['cold_days'] ?? 60)));
        $c['stale_days'] = max(3, min(180, (int)($saved['stale_days'] ?? 21)));
        if (isset($saved['loss_reasons']) && is_array($saved['loss_reasons'])) {
            $r = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), $saved['loss_reasons']))));
            $c['loss_reasons'] = array_slice($r, 0, 20);
        }
        $c['required'] = array_values(array_intersect((array)($saved['required'] ?? []), array_keys(self::REQUIRABLE)));
        // The older VAT / registration switches still count.
        $rules = self::rules($db, $companyId);
        if ($rules['crm_require_vat_number'] === '1' && !in_array('vat_no', $c['required'], true)) $c['required'][] = 'vat_no';
        if ($rules['crm_require_reg_number'] === '1' && !in_array('reg_no', $c['required'], true)) $c['required'][] = 'reg_no';
        return self::$cache['config:' . $companyId] = $c;
    }

    /** Each person's own layout, merged over the defaults. */
    public static function userPrefs(PDO $db, int $companyId, int $userId): array
    {
        $saved = self::readPrefs($db, $companyId, $userId);
        $p = self::USER_DEFAULTS;
        $p['density'] = in_array($saved['density'] ?? '', ['comfortable', 'compact'], true) ? $saved['density'] : 'comfortable';
        $p['text'] = in_array($saved['text'] ?? '', ['sm', 'md', 'lg'], true) ? $saved['text'] : 'md';
        $p['accent'] = self::color($saved['accent'] ?? '') ?: '';
        $p['landing'] = in_array($saved['landing'] ?? '', ['overview', 'customers', 'suppliers', 'pipeline', 'tasks', 'contacts', 'activity'], true) ? $saved['landing'] : 'overview';
        foreach (['customers', 'suppliers'] as $list) {
            $v = $saved['list_view'][$list] ?? null;
            if (in_array($v, ['table', 'cards'], true)) $p['list_view'][$list] = $v;
            if (isset($saved['columns'][$list]) && is_array($saved['columns'][$list])) {
                $cols = array_values(array_unique(array_filter(array_map('strval', $saved['columns'][$list]),
                    fn($k) => isset(self::LIST_COLUMNS[$k]) || preg_match('/^cf_\d+$/', $k))));
                $p['columns'][$list] = array_slice($cols, 0, 14);
            }
        }
        $p['pipeline_view'] = in_array($saved['pipeline_view'] ?? '', ['board', 'list'], true) ? $saved['pipeline_view'] : 'board';
        $p['pipeline_mine'] = !empty($saved['pipeline_mine']);
        $views = [];
        foreach ((array)($saved['views'] ?? []) as $v) {
            if (!is_array($v)) continue;
            $name = mb_substr(trim((string)($v['name'] ?? '')), 0, 40);
            $route = (string)($v['route'] ?? '');
            if ($name === '' || !in_array($route, ['customers', 'suppliers', 'pipeline', 'tasks', 'contacts', 'activity'], true)) continue;
            $views[] = ['id' => preg_replace('/[^a-z0-9]/i', '', (string)($v['id'] ?? '')) ?: bin2hex(random_bytes(4)),
                        'name' => $name, 'route' => $route, 'query' => mb_substr((string)($v['query'] ?? ''), 0, 500)];
        }
        $p['views'] = array_slice($views, 0, 20);
        return $p;
    }

    public static function readPrefs(PDO $db, int $companyId, int $userId): array
    {
        try {
            $st = $db->prepare("SELECT prefs_json FROM crm_prefs WHERE company_id = ? AND user_id = ?");
            $st->execute([$companyId, $userId]);
            $v = json_decode((string)($st->fetchColumn() ?: ''), true);
            return is_array($v) ? $v : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Merge $changes into the stored prefs (top-level keys replace). */
    public static function writePrefs(PDO $db, int $companyId, int $userId, array $changes): void
    {
        $cur = self::readPrefs($db, $companyId, $userId);
        foreach ($changes as $k => $v) {
            if ($v === null) unset($cur[$k]);
            else $cur[$k] = $v;
        }
        $json = json_encode($cur, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 200000) throw new CrmUserError('These settings are too large to save.');
        $db->prepare("INSERT INTO crm_prefs (company_id, user_id, prefs_json, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json), updated_at = NOW()")
           ->execute([$companyId, $userId, $json]);
    }

    /** Company rules from company_settings (string values, defaults filled in). */
    public static function rules(PDO $db, int $companyId): array
    {
        $out = self::RULES;
        try {
            $keys = array_keys(self::RULES);
            $st = $db->prepare("SELECT setting_key, setting_value FROM company_settings
                                 WHERE company_id = ? AND setting_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
            $st->execute(array_merge([$companyId], $keys));
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $out[$k] = (string)$v;
        } catch (Throwable $e) {
            // defaults
        }
        return $out;
    }

    public static function setRule(PDO $db, int $companyId, string $key, string $value): void
    {
        if (!array_key_exists($key, self::RULES)) throw new CrmUserError('Unknown setting');
        $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
           ->execute([$companyId, $key, $value]);
    }

    /** The company's deal stages in board order (built-in defaults until the company edits them). */
    public static function stages(PDO $db, int $companyId): array
    {
        if (isset(self::$cache['stages:' . $companyId])) return self::$cache['stages:' . $companyId];
        $rows = [];
        try {
            $st = $db->prepare("SELECT stage_key, label, color, probability, kind FROM crm_pipeline_stages WHERE company_id = ? ORDER BY sort_order, id");
            $st->execute([$companyId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }
        if (!$rows) return self::$cache['stages:' . $companyId] = self::DEFAULT_STAGES;
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['key' => (string)$r['stage_key'], 'label' => (string)$r['label'], 'color' => (string)$r['color'],
                      'probability' => (int)$r['probability'], 'kind' => (string)$r['kind']];
        }
        // won and lost always exist
        foreach (['won', 'lost'] as $k) {
            if (!array_filter($out, fn($s) => $s['key'] === $k)) {
                foreach (self::DEFAULT_STAGES as $d) if ($d['key'] === $k) $out[] = $d;
            }
        }
        return self::$cache['stages:' . $companyId] = $out;
    }

    /** Forget cached config / stages (after a settings save, and in tests). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Per-request memo: 'config:<cid>', 'stages:<cid>', 'people:<cid>', 'company:<cid>'. */
    private static array $cache = [];

    public static function stageMap(PDO $db, int $companyId): array
    {
        $m = [];
        foreach (self::stages($db, $companyId) as $s) $m[$s['key']] = $s;
        return $m;
    }

    public static function openStageKeys(PDO $db, int $companyId): array
    {
        return array_values(array_map(fn($s) => $s['key'], array_filter(self::stages($db, $companyId), fn($s) => $s['kind'] === 'open')));
    }

    /** Active people in the company: [id => ['id', 'name', 'email']]. */
    public static function people(PDO $db, int $companyId): array
    {
        if (isset(self::$cache['people:' . $companyId])) return self::$cache['people:' . $companyId];
        $st = $db->prepare("SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
                              FROM users u LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
                             WHERE (u.company_id = ? OR uc.company_id = ?) AND u.status = 'active'
                          ORDER BY u.first_name, u.last_name");
        $st->execute([$companyId, $companyId, $companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $name = trim($u['first_name'] . ' ' . $u['last_name']);
            $out[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => $name !== '' ? $name : (string)$u['email'], 'email' => (string)$u['email']];
        }
        return self::$cache['people:' . $companyId] = $out;
    }

    public static function personName(PDO $db, int $companyId, ?int $id): string
    {
        if (!$id) return '';
        $p = self::people($db, $companyId);
        if (isset($p[$id])) return $p[$id]['name'];
        $st = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE id = ?");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: 'Former member');
    }

    // ---------------------------------------------------------------- helpers

    /** '#rrggbb' or '' */
    public static function color($v): string
    {
        $v = trim((string)$v);
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '';
    }

    /** Keep the saved order of known keys and append any new ones. */
    private static function ordered($saved, array $all): array
    {
        $out = [];
        foreach ((array)$saved as $k) if (in_array($k, $all, true) && !in_array($k, $out, true)) $out[] = $k;
        foreach ($all as $k) if (!in_array($k, $out, true)) $out[] = $k;
        return $out;
    }

    /** Normalise a phone number: digits with an optional leading +, following the company's format. */
    public static function phone(string $raw, string $format = 'international'): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return mb_substr($raw, 0, 64);
        if (!$plus && str_starts_with($digits, '00')) { $plus = true; $digits = substr($digits, 2); }
        if ($format === 'national') {
            if ($plus && str_starts_with($digits, '27') && strlen($digits) === 11) return '0' . substr($digits, 2);
            return ($plus ? '+' : '') . $digits;
        }
        if (!$plus && str_starts_with($digits, '0') && strlen($digits) === 10) return '+27' . substr($digits, 1);
        if (!$plus && str_starts_with($digits, '27') && strlen($digits) === 11) return '+' . $digits;
        return ($plus ? '+' : '') . $digits;
    }

    /** Run $fn in a transaction, or inside the caller's when one is already open. */
    public static function atomic(PDO $db, callable $fn)
    {
        if ($db->inTransaction()) return $fn();
        $db->beginTransaction();
        try {
            $r = $fn();
            $db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    public static function audit(PDO $db, int $companyId, int $userId, string $action, string $entityType, int $entityId, array $details = []): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $userId, $action, $entityType, $entityId,
                          json_encode($details, JSON_UNESCAPED_UNICODE), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('[crm] audit: ' . $e->getMessage());
        }
    }

    /** A company row's name (cached). */
    public static function companyName(PDO $db, int $companyId): string
    {
        if (isset(self::$cache['company:' . $companyId])) return self::$cache['company:' . $companyId];
        $st = $db->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        return self::$cache['company:' . $companyId] = (string)($st->fetchColumn() ?: 'Company');
    }

    /** Is a date string a real Y-m-d? */
    public static function ymd($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    }

    /** 'Y-m-d H:i[:s]' or 'Y-m-dTH:i' → 'Y-m-d H:i:s', or null. */
    public static function datetime($v): ?string
    {
        $v = str_replace('T', ' ', trim((string)$v));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) return null;
        if (!self::ymd($m[1]) || (int)$m[2] > 23 || (int)$m[3] > 59) return null;
        return sprintf('%s %02d:%02d:%02d', $m[1], (int)$m[2], (int)$m[3], (int)($m[4] ?? 0));
    }
}
