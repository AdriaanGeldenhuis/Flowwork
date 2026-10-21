<?php
// /suppliers_ai/lib/SaKit.php — Suppliers AI's shared vocabulary:
//   - who may do what (search, ask for quotes, make purchase orders, settings, keys),
//   - the company's settings (ai_settings: search defaults, the OpenAI and
//     Google keys — never sent to the browser — and the search words),
//   - money in cents, dates, text, a file-based rate limiter and the
//     company-wide hourly and daily ceilings on paid look-ups,
//   - the audit trail and the bell.

final class SaProblem extends RuntimeException {}   // a message for the person; the code is the HTTP status
final class SaForbidden extends RuntimeException {}

final class SaKit
{
    public const AI_MODELS = ['gpt-4o-mini' => 'GPT-4o mini (fast, low cost)', 'gpt-4o' => 'GPT-4o (most careful)'];

    public const DEFAULTS = [
        'default_radius_km' => 25.0,
        'min_score_threshold' => 0.55,
        'rfq_max_recipients' => 8,
        'per_hour_cap' => 20,
        'daily_search_cap' => 100,
    ];

    /** Company settings: the request-for-quote email (JSON subject/body); only suppliers with documents in order by default. */
    public const TEMPLATE_KEY = 'sai_rfq_template';
    public const COMPLIANT_KEY = 'sai_compliant_only';

    public const RFQ_TEMPLATE = [
        'subject' => 'Request for quotation: {what} — {company}',
        'body' => "Hi {first_name},\n\n{company} would like a quotation for the following:\n\n{details}\n\nWhere: {where}\nNeeded by: {needed_by}\n\nPlease send your quotation by {quote_by}, with your delivery or lead time and payment terms. If you cannot help with this one, a short reply to say so is appreciated.\n\nKind regards\n{sender}\n{company}",
    ];
    public const PLACEHOLDERS = ['first_name', 'supplier', 'what', 'details', 'quantity', 'where', 'needed_by', 'quote_by', 'company', 'sender'];

    // ------------------------------------------------------------------ roles

    public static function role(string $role): string
    {
        $r = strtolower(trim($role));
        return in_array($r, ['admin', 'bookkeeper', 'member', 'pos', 'viewer'], true) ? $r : 'viewer';
    }

    /** Search for suppliers (it can cost money: Google and OpenAI look-ups). Viewers only look. */
    public static function canSearch(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper', 'member'], true);
    }

    /** Ask suppliers for quotes, record quotes, add suppliers to the CRM. */
    public static function canWrite(string $role): bool
    {
        return self::canSearch($role);
    }

    /** Purchase orders, and what was billed (the books): Procurement's and Finance's writers. */
    public static function canOrder(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper'], true);
    }

    /** Search defaults, the words and the request email. */
    public static function canConfigure(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper'], true);
    }

    /** The OpenAI and Google keys (shared with Receipts and Shopping). */
    public static function canKeys(string $role): bool
    {
        return self::role($role) === 'admin';
    }

    public static function caps(string $role): array
    {
        return ['search' => self::canSearch($role), 'write' => self::canWrite($role), 'order' => self::canOrder($role),
                'books' => self::canOrder($role), 'configure' => self::canConfigure($role), 'keys' => self::canKeys($role)];
    }

    // --------------------------------------------------------------- settings

    /** The ai_settings row, made when missing. */
    public static function row(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT * FROM ai_settings WHERE company_id = ? LIMIT 1");
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        try {
            $db->prepare("INSERT IGNORE INTO ai_settings (company_id, default_radius_km, min_score_threshold, require_compliance, block_expired_compliance,
                                                          rfq_max_recipients, daily_search_cap, per_hour_cap, created_at)
                          VALUES (?, 25.00, 0.55, 0, 1, 8, 100, 20, NOW())")->execute([$companyId]);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] settings row: ' . $e->getMessage());
        }
        $st->execute([$companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['company_id' => $companyId];
    }

    /** Settings, checked and with their defaults. The keys are included: never send this array to the browser. */
    public static function settings(PDO $db, int $companyId): array
    {
        $r = self::row($db, $companyId);
        $keys = json_decode((string)($r['api_keys_json'] ?? ''), true);
        $keys = is_array($keys) ? $keys : [];
        $num = fn($k, $min, $max) => max($min, min($max, isset($r[$k]) && $r[$k] !== null && $r[$k] !== '' ? (float)$r[$k] : (float)self::DEFAULTS[$k]));
        $openai = trim((string)($keys['openai_api_key'] ?? ''));
        $google = trim((string)($keys['google_places_key'] ?? ''));
        $model = (string)($keys['openai_model'] ?? 'gpt-4o-mini');
        return [
            'radius_km' => round($num('default_radius_km', 1, 500), 1),
            'min_score' => round($num('min_score_threshold', 0, 1), 2),
            'max_recipients' => (int)$num('rfq_max_recipients', 1, 50),
            'per_hour' => (int)$num('per_hour_cap', 1, 500),
            'per_day' => (int)$num('daily_search_cap', 1, 5000),
            // Not ai_settings.require_compliance: the old module stored 1 there for everyone and never read it.
            'compliant_only' => self::companySetting($db, $companyId, self::COMPLIANT_KEY, '0') === '1',
            'openai_key' => self::validOpenAiKey($openai) ? $openai : '',
            'openai_on' => !empty($keys['openai_enabled']),
            'openai_model' => isset(self::AI_MODELS[$model]) ? $model : 'gpt-4o-mini',
            'google_key' => self::validGoogleKey($google) ? $google : '',
            'rules' => self::rules($r['rules_json'] ?? null),
        ];
    }

    /** What the browser may see of the settings (keys masked). */
    public static function publicSettings(array $s): array
    {
        $out = $s;
        unset($out['openai_key'], $out['google_key']);
        $out['openai_key_hint'] = self::mask($s['openai_key']);
        $out['google_key_hint'] = self::mask($s['google_key']);
        $out['ai_ready'] = self::aiReady($s);
        $out['google_ready'] = $s['google_key'] !== '';
        return $out;
    }

    public static function aiReady(array $s): bool
    {
        return $s['openai_on'] && $s['openai_key'] !== '';
    }

    public static function validOpenAiKey(string $k): bool
    {
        return (bool)preg_match('/^sk-[A-Za-z0-9_\-]{20,}$/', $k);
    }

    public static function validGoogleKey(string $k): bool
    {
        return (bool)preg_match('/^AIza[0-9A-Za-z_\-]{30,}$/', $k);
    }

    /** "sk-…AbCd" — enough to recognise a key, never enough to use it. */
    public static function mask(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 4) . '…' . substr($key, -4);
    }

    /** The search words (rules_json), cleaned: synonyms, categories, allow and deny lists. */
    public static function rules($json): array
    {
        $r = is_array($json) ? $json : json_decode((string)$json, true);
        $r = is_array($r) ? $r : [];
        $words = function ($list): array {
            $out = [];
            foreach ((array)$list as $w) {
                if (!is_scalar($w)) continue;
                $w = mb_strtolower(self::text($w, 60));
                if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
            }
            return array_slice($out, 0, 60);
        };
        $map = function ($m) use ($words): array {
            $out = [];
            foreach ((array)$m as $k => $v) {
                $k = mb_strtolower(self::text((string)$k, 60));
                if ($k === '' || is_int($k)) continue;
                $w = $words($v);
                if ($w) $out[$k] = $w;
                if (count($out) >= 200) break;
            }
            return $out;
        };
        $list = function ($l): array {
            $l = is_array($l) ? $l : [];
            $phones = [];
            foreach ((array)($l['phones'] ?? []) as $p) {
                $d = self::phoneKey((string)$p);
                if ($d !== '' && !in_array($d, $phones, true)) $phones[] = $d;
            }
            $domains = [];
            foreach ((array)($l['domains'] ?? []) as $d) {
                $d = self::domain((string)$d);
                if ($d !== '' && !in_array($d, $domains, true)) $domains[] = $d;
            }
            $names = [];
            foreach ((array)($l['names'] ?? []) as $n) {
                $n = mb_strtolower(self::text($n, 120));
                if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($l['ids'] ?? [])), fn($i) => $i > 0)));
            return ['phones' => array_slice($phones, 0, 500), 'domains' => array_slice($domains, 0, 500), 'names' => array_slice($names, 0, 500), 'ids' => array_slice($ids, 0, 500)];
        };
        return ['synonyms' => $map($r['synonyms'] ?? []), 'categories' => $map($r['categories'] ?? []),
                'allow_list' => $list($r['allow_list'] ?? []), 'deny_list' => $list($r['deny_list'] ?? [])];
    }

    /** Write some ai_settings columns (already checked by the caller). */
    public static function saveRow(PDO $db, int $companyId, array $cols): void
    {
        if (!$cols) return;
        self::row($db, $companyId);
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
        $db->prepare("UPDATE ai_settings SET $sets, updated_at = NOW() WHERE company_id = ?")->execute(array_merge(array_values($cols), [$companyId]));
    }

    /** Change the stored keys: a value replaces, '' leaves as is, null removes. */
    public static function saveKeys(PDO $db, int $companyId, array $changes): void
    {
        $r = self::row($db, $companyId);
        $keys = json_decode((string)($r['api_keys_json'] ?? ''), true);
        $keys = is_array($keys) ? $keys : [];
        foreach ($changes as $k => $v) {
            if ($v === '') continue;
            if ($v === null) unset($keys[$k]); else $keys[$k] = $v;
        }
        unset($keys['bing_local_key']); // Bing's local search API was retired by Microsoft in August 2025
        self::saveRow($db, $companyId, ['api_keys_json' => json_encode($keys, JSON_UNESCAPED_SLASHES)]);
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

    /** The request-for-quote email the company uses (its own, or the built-in one). */
    public static function template(PDO $db, int $companyId): array
    {
        $j = json_decode(self::companySetting($db, $companyId, self::TEMPLATE_KEY, ''), true);
        $t = self::RFQ_TEMPLATE;
        if (is_array($j)) {
            if (trim((string)($j['subject'] ?? '')) !== '') $t['subject'] = (string)$j['subject'];
            if (trim((string)($j['body'] ?? '')) !== '') $t['body'] = (string)$j['body'];
        }
        $t['custom'] = is_array($j);
        return $t;
    }

    /** The company's name, town and contact details. */
    public static function company(PDO $db, int $companyId): array
    {
        static $cache = [];
        if (isset($cache[$companyId])) return $cache[$companyId];
        $st = $db->prepare("SELECT id, name, city, region, country, phone, email FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $companyId, 'name' => 'Your company'];
        $c['town'] = implode(', ', array_filter([trim((string)($c['city'] ?? '')), trim((string)($c['region'] ?? ''))]));
        return $cache[$companyId] = $c;
    }

    // ------------------------------------------------------------------ quota

    /**
     * The company-wide ceilings on searches that reach Google or OpenAI (they cost money).
     * @return array{hour:int, hour_cap:int, day:int, day_cap:int, left:int}
     */
    public static function quota(PDO $db, int $companyId, array $settings): array
    {
        $hour = $day = 0;
        try {
            // Searches that reached Google or OpenAI, and emails written by AI; the hour can reach back into yesterday
            $st = $db->prepare("SELECT COALESCE(SUM(t >= NOW() - INTERVAL 1 HOUR), 0), COALESCE(SUM(t >= CURDATE()), 0) FROM (
                                    SELECT created_at AS t FROM ai_queries WHERE company_id = ? AND external = 1 AND created_at >= LEAST(CURDATE(), NOW() - INTERVAL 1 HOUR)
                                    UNION ALL
                                    SELECT created_at FROM ai_actions WHERE company_id = ? AND action = 'ai_draft' AND created_at >= LEAST(CURDATE(), NOW() - INTERVAL 1 HOUR)) x");
            $st->execute([$companyId, $companyId]);
            [$hour, $day] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] quota: ' . $e->getMessage());
        }
        $left = max(0, min($settings['per_hour'] - $hour, $settings['per_day'] - $day));
        return ['hour' => $hour, 'hour_cap' => $settings['per_hour'], 'day' => $day, 'day_cap' => $settings['per_day'], 'left' => $left];
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

    /** An amount for a quote or a budget: 0 … R 100 000 000, or null when empty. Throws when it is not an amount. */
    public static function amount($v, string $what = 'The amount'): ?int
    {
        if ($v === null || $v === '') return null;
        $c = self::cents($v);
        if ($c === null || $c < 0 || $c > 10000000000) throw new SaProblem($what . ' is not an amount in rand.', 422);
        return $c;
    }

    public static function money(?int $cents): string
    {
        if ($cents === null) return '';
        return ($cents < 0 ? '−' : '') . 'R ' . number_format(abs($cents) / 100, 2, '.', "\u{00A0}");
    }

    public static function ymd($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }

    public static function date(?string $ymd, bool $year = false): string
    {
        if (!$ymd || !($t = strtotime(substr($ymd, 0, 10)))) return '';
        return date($year || date('Y', $t) !== date('Y') ? 'j M Y' : 'j M', $t);
    }

    /** "today", "tomorrow", "in 4 days", "2 days ago", "12 Nov" */
    public static function due(?string $ymd): string
    {
        if (!$ymd || !($t = strtotime(substr($ymd, 0, 10)))) return '';
        $d = (int)round(($t - strtotime(date('Y-m-d'))) / 86400);
        if ($d === 0) return 'today';
        if ($d === 1) return 'tomorrow';
        if ($d === -1) return 'yesterday';
        if ($d < 0) return abs($d) . ' days ago';
        if ($d < 7) return 'in ' . $d . ' days';
        return self::date($ymd);
    }

    /** One line of plain text, at most $max characters. */
    public static function text($v, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string)$v) ?? '');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_substr($s, 0, $max);
    }

    /** Several lines of plain text (blank lines kept, at most two in a row). */
    public static function lines($v, int $max): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", (string)$v);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s) ?? '';
        $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return mb_substr(trim($s), 0, $max);
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** The last nine digits of a phone number: the same line in any format ("082 123 4567", "+27821234567"). */
    public static function phoneKey(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    /** A number WhatsApp and tel: links can dial (South African numbers made international). */
    public static function dialable(string $phone): string
    {
        $raw = trim($phone);
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') return '';
        if (str_starts_with($raw, '+')) return $d;
        if (str_starts_with($d, '00')) return substr($d, 2);
        if (str_starts_with($d, '0') && strlen($d) === 10) return '27' . substr($d, 1);
        if (str_starts_with($d, '27') && strlen($d) === 11) return $d;
        return $d;
    }

    /** "www.Example.co.za/x" or "info@example.co.za" → "example.co.za". */
    public static function domain(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') return '';
        if (str_contains($s, '@')) $s = substr($s, strrpos($s, '@') + 1);
        $s = preg_replace('#^[a-z]+://#', '', $s) ?? $s;
        $s = preg_replace('#[/?\#].*$#', '', $s) ?? $s;
        $s = preg_replace('/^www\./', '', $s) ?? $s;
        $s = trim($s, '. ');
        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $s) ? $s : '';
    }

    /** Free email providers: their domain says nothing about the business. */
    public static function freeMail(string $domain): bool
    {
        return in_array($domain, ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'mweb.co.za', 'telkomsa.net', 'webmail.co.za', 'vodamail.co.za', 'iafrica.com', 'absamail.co.za', 'lantic.net'], true);
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

    /** Text for a latin1 column (purchase order lines): characters it cannot hold become their nearest look-alike. */
    public static function latin1(string $s): string
    {
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $u = @iconv('CP1252', 'UTF-8', $t);
                if ($u !== false) return $u;
            }
        }
        return preg_replace('/[^\x20-\x7E\x{A0}-\x{FF}]/u', '?', $s) ?? $s;
    }

    /** storage/suppliers_ai (closed to the web by storage/.htaccess; created on demand). */
    public static function storageDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/suppliers_ai';
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

    /** Audit trail (never throws). */
    public static function audit(PDO $db, int $companyId, int $userId, string $action, string $entity, ?int $entityId, array $details = []): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $userId, mb_substr($action, 0, 100), $entity, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] audit ' . $action . ': ' . $e->getMessage());
        }
    }

    /** The bell (and the phone) for someone else. Never throws. */
    public static function notify(PDO $db, int $companyId, int $toUser, int $byUser, string $title, string $message, string $url, bool $push = true): void
    {
        if ($toUser <= 0 || $toUser === $byUser) return;
        try {
            require_once dirname(__DIR__, 2) . '/includes/notify.php';
            fw_notify($db, $companyId, $toUser, 'suppliers_ai', $title, $message, $url, $push ? ['ch' => 'general'] : false);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] notify: ' . $e->getMessage());
        }
    }
}
