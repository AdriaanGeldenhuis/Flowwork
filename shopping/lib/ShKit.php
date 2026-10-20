<?php
// /shopping/lib/ShKit.php — Shopping's shared vocabulary:
//   - who may do what (write lists, make purchase orders, company settings),
//   - purposes, sharing modes and the per-person preferences,
//   - money in cents, quantities, dates, a file-based rate limiter,
//   - the list's activity (shopping_actions), the audit trail and the bell.

final class ShProblem extends RuntimeException {}   // a message for the person; the code is the HTTP status
final class ShForbidden extends RuntimeException {}

final class ShKit
{
    public const PURPOSES = [
        'procurement' => 'Supplies & materials',
        'groceries'   => 'Groceries & kitchen',
        'general'     => 'General',
    ];
    public const PURPOSE_ICON = ['procurement' => 'is-crate', 'groceries' => 'is-basket', 'general' => 'is-list'];
    public const PURPOSE_COLOR = ['procurement' => '#9a6512', 'groceries' => '#2f7457', 'general' => '#7a1f30'];

    public const SHARING = ['private' => 'Only me', 'team' => 'Everyone in the company'];
    public const ROUTE_MODES = ['drive' => 'Driving', 'walk' => 'Walking', 'bike' => 'Cycling'];
    public const GROUP_BY = ['aisle' => 'By aisle', 'order' => 'In the order added'];

    public const PREF_DEFAULTS = [
        'default_radius_km' => 25.0,
        'route_mode' => 'drive',
        'price_prompt' => 1,
        'keep_awake' => 1,
        'group_by' => 'aisle',
        'default_purpose' => 'general',
        'home_store_id' => 0,
    ];

    /** Company setting: may Shopping ask OpenAI where to buy something (item name + the company's town only). */
    public const AI_KEY = 'shopping_ai_stores';

    public const MAX_ITEMS_PER_LIST = 500;
    public const MAX_LINES_PER_ADD = 200;

    // ------------------------------------------------------------------ roles

    public static function role(string $role): string
    {
        $r = strtolower(trim($role));
        return in_array($r, ['admin', 'bookkeeper', 'member', 'pos', 'viewer'], true) ? $r : 'viewer';
    }

    /** Make and change lists. Viewers only look (at team lists). */
    public static function canWrite(string $role): bool
    {
        return self::role($role) !== 'viewer';
    }

    /** Turn a list into a purchase order — Procurement's writers. */
    public static function canOrder(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper'], true);
    }

    /** Company-wide settings (the AI store ideas). */
    public static function canConfigure(string $role): bool
    {
        return self::role($role) === 'admin';
    }

    public static function caps(string $role): array
    {
        return ['write' => self::canWrite($role), 'order' => self::canOrder($role), 'admin' => self::canConfigure($role)];
    }

    // ------------------------------------------------------------ preferences

    /** One person's preferences in one company (defaults when never saved). */
    public static function prefs(PDO $db, int $companyId, int $userId): array
    {
        $p = self::PREF_DEFAULTS;
        try {
            $st = $db->prepare("SELECT * FROM shopping_preferences WHERE company_id = ? AND user_id = ? LIMIT 1");
            $st->execute([$companyId, $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        foreach ($p as $k => $def) {
            if (!array_key_exists($k, $row) || $row[$k] === null) continue;
            $p[$k] = is_float($def) ? (float)$row[$k] : (is_int($def) ? (int)$row[$k] : (string)$row[$k]);
        }
        if (!isset(self::ROUTE_MODES[$p['route_mode']])) $p['route_mode'] = 'drive';
        if (!isset(self::GROUP_BY[$p['group_by']])) $p['group_by'] = 'aisle';
        if (!isset(self::PURPOSES[$p['default_purpose']])) $p['default_purpose'] = 'general';
        $p['default_radius_km'] = max(1.0, min(100.0, $p['default_radius_km']));
        return $p;
    }

    /** Save preferences (already checked by the caller). */
    public static function savePrefs(PDO $db, int $companyId, int $userId, array $values): void
    {
        $values = array_intersect_key($values, self::PREF_DEFAULTS);
        if (!$values) return;
        $row = $values + self::prefs($db, $companyId, $userId);
        $cols = array_keys($row);
        $db->prepare("INSERT INTO shopping_preferences (company_id, user_id, `" . implode('`, `', $cols) . "`) VALUES (?, ?" . str_repeat(', ?', count($cols)) . ")
                      ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols)) . ", updated_at = NOW()")
           ->execute(array_merge([$companyId, $userId], array_values($row)));
    }

    public static function companySetting(PDO $db, int $companyId, string $key, string $default = ''): string
    {
        try {
            $st = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1");
            $st->execute([$companyId, $key]);
            $v = $st->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function saveCompanySetting(PDO $db, int $companyId, string $key, string $value): void
    {
        $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
           ->execute([$companyId, $key, $value]);
    }

    /** The company's OpenAI key (from Suppliers AI), or null. */
    public static function aiKey(PDO $db, int $companyId): ?string
    {
        try {
            $st = $db->prepare("SELECT api_keys_json FROM ai_settings WHERE company_id = ? LIMIT 1");
            $st->execute([$companyId]);
            $keys = json_decode((string)$st->fetchColumn(), true);
        } catch (Throwable $e) {
            return null;
        }
        $key = trim((string)($keys['openai_api_key'] ?? ''));
        return preg_match('/^sk-[A-Za-z0-9_\-]{20,}$/', $key) ? $key : null;
    }

    /** AI store ideas: switched on by an admin and a key in place. */
    public static function aiReady(PDO $db, int $companyId): bool
    {
        return self::companySetting($db, $companyId, self::AI_KEY, '0') === '1' && self::aiKey($db, $companyId) !== null;
    }

    /** The company's town, for directions and store ideas ('' when unknown). */
    public static function companyTown(PDO $db, int $companyId): string
    {
        try {
            $st = $db->prepare("SELECT city, region, country FROM companies WHERE id = ?");
            $st->execute([$companyId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return '';
        }
        return implode(', ', array_filter([trim((string)($c['city'] ?? '')), trim((string)($c['region'] ?? ''))]));
    }

    // ----------------------------------------------------------------- helpers

    /** Money as typed ("1 234,50", "R1,234.50", 45) to cents; null when not a number. */
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

    /** A price for the books: 0 … R 10 000 000, or null when empty. Throws when it is not a price. */
    public static function price($v, string $what = 'The price'): ?int
    {
        if ($v === null || $v === '') return null;
        $c = self::cents($v);
        if ($c === null || $c < 0 || $c > 1000000000) throw new ShProblem($what . ' is not an amount in rand.', 422);
        return $c;
    }

    /** A quantity as typed ("2,5", "1 000", "½") to a number; null when not one. */
    public static function number($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = str_replace(["\u{2212}", ' ', "\u{00A0}"], ['-', '', ''], trim((string)$v));
        if ($s === '') return null;
        if (preg_match('/^-?[1-9]\d{0,2}(,\d{3})+(\.\d+)?$/', $s)) $s = str_replace(',', '', $s);
        elseif (preg_match('/^-?\d+,\d+$/', $s)) $s = str_replace(',', '.', $s);
        return preg_match('/^-?\d+(\.\d+)?$/', $s) ? (float)$s : null;
    }

    public static function qty($v): float
    {
        $n = self::number($v);
        if ($n === null || $n <= 0 || $n > 1000000) throw new ShProblem('The quantity must be a number above nought.', 422);
        return round($n, 3);
    }

    /** 2.000 → "2", 2.500 → "2.5" */
    public static function qtyText($q): string
    {
        $s = rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '−' : '') . 'R ' . number_format(abs($cents) / 100, 2, '.', "\u{00A0}");
    }

    public static function ymd($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }

    public static function date(?string $ymd, bool $year = true): string
    {
        if (!$ymd || !($t = strtotime(substr($ymd, 0, 10)))) return '';
        return date($year ? 'j M Y' : 'j M', $t);
    }

    /** "today", "tomorrow", "in 4 days", "2 days late", "12 Nov" */
    public static function due(?string $ymd): string
    {
        if (!$ymd || !($t = strtotime(substr($ymd, 0, 10)))) return '';
        $d = (int)round(($t - strtotime(date('Y-m-d'))) / 86400);
        if ($d === 0) return 'today';
        if ($d === 1) return 'tomorrow';
        if ($d === -1) return 'yesterday';
        if ($d < 0) return abs($d) . ' days late';
        if ($d < 7) return 'in ' . $d . ' days';
        return self::date($ymd, date('Y', $t) !== date('Y'));
    }

    public static function text($v, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string)$v) ?? '');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_substr($s, 0, $max);
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** A person's name ('' when unknown). */
    public static function personName(PDO $db, int $userId): string
    {
        static $cache = [];
        if ($userId <= 0) return '';
        if (isset($cache[$userId])) return $cache[$userId];
        $st = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) n, email FROM users WHERE id = ?");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return $cache[$userId] = (string)(($r['n'] ?? '') !== '' ? $r['n'] : ($r['email'] ?? ''));
    }

    /** storage/shopping (closed to the web by storage/.htaccess; created on demand). */
    public static function storageDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/shopping';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
        return $dir;
    }

    /** Sliding-window limiter. @return int seconds to wait (0 = go ahead) */
    public static function rateLimit(string $key, int $max, int $windowSeconds): int
    {
        $dir = self::storageDir();
        if ($dir === null || !is_writable($dir)) $dir = sys_get_temp_dir();
        $fh = @fopen($dir . '/rl-' . sha1($key) . '.json', 'c+');
        if (!$fh) return 0;
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
            if (count($hits) >= $max) return max(1, $hits[0] + $windowSeconds - $now);
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            return 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** What happened on a list, for its activity feed (never throws). */
    public static function act(PDO $db, int $companyId, int $listId, ?int $itemId, int $userId, string $action, array $payload = []): void
    {
        try {
            $db->prepare("INSERT INTO shopping_actions (company_id, list_id, item_id, user_id, action, payload_json, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $listId, $itemId, $userId, mb_substr($action, 0, 100), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {
            error_log('[shopping] action ' . $action . ': ' . $e->getMessage());
        }
    }

    /** Audit trail (never throws). */
    public static function audit(PDO $db, int $companyId, int $userId, string $action, string $entity, ?int $entityId, array $details = []): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $userId, mb_substr($action, 0, 100), $entity, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {
            error_log('[shopping] audit ' . $action . ': ' . $e->getMessage());
        }
    }

    /** The bell (and the phone when asked) for someone else. Never throws. */
    public static function notify(PDO $db, int $companyId, int $toUser, int $byUser, string $title, string $message, string $url, bool $push = false): void
    {
        if ($toUser <= 0 || $toUser === $byUser) return;
        try {
            require_once dirname(__DIR__, 2) . '/includes/notify.php';
            fw_notify($db, $companyId, $toUser, 'shopping', $title, $message, $url, $push ? ['ch' => 'general'] : false);
        } catch (Throwable $e) {
            error_log('[shopping] notify: ' . $e->getMessage());
        }
    }
}
