<?php
// calendar/lib/CalendarSettings.php
//
// Per-user calendar preferences with company defaults behind them. Admins set
// the defaults (and which Flowwork sources the calendar may show) on
// /admin/calendar.php — company_settings keys calendar_*; a user's own choices
// live in calendar_settings (+ prefs_json for the newer ones) and win.

final class CalendarSettings
{
    public const VIEWS = ['day', 'week', 'month', 'year', 'agenda'];

    /** Live sources: company toggle key, default, who may see them. */
    public const SOURCES = [
        'projects' => ['key' => 'calendar_sync_project_dates', 'default' => '1'],
        'tasks'    => ['key' => 'calendar_sync_tasks',         'default' => '1'],
        'invoices' => ['key' => 'calendar_sync_invoice_due',   'default' => '1'],
        'quotes'   => ['key' => 'calendar_sync_quotes',        'default' => '1'],
        'deals'    => ['key' => 'calendar_sync_deals',         'default' => '1'],
        'followups' => ['key' => 'calendar_sync_crm_tasks',    'default' => '1'],
        'payroll'  => ['key' => 'calendar_sync_payroll_dates', 'default' => '0', 'roles' => ['admin', 'bookkeeper']],
    ];

    private static array $cache = [];

    public static function companyDefaults(PDO $db, int $companyId): array
    {
        $g = fn($k, $d) => CalendarRepo::companySetting($db, $companyId, $k, $d);
        $view = $g('calendar_default_view', 'week');
        return [
            'week_start'       => (int)$g('calendar_week_start', '1') === 0 ? 0 : 1,
            'work_start'       => self::hm($g('calendar_work_hours_start', '08:00'), '08:00'),
            'work_end'         => self::hm($g('calendar_work_hours_end', '17:00'), '17:00'),
            'default_view'     => in_array($view, self::VIEWS, true) ? $view : 'week',
            'default_reminder' => max(0, (int)$g('calendar_default_reminder', '15')),
            'show_weekends'    => $g('calendar_show_weekends', '1') === '1',
            'timezone'         => CalendarRepo::companyTz($db, $companyId)->getName(),
        ];
    }

    /** Sources the company allows this role to see. @return array<string,bool> */
    public static function availableSources(PDO $db, int $companyId, string $role): array
    {
        $out = [];
        foreach (self::SOURCES as $name => $s) {
            $on = CalendarRepo::companySetting($db, $companyId, $s['key'], $s['default']) === '1';
            if (isset($s['roles']) && !in_array($role, $s['roles'], true)) $on = false;
            $out[$name] = $on;
        }
        return $out;
    }

    public static function load(PDO $db, int $companyId, int $userId): array
    {
        $ck = $companyId . ':' . $userId;
        if (isset(self::$cache[$ck])) return self::$cache[$ck];
        $co = self::companyDefaults($db, $companyId);
        $row = null;
        try {
            $st = $db->prepare("SELECT * FROM calendar_settings WHERE company_id = ? AND user_id = ?");
            $st->execute([$companyId, $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('[calendar] settings: ' . $e->getMessage());
        }
        $p = $row ? (array)json_decode((string)($row['prefs_json'] ?? ''), true) : [];
        $view = $row['default_view'] ?? $co['default_view'];
        $sources = [];
        foreach (array_keys(self::SOURCES) as $name) {
            $legacy = ['projects' => 'enable_project_dates', 'invoices' => 'enable_invoice_due'][$name] ?? null;
            $sources[$name] = isset($p['sources'][$name]) ? (bool)$p['sources'][$name]
                : ($legacy && $row ? (int)$row[$legacy] === 1 : true);
        }
        $out = [
            'week_start'          => $row ? ((int)$row['week_start'] === 0 ? 0 : 1) : $co['week_start'],
            'work_start'          => $row ? self::hm((string)$row['work_hours_start'], $co['work_start']) : $co['work_start'],
            'work_end'            => $row ? self::hm((string)$row['work_hours_end'], $co['work_end']) : $co['work_end'],
            'default_view'        => in_array($view, self::VIEWS, true) ? $view : 'week',
            'default_reminder'    => $row ? max(0, (int)$row['default_reminder_minutes']) : $co['default_reminder'],
            'show_weekends'       => array_key_exists('show_weekends', $p) ? (bool)$p['show_weekends'] : $co['show_weekends'],
            'time_24h'            => array_key_exists('time_24h', $p) ? (bool)$p['time_24h'] : true,
            'default_duration'    => in_array((int)($p['default_duration'] ?? 60), [15, 30, 45, 60, 90, 120], true) ? (int)($p['default_duration'] ?? 60) : 60,
            'default_calendar_id' => isset($p['default_calendar_id']) ? (int)$p['default_calendar_id'] : null,
            'hidden_calendars'    => array_values(array_map('strval', (array)($p['hidden_calendars'] ?? []))),
            'sources'             => $sources,
            'timezone'            => $co['timezone'],
        ];
        return self::$cache[$ck] = $out;
    }

    /** Save the keys present in $in; returns the full settings. */
    public static function save(PDO $db, int $companyId, int $userId, array $in): array
    {
        $cur = self::load($db, $companyId, $userId);
        $n = $cur;
        if (isset($in['week_start'])) $n['week_start'] = (int)$in['week_start'] === 0 ? 0 : 1;
        if (isset($in['work_start'])) $n['work_start'] = self::hm((string)$in['work_start'], $cur['work_start']);
        if (isset($in['work_end'])) $n['work_end'] = self::hm((string)$in['work_end'], $cur['work_end']);
        if ($n['work_end'] <= $n['work_start']) throw new CalendarUserError('Working hours must end after they start.');
        if (isset($in['default_view'])) {
            if (!in_array($in['default_view'], self::VIEWS, true)) throw new CalendarUserError('Unknown view.');
            $n['default_view'] = $in['default_view'];
        }
        if (isset($in['default_reminder'])) $n['default_reminder'] = max(0, min(40320, (int)$in['default_reminder']));
        foreach (['show_weekends', 'time_24h'] as $k) if (array_key_exists($k, $in)) $n[$k] = (bool)$in[$k];
        if (isset($in['default_duration'])) {
            $d = (int)$in['default_duration'];
            if (!in_array($d, [15, 30, 45, 60, 90, 120], true)) throw new CalendarUserError('Choose a duration from the list.');
            $n['default_duration'] = $d;
        }
        if (array_key_exists('default_calendar_id', $in)) $n['default_calendar_id'] = (int)$in['default_calendar_id'] ?: null;
        if (array_key_exists('hidden_calendars', $in)) {
            $n['hidden_calendars'] = array_values(array_unique(array_filter(array_map(
                fn($v) => preg_match('/^(\d{1,19}|src:[a-z]+|inv)$/', (string)$v) ? (string)$v : null, (array)$in['hidden_calendars']))));
            $n['hidden_calendars'] = array_slice($n['hidden_calendars'], 0, 200);
        }
        if (isset($in['sources']) && is_array($in['sources'])) {
            foreach (array_keys(self::SOURCES) as $name) {
                if (array_key_exists($name, $in['sources'])) $n['sources'][$name] = (bool)$in['sources'][$name];
            }
        }
        $prefs = ['show_weekends' => $n['show_weekends'], 'time_24h' => $n['time_24h'], 'default_duration' => $n['default_duration'],
                  'default_calendar_id' => $n['default_calendar_id'], 'hidden_calendars' => $n['hidden_calendars'], 'sources' => $n['sources']];
        $db->prepare("INSERT INTO calendar_settings
                (company_id, user_id, timezone, week_start, work_hours_start, work_hours_end, default_reminder_minutes, default_view,
                 enable_invoice_due, enable_project_dates, prefs_json, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE timezone = VALUES(timezone), week_start = VALUES(week_start),
                    work_hours_start = VALUES(work_hours_start), work_hours_end = VALUES(work_hours_end),
                    default_reminder_minutes = VALUES(default_reminder_minutes), default_view = VALUES(default_view),
                    enable_invoice_due = VALUES(enable_invoice_due), enable_project_dates = VALUES(enable_project_dates),
                    prefs_json = VALUES(prefs_json), updated_at = NOW()")
           ->execute([$companyId, $userId, $n['timezone'], $n['week_start'], $n['work_start'] . ':00', $n['work_end'] . ':00',
                      $n['default_reminder'], $n['default_view'], $n['sources']['invoices'] ? 1 : 0, $n['sources']['projects'] ? 1 : 0,
                      json_encode($prefs)]);
        unset(self::$cache[$companyId . ':' . $userId]);
        return self::load($db, $companyId, $userId);
    }

    /** 'HH:MM' from 'HH:MM[:SS]', or $default. */
    private static function hm(string $v, string $default): string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', trim($v), $m) ? $m[1] . ':' . $m[2] : $default;
    }
}
