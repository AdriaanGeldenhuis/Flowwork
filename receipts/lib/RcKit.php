<?php
// /receipts/lib/RcKit.php — Receipts' shared vocabulary:
//   - who may do what (upload, see everyone's slips, capture and post bills,
//     change settings, override a policy block),
//   - the company's receipt settings (receipt_settings, one row a company),
//   - what state a receipt is in, as SQL and as labels,
//   - money in cents, dates, a file-based rate limiter and the audit trail.

final class RcProblem extends RuntimeException {}   // a message for the person; the code is the HTTP status
final class RcForbidden extends RuntimeException {}

final class RcKit
{
    /** States a receipt can be in (derived — see statusSql()). */
    public const STATUS = [
        'reading' => 'Being read',
        'review'  => 'To review',
        'draft'   => 'Draft bill',
        'posted'  => 'In the books',
        'paid'    => 'Paid',
        'aside'   => 'Set aside',
    ];
    public const STATUS_TONE = ['reading' => 'info', 'review' => 'gold', 'draft' => 'accent', 'posted' => 'success', 'paid' => 'success', 'aside' => 'muted'];

    /** Why a receipt was set aside. */
    public const ASIDE_REASONS = [
        'duplicate' => 'A duplicate of another receipt',
        'personal'  => 'Personal, not a business expense',
        'not_bill'  => 'Not a bill (a quote, statement or delivery note)',
        'elsewhere' => 'Already captured in Finance',
        'other'     => 'Something else',
    ];

    /** How a slip was paid when it was paid on the spot. */
    public const PAID_METHODS = ['card' => 'Company card', 'eft' => 'EFT', 'cash' => 'Cash', 'other' => 'Other'];

    public const READERS = ['ai' => 'AI reader', 'tesseract' => 'Text recognition on the server', 'manual' => 'Type it in'];
    public const AI_MODELS = ['gpt-4o-mini' => 'GPT-4o mini (fast, low cost)', 'gpt-4o' => 'GPT-4o (most accurate)'];

    public const DEFAULTS = [
        'require_po_over_amount' => 0.0,
        'require_3way_match' => 0,
        'variance_tolerance_pct' => 5.0,
        'line_variance_tolerance_pct' => 20.0,
        'require_vat_number' => 1,
        'block_expired_compliance' => 1,
        'default_expense_account' => '',
        'max_file_size_mb' => 15,
        'ocr_provider' => 'tesseract',
        'ai_model' => 'gpt-4o-mini',
        'auto_split_lines' => 1,
        'default_due_days' => 30,
        'retention_days' => 2555,
    ];

    // ------------------------------------------------------------------ roles

    public static function role(string $role): string
    {
        return strtolower(trim($role));
    }

    /** Snap and send in a slip. Viewers only look. */
    public static function canUpload(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper', 'member'], true);
    }

    /** See every receipt of the company (others see the ones they sent in). */
    public static function canSeeAll(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper', 'viewer'], true);
    }

    /** Turn receipts into bills, post them, set them aside — Finance's writers. */
    public static function canCapture(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper'], true);
    }

    public static function canConfigure(string $role): bool
    {
        return in_array(self::role($role), ['admin', 'bookkeeper'], true);
    }

    /** Post past a policy block (with a reason that stays on record). */
    public static function canOverride(string $role): bool
    {
        return self::role($role) === 'admin';
    }

    public static function caps(string $role): array
    {
        return ['upload' => self::canUpload($role), 'all' => self::canSeeAll($role), 'capture' => self::canCapture($role),
                'settings' => self::canConfigure($role), 'override' => self::canOverride($role)];
    }

    // --------------------------------------------------------------- settings

    public static function settings(PDO $db, int $companyId): array
    {
        $row = [];
        try {
            $st = $db->prepare("SELECT * FROM receipt_settings WHERE company_id = ? ORDER BY setting_id LIMIT 1");
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[receipts] settings: ' . $e->getMessage());
        }
        $s = self::DEFAULTS;
        foreach ($s as $k => $def) {
            if (!array_key_exists($k, $row) || $row[$k] === null) continue;
            $s[$k] = is_float($def) ? (float)$row[$k] : (is_int($def) ? (int)$row[$k] : (string)$row[$k]);
        }
        if (!isset(self::READERS[$s['ocr_provider']])) $s['ocr_provider'] = 'tesseract'; // retired google / aws / azure never had keys
        if (!isset(self::AI_MODELS[$s['ai_model']])) $s['ai_model'] = 'gpt-4o-mini';
        $s['max_file_size_mb'] = max(1, min(25, $s['max_file_size_mb']));
        return $s;
    }

    /** Write some settings (already checked by the caller). */
    public static function saveSettings(PDO $db, int $companyId, array $values): void
    {
        $values = array_intersect_key($values, self::DEFAULTS);
        if (!$values) return;
        $st = $db->prepare("SELECT setting_id FROM receipt_settings WHERE company_id = ? ORDER BY setting_id LIMIT 1");
        $st->execute([$companyId]);
        $id = (int)$st->fetchColumn();
        if (!$id) {
            // The first save writes the defaults the page showed, not the table's older column defaults.
            $row = $values + self::DEFAULTS;
            $cols = array_keys($row);
            $db->prepare("INSERT INTO receipt_settings (company_id, `" . implode('`, `', $cols) . "`) VALUES (?" . str_repeat(', ?', count($cols)) . ")")
               ->execute(array_merge([$companyId], array_values($row)));
            return;
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($values)));
        $db->prepare("UPDATE receipt_settings SET $sets WHERE setting_id = ? AND company_id = ?")->execute(array_merge(array_values($values), [$id, $companyId]));
    }

    // ----------------------------------------------------------------- states

    /**
     * The state of a receipt, from receipt_file `rf` and its bill `b`
     * (LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id).
     * A bill deleted in Finance leaves the receipt to review again.
     */
    public static function statusSql(): string
    {
        return "CASE WHEN rf.dismissed_at IS NOT NULL THEN 'aside'
                     WHEN b.id IS NOT NULL AND b.status = 'paid' THEN 'paid'
                     WHEN b.id IS NOT NULL AND b.status = 'posted' THEN 'posted'
                     WHEN b.id IS NOT NULL THEN 'draft'
                     WHEN rf.ocr_status = 'pending' THEN 'reading'
                     ELSE 'review' END";
    }

    /** Receipts this person may see: everyone's, or the ones they sent in. */
    public static function scopeSql(string $role, int $userId, array &$args): string
    {
        if (self::canSeeAll($role)) return '1 = 1';
        $args[] = $userId;
        return 'rf.uploaded_by = ?';
    }

    // ----------------------------------------------------------------- helpers

    /** Money from a form ("1 234,50", "R1,234.50") to cents; null when not a number. */
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

    public static function money(int $cents): string
    {
        return ($cents < 0 ? '−' : '') . 'R ' . number_format(abs($cents) / 100, 2, '.', "\u{00A0}");
    }

    public static function ymd($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    }

    public static function date(?string $ymd, bool $year = true): string
    {
        if (!$ymd || !($t = strtotime(substr($ymd, 0, 10)))) return '';
        return date($year ? 'j M Y' : 'j M', $t);
    }

    public static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** A person's name in this company ('' when unknown). */
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

    /** storage/receipts (closed to the web by storage/.htaccess; created on demand). */
    public static function storageDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/receipts';
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
    public static function audit(PDO $db, int $companyId, int $userId, string $action, ?int $fileId, array $details = []): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, created_at) VALUES (?, ?, ?, 'receipt', ?, ?, ?, NOW())")
               ->execute([$companyId, $userId, mb_substr($action, 0, 100), $fileId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {
            error_log('[receipts] audit ' . $action . ': ' . $e->getMessage());
        }
    }

    /** A bell (and, when asked, the phone) for the person who sent a receipt in. Never throws. */
    public static function notify(PDO $db, int $companyId, int $toUser, int $byUser, string $type, string $title, string $message, string $url, bool $push = false): void
    {
        if ($toUser <= 0 || $toUser === $byUser) return;
        try {
            require_once dirname(__DIR__, 2) . '/includes/notify.php';
            fw_notify($db, $companyId, $toUser, $type, $title, $message, $url, $push ? ['ch' => 'general'] : false);
        } catch (Throwable $e) {
            error_log('[receipts] notify: ' . $e->getMessage());
        }
    }
}
