<?php
// finances/lib/Fin.php
//
// Finance's shared vocabulary and configuration (the accounting itself lives
// in PostingService, VatCalculator and friends — nothing here posts):
//   - roles: who may see Finance, who may capture and post, who may approve,
//     and who may change company settings, close years and unlock periods,
//   - the company's look and wording (labels, accent, which parts are on,
//     the overview layout, thresholds, workflow rules) and each person's own
//     layout (density, text size, colour, landing page, money format,
//     default period, hidden panels, saved views, snoozed reminders),
//     stored in fin_prefs (user_id 0 = the company),
//   - the finance_* rules the posting engine reads from company_settings,
//   - small helpers: people, dates, money in cents, atomic(), audit.

require_once __DIR__ . '/FinSchema.php';
require_once __DIR__ . '/Audit.php';

final class FinUserError extends RuntimeException {}
final class FinForbidden extends RuntimeException {}

final class Fin
{
    /** Every screen of the app (hash routes), in sidebar order. */
    public const ROUTES = [
        'overview'    => ['label' => 'Overview',          'module' => null],
        'journals'    => ['label' => 'Journals',          'module' => null],
        'ledger'      => ['label' => 'Ledger',            'module' => null],
        'accounts'    => ['label' => 'Chart of accounts', 'module' => null],
        'bank'        => ['label' => 'Bank',              'module' => 'bank'],
        'receivables' => ['label' => 'Receivables',       'module' => 'receivables'],
        'payables'    => ['label' => 'Payables',          'module' => 'payables'],
        'vat'         => ['label' => 'VAT',               'module' => 'vat'],
        'assets'      => ['label' => 'Fixed assets',      'module' => 'assets'],
        'payroll'     => ['label' => 'Payroll',           'module' => 'payroll'],
        'budgets'     => ['label' => 'Budgets',           'module' => 'budgets'],
        'reports'     => ['label' => 'Reports',           'module' => null],
        'close'       => ['label' => 'Close & year end',  'module' => null],
    ];

    /** Overview panels, in their default order. */
    public const OVERVIEW_PANELS = [
        'kpis'      => 'Key numbers',
        'cashflow'  => 'Cash flow & forecast',
        'attention' => 'Needs attention',
        'aging'     => 'Who owes whom (aging)',
        'profit'    => 'Income vs expenses',
        'spend'     => 'Where the money went',
        'banks'     => 'Bank accounts',
        'vat'       => 'VAT position',
        'activity'  => 'Recent postings',
    ];

    public const COMPANY_DEFAULTS = [
        'labels' => [
            'finance' => 'Finance',
            'journals' => 'Journals', 'journal' => 'Journal',
            'ledger' => 'Ledger',
            'accounts' => 'Chart of accounts', 'account' => 'Account',
            'bank' => 'Bank',
            'receivables' => 'Receivables', 'payables' => 'Payables',
            'customer' => 'Customer', 'customers' => 'Customers',
            'supplier' => 'Supplier', 'suppliers' => 'Suppliers',
            'bill' => 'Bill', 'bills' => 'Bills',
            'vat' => 'VAT',
            'assets' => 'Fixed assets', 'asset' => 'Asset',
            'payroll' => 'Payroll',
            'budgets' => 'Budgets',
            'reports' => 'Reports',
            'close' => 'Close & year end',
        ],
        'modules' => [
            'bank' => true, 'receivables' => true, 'payables' => true, 'vat' => true,
            'assets' => true, 'payroll' => true, 'budgets' => true, 'currencies' => true,
        ],
        'accent' => '',
        'overview' => ['kpis', 'cashflow', 'attention', 'aging', 'profit', 'spend', 'banks', 'vat', 'activity'],
        'overview_off' => [],
        'thresholds' => [
            'due_soon_days' => 7,      // bills / invoices "due soon"
            'forecast_days' => 60,     // how far the cash forecast looks ahead
            'low_cash' => 0,           // warn below this bank balance (rand; 0 = off)
            'stale_bank_days' => 14,   // unmatched bank lines older than this need attention
            'large_journal' => 0,      // flag manual journals above this (rand; 0 = off)
        ],
        'rules' => [
            'approval' => true,        // manual journals: draft → approved → posted
            'memo_required' => false,  // manual journals need a description
            'members_read' => false,   // members (not bookkeepers) may look at Finance, read only
            'attachments_required' => false, // manual journals need a supporting document to post
        ],
    ];

    public const USER_DEFAULTS = [
        'density' => 'comfortable',
        'text' => 'md',
        'accent' => '',
        'landing' => 'overview',
        'money' => 'full',          // full | compact (R 1.2m)
        'period' => 'month',        // default period for lists and reports
        'overview_off' => [],       // panels this person hides on top of the company's
        'views' => [],
        'snoozed' => [],            // attention keys → Y-m-d until
        'recent' => [],             // recently opened accounts (codes) for the ledger
        'recent_reports' => [],     // recently run reports (keys)
    ];

    /** company_settings keys the posting engine and older pages read (finance_ prefix). */
    public const ENGINE_SETTINGS = ['fiscal_year_start', 'vat_basis', 'require_sod'];

    public const PERIODS = ['month', 'last_month', 'quarter', 'ytd', 'fy', 'last_fy', '12m', 'all'];

    // ------------------------------------------------------------------ roles

    /** Who may open Finance at all. Members only when the company allows read-only access. */
    public static function canSee(string $role, ?array $config = null): bool
    {
        $role = strtolower($role);
        if (in_array($role, ['admin', 'bookkeeper', 'viewer'], true)) return true;
        return $role === 'member' && $config !== null && !empty($config['rules']['members_read']);
    }

    /** Capture, approve, post, reconcile, pay and file. */
    public static function canWrite(string $role): bool
    {
        return in_array(strtolower($role), ['admin', 'bookkeeper'], true);
    }

    /** Company settings, account mappings, period unlocks and year-end close. */
    public static function isAdmin(string $role): bool
    {
        return strtolower($role) === 'admin';
    }

    /** What the person may do, for the page (the server checks every call again). */
    public static function caps(string $role): array
    {
        $w = self::canWrite($role);
        $a = self::isAdmin($role);
        return [
            'read' => true, 'write' => $w, 'post' => $w, 'reconcile' => $w, 'pay' => $w, 'vat' => $w,
            'export' => true, 'admin' => $a, 'close_year' => $a, 'unlock' => $a, 'settings' => $a,
        ];
    }

    // ------------------------------------------------------- company & people

    public static function config(PDO $db, int $companyId): array
    {
        if (isset(self::$cache['config:' . $companyId])) return self::$cache['config:' . $companyId];
        $saved = self::readPrefs($db, $companyId, 0);
        $c = self::COMPANY_DEFAULTS;
        foreach (['labels', 'modules', 'rules'] as $k) {
            if (!isset($saved[$k]) || !is_array($saved[$k])) continue;
            foreach ($c[$k] as $key => $def) {
                if (!array_key_exists($key, $saved[$k])) continue;
                $c[$k][$key] = is_bool($def) ? (bool)$saved[$k][$key] : (mb_substr(trim((string)$saved[$k][$key]), 0, 40) ?: $def);
            }
        }
        if (isset($saved['thresholds']) && is_array($saved['thresholds'])) {
            $t = $saved['thresholds'];
            $c['thresholds']['due_soon_days'] = self::clampInt($t['due_soon_days'] ?? 7, 1, 60);
            $c['thresholds']['forecast_days'] = in_array((int)($t['forecast_days'] ?? 60), [30, 60, 90], true) ? (int)$t['forecast_days'] : 60;
            $c['thresholds']['low_cash'] = max(0, round((float)($t['low_cash'] ?? 0), 2));
            $c['thresholds']['stale_bank_days'] = self::clampInt($t['stale_bank_days'] ?? 14, 1, 180);
            $c['thresholds']['large_journal'] = max(0, round((float)($t['large_journal'] ?? 0), 2));
        }
        $c['accent'] = self::color($saved['accent'] ?? '');
        $c['overview'] = self::ordered($saved['overview'] ?? null, array_keys(self::OVERVIEW_PANELS));
        $c['overview_off'] = array_values(array_intersect((array)($saved['overview_off'] ?? []), array_keys(self::OVERVIEW_PANELS)));
        return self::$cache['config:' . $companyId] = $c;
    }

    public static function userPrefs(PDO $db, int $companyId, int $userId): array
    {
        $s = self::readPrefs($db, $companyId, $userId);
        $p = self::USER_DEFAULTS;
        $p['density'] = in_array($s['density'] ?? '', ['comfortable', 'compact'], true) ? $s['density'] : 'comfortable';
        $p['text'] = in_array($s['text'] ?? '', ['sm', 'md', 'lg'], true) ? $s['text'] : 'md';
        $p['accent'] = self::color($s['accent'] ?? '');
        $p['landing'] = isset(self::ROUTES[$s['landing'] ?? '']) ? $s['landing'] : 'overview';
        $p['money'] = ($s['money'] ?? '') === 'compact' ? 'compact' : 'full';
        $p['period'] = in_array($s['period'] ?? '', self::PERIODS, true) ? $s['period'] : 'month';
        $p['overview_off'] = array_values(array_intersect((array)($s['overview_off'] ?? []), array_keys(self::OVERVIEW_PANELS)));
        $views = [];
        foreach ((array)($s['views'] ?? []) as $v) {
            if (!is_array($v)) continue;
            $name = mb_substr(trim((string)($v['name'] ?? '')), 0, 40);
            $route = (string)($v['route'] ?? '');
            if ($name === '' || !isset(self::ROUTES[$route])) continue;
            $views[] = ['id' => preg_replace('/[^a-z0-9]/i', '', (string)($v['id'] ?? '')) ?: bin2hex(random_bytes(4)),
                        'name' => $name, 'route' => $route, 'query' => mb_substr((string)($v['query'] ?? ''), 0, 500)];
        }
        $p['views'] = array_slice($views, 0, 20);
        $today = date('Y-m-d');
        $snoozed = [];
        foreach ((array)($s['snoozed'] ?? []) as $k => $until) {
            if (preg_match('/^[a-z0-9_:.-]{1,80}$/i', (string)$k) && self::ymd($until) && $until >= $today) $snoozed[$k] = $until;
        }
        $p['snoozed'] = $snoozed;
        $p['recent'] = array_slice(array_values(array_filter(array_map('strval', (array)($s['recent'] ?? [])), fn($c) => preg_match('/^[A-Za-z0-9._-]{1,20}$/', $c))), 0, 8);
        $p['recent_reports'] = array_slice(array_values(array_filter(array_map('strval', (array)($s['recent_reports'] ?? [])), fn($c) => preg_match('/^[a-z_]{1,40}$/', $c))), 0, 6);
        return $p;
    }

    public static function readPrefs(PDO $db, int $companyId, int $userId): array
    {
        try {
            $st = $db->prepare("SELECT prefs_json FROM fin_prefs WHERE company_id = ? AND user_id = ?");
            $st->execute([$companyId, $userId]);
            $v = json_decode((string)($st->fetchColumn() ?: ''), true);
            return is_array($v) ? $v : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Merge $changes into the stored prefs (top-level keys replace; null removes). */
    public static function writePrefs(PDO $db, int $companyId, int $userId, array $changes): void
    {
        $cur = self::readPrefs($db, $companyId, $userId);
        foreach ($changes as $k => $v) {
            if ($v === null) unset($cur[$k]);
            else $cur[$k] = $v;
        }
        $json = json_encode($cur, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 100000) throw new FinUserError('These settings are too large to save.');
        $db->prepare("INSERT INTO fin_prefs (company_id, user_id, prefs_json, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json), updated_at = NOW()")
           ->execute([$companyId, $userId, $json]);
        unset(self::$cache['config:' . $companyId]);
    }

    /** finance_* engine settings (fiscal year start month, VAT basis, segregation of duties). */
    public static function engineSettings(PDO $db, int $companyId): array
    {
        $out = ['fiscal_year_start' => 'March', 'vat_basis' => 'invoice', 'require_sod' => '0'];
        $st = $db->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ?
                             AND setting_key IN ('finance_fiscal_year_start', 'finance_vat_basis', 'finance_require_sod')");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $k = substr($k, 8);
            if ($v !== null && $v !== '') $out[$k] = (string)$v;
        }
        if ($out['vat_basis'] !== 'payments') $out['vat_basis'] = 'invoice';
        $out['require_sod'] = $out['require_sod'] === '1' ? '1' : '0';
        return $out;
    }

    /** First day of the financial year that contains $date (fiscal_year_start month, SA default March). */
    public static function fyStart(PDO $db, int $companyId, ?string $date = null): string
    {
        $date = self::ymd($date) ?: date('Y-m-d');
        $month = self::monthNumber(self::engineSettings($db, $companyId)['fiscal_year_start']);
        $y = (int)substr($date, 0, 4);
        if ((int)substr($date, 5, 2) < $month) $y--;
        return sprintf('%04d-%02d-01', $y, $month);
    }

    public static function monthNumber($v): int
    {
        if (is_numeric($v)) return self::clampInt($v, 1, 12);
        $t = strtotime('1 ' . trim((string)$v) . ' 2000');
        return $t ? (int)date('n', $t) : 3;
    }

    /** [from, to] for a named period around today. */
    public static function periodRange(PDO $db, int $companyId, string $period, ?string $today = null): array
    {
        $today = self::ymd($today) ?: date('Y-m-d');
        $t = new DateTimeImmutable($today);
        switch ($period) {
            case 'last_month':
                $f = $t->modify('first day of last month');
                return [$f->format('Y-m-d'), $f->modify('last day of this month')->format('Y-m-d')];
            case 'quarter':
                $q = intdiv((int)$t->format('n') - 1, 3) * 3 + 1;
                $f = new DateTimeImmutable($t->format('Y') . '-' . sprintf('%02d', $q) . '-01');
                return [$f->format('Y-m-d'), $f->modify('+2 months')->modify('last day of this month')->format('Y-m-d')];
            case 'ytd':
                return [self::fyStart($db, $companyId, $today), $today];
            case 'fy':
                $f = new DateTimeImmutable(self::fyStart($db, $companyId, $today));
                return [$f->format('Y-m-d'), $f->modify('+1 year -1 day')->format('Y-m-d')];
            case 'last_fy':
                $f = (new DateTimeImmutable(self::fyStart($db, $companyId, $today)))->modify('-1 year');
                return [$f->format('Y-m-d'), $f->modify('+1 year -1 day')->format('Y-m-d')];
            case '12m':
                return [$t->modify('first day of this month')->modify('-11 months')->format('Y-m-d'), $today];
            case 'all':
                return ['2000-01-01', '2099-12-31'];
            case 'month':
            default:
                return [$t->format('Y-m-01'), $t->format('Y-m-t')];
        }
    }

    /** Active people in the company: [id => ['id', 'name', 'email', 'role']]. */
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
            $out[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => $name !== '' ? $name : (string)$u['email']];
        }
        return self::$cache['people:' . $companyId] = $out;
    }

    public static function personName(PDO $db, int $companyId, $id): string
    {
        $id = (int)$id;
        if (!$id) return '';
        $p = self::people($db, $companyId);
        if (isset($p[$id])) return $p[$id]['name'];
        $st = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE id = ?");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: 'Former member');
    }

    public static function companyName(PDO $db, int $companyId): string
    {
        if (isset(self::$cache['company:' . $companyId])) return self::$cache['company:' . $companyId];
        $st = $db->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        return self::$cache['company:' . $companyId] = (string)($st->fetchColumn() ?: 'Company');
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static array $cache = [];

    // ---------------------------------------------------------------- helpers

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

    /** Money from a form ("1 234,50", "R1,234.50", "-12") to integer cents; null when not a number. */
    public static function cents($v): ?int
    {
        if (is_int($v)) return $v * 100;
        if (is_float($v)) return (int)round($v * 100);
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = str_replace(["\u{2212}", 'R', 'r', ' ', "\u{00A0}"], ['-', '', '', '', ''], $s);
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) $s = str_replace(',', '', $s);
        elseif (preg_match('/^-?\d+,\d{1,2}$/', $s)) $s = str_replace(',', '.', $s);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
        return (int)round((float)$s * 100);
    }

    public static function rand(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function money(float $v): string
    {
        return ($v < 0 ? '-' : '') . 'R ' . number_format(abs($v), 2, '.', ',');
    }

    /** '#rrggbb' or '' */
    public static function color($v): string
    {
        $v = trim((string)$v);
        return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '';
    }

    public static function ymd($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    }

    public static function clampInt($v, int $min, int $max): int
    {
        return max($min, min($max, (int)$v));
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private static function ordered($saved, array $all): array
    {
        $out = [];
        foreach ((array)$saved as $k) if (in_array($k, $all, true) && !in_array($k, $out, true)) $out[] = $k;
        foreach ($all as $k) if (!in_array($k, $out, true)) $out[] = $k;
        return $out;
    }

    /** Finance audit trail (audit_log through Audit::log, which never throws). */
    public static function audit(PDO $db, int $companyId, int $userId, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        Audit::setPdo($db);
        Audit::log($action, $details, $entityType, $entityId, $companyId, $userId);
    }
}
