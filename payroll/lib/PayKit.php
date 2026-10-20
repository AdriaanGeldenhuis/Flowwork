<?php
// /payroll/lib/PayKit.php — small shared pieces for Payroll pages and APIs:
// who may use Payroll, the company's payroll settings, labels in plain words,
// money and dates, the audit trail, a rate limiter, run numbers and the
// suggested period / pay day for the next run.

require_once __DIR__ . '/PayTax.php';

final class PayKit
{
    /** Pay frequencies as people say them. */
    public const FREQ = ['monthly' => 'Monthly', 'fortnight' => 'Every two weeks', 'weekly' => 'Weekly'];
    public const FREQ_SHORT = ['monthly' => 'month', 'fortnight' => 'fortnight', 'weekly' => 'week'];
    public const TYPES = ['permanent' => 'Permanent', 'contract' => 'Fixed-term contract', 'casual' => 'Casual'];
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const ACCOUNT_TYPES = ['cheque' => 'Cheque / current', 'savings' => 'Savings', 'transmission' => 'Transmission'];

    /** Pay item kinds: label, what it means. */
    public const ITEM_TYPES = [
        'earning' => ['Earning', 'Paid to the person, like overtime, commission or an allowance.'],
        'deduction' => ['Deduction', 'Taken off net pay, like medical aid, pension or a loan repayment.'],
        'reimbursement' => ['Reimbursement', 'Money paid back for costs, like petrol or a tool. Not income, so no tax.'],
        'benefit' => ['Fringe benefit', 'A taxable benefit that is not paid in cash, like the use of a company car.'],
        'contribution' => ['Employer contribution', 'Paid by the company on top of pay, like its share of medical aid or pension.'],
    ];

    /** South African banks with their universal branch codes (check against a bank statement). */
    public const BANKS = [
        'ABSA' => '632005', 'African Bank' => '430000', 'Bidvest Bank' => '462005', 'Capitec' => '470010',
        'Discovery Bank' => '679000', 'FNB' => '250655', 'Investec' => '580105', 'Nedbank' => '198765',
        'Postbank' => '460005', 'Standard Bank' => '051001', 'TymeBank' => '678910',
    ];

    /** Run statuses grouped the way people think about them. */
    public const STAGE = [
        'draft' => 'prepare', 'calculated' => 'prepare', 'review' => 'prepare',
        'approved' => 'approved', 'locked' => 'final', 'posted' => 'booked',
    ];
    public const STAGE_LABEL = ['prepare' => 'Being prepared', 'approved' => 'Approved', 'final' => 'Finalised', 'booked' => 'In the books'];

    public static function e($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    /** Payroll holds salaries, ID and bank numbers: admins and bookkeepers only. */
    public static function canUse(string $role): bool
    {
        return in_array(strtolower($role), ['admin', 'bookkeeper'], true);
    }

    /** Settings that change how everyone is paid are for admins. */
    public static function canConfigure(string $role): bool
    {
        return strtolower($role) === 'admin';
    }

    /** The company's payroll settings (a row is created with the defaults the first time). */
    public static function settings(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT * FROM payroll_settings WHERE company_id = ?");
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            try {
                $db->prepare("INSERT IGNORE INTO payroll_settings (company_id) VALUES (?)")->execute([$companyId]);
            } catch (PDOException $e) {
                error_log('[payroll] settings row: ' . $e->getMessage());
            }
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $ot = json_decode((string)($row['overtime_rules_json'] ?? ''), true);
        return [
            'default_frequency' => isset(self::FREQ[$row['default_frequency'] ?? '']) ? $row['default_frequency'] : 'monthly',
            'monthly_anchor_day' => max(1, min(31, (int)($row['monthly_anchor_day'] ?? 25) ?: 25)),
            'weekly_anchor_day' => max(1, min(7, (int)($row['weekly_anchor_day'] ?? 5) ?: 5)),
            'auto_post_to_finance' => (int)($row['auto_post_to_finance'] ?? 0) === 1,
            'sdl_liable' => (int)($row['sdl_liable'] ?? 1) === 1,
            'hours_per_week' => (float)($row['hours_per_week'] ?? 45) ?: 45.0,
            'variance_pct' => (float)($row['require_approval_for_variance_pct'] ?? 10),
            'bank_account_id' => !empty($row['default_bank_account_id']) ? (int)$row['default_bank_account_id'] : null,
            'paye_reference' => (string)($row['paye_reference'] ?? ''),
            'overtime' => [
                'weekday' => (float)($ot['weekday'] ?? 1.5) ?: 1.5,
                'sunday' => (float)($ot['sunday'] ?? 2) ?: 2.0,
                'holiday' => (float)($ot['holiday'] ?? 2) ?: 2.0,
            ],
        ];
    }

    public static function money(int $cents, bool $symbol = true): string
    {
        $neg = $cents < 0;
        $s = number_format(abs($cents) / 100, 2, '.', ' ');
        return ($neg ? '−' : '') . ($symbol ? 'R ' : '') . $s;
    }

    public static function date(?string $d, bool $year = true): string
    {
        if (!$d || !strtotime($d)) return '';
        return date($year ? 'j M Y' : 'j M', strtotime($d));
    }

    public static function period(string $from, string $to): string
    {
        $a = strtotime($from);
        $b = strtotime($to);
        if (date('Y-m', $a) === date('Y-m', $b) && date('j', $a) === '1' && date('Y-m-t', $a) === date('Y-m-d', $b)) return date('F Y', $a);
        if (date('Y', $a) === date('Y', $b)) return date('j M', $a) . ' – ' . date('j M Y', $b);
        return date('j M Y', $a) . ' – ' . date('j M Y', $b);
    }

    public static function stage(string $status): string
    {
        return self::STAGE[$status] ?? 'prepare';
    }

    public static function audit(PDO $db, int $companyId, int $userId, string $action, array $details = [], ?string $entityType = null, ?int $entityId = null): void
    {
        try {
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip, timestamp, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
               ->execute([$companyId, $userId ?: null, substr($action, 0, 100), $entityType, $entityId,
                          $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                          substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('[payroll] audit ' . $action . ': ' . $e->getMessage());
        }
    }

    /** Sliding-window limit; returns seconds to wait, or 0 when the action may go ahead. */
    public static function rateLimit(string $key, int $max, int $windowSeconds): int
    {
        $dir = dirname(__DIR__, 2) . '/storage/payroll';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
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

    /** The next free run number for the pay date's year: 2026-0001, 2026-0002 … (call inside a transaction). */
    public static function nextRunNumber(PDO $db, int $companyId, string $payDate): string
    {
        $year = date('Y', strtotime($payDate));
        $st = $db->prepare("SELECT run_number FROM pay_runs WHERE company_id = ? AND run_number LIKE ? ORDER BY run_number DESC LIMIT 1 FOR UPDATE");
        $st->execute([$companyId, $year . '-%']);
        $last = (string)$st->fetchColumn();
        $n = $last !== '' ? (int)substr($last, strlen($year) + 1) : 0;
        return $year . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Move a pay day that falls on a weekend to the Friday before. */
    public static function businessDay(DateTimeImmutable $d): DateTimeImmutable
    {
        while ((int)$d->format('N') >= 6) $d = $d->modify('-1 day');
        return $d;
    }

    /**
     * The period and pay day the next run of a frequency should cover: the
     * period after the last run of that frequency, or the current one.
     *   monthly    the calendar month; paid on the pay day (Friday before if a weekend)
     *   weekly     seven days ending on the pay weekday; paid that day
     *   fortnight  fourteen days ending on the pay weekday; paid that day
     */
    public static function suggest(PDO $db, int $companyId, string $freq, array $settings): array
    {
        $st = $db->prepare("SELECT period_end FROM pay_runs WHERE company_id = ? AND frequency = ? ORDER BY period_end DESC LIMIT 1");
        $st->execute([$companyId, $freq]);
        $lastEnd = $st->fetchColumn();
        $today = new DateTimeImmutable('today');
        if ($freq === 'monthly') {
            $start = $lastEnd ? (new DateTimeImmutable($lastEnd))->modify('+1 day') : $today->modify('first day of this month');
            $start = $start->modify('first day of this month');
            $end = $start->modify('last day of this month');
            $day = min($settings['monthly_anchor_day'], (int)$end->format('j'));
            $pay = self::businessDay($start->setDate((int)$start->format('Y'), (int)$start->format('n'), $day));
            $name = $start->format('F Y');
        } else {
            $len = $freq === 'weekly' ? 7 : 14;
            if ($lastEnd) {
                $start = (new DateTimeImmutable($lastEnd))->modify('+1 day');
                $end = $start->modify('+' . ($len - 1) . ' days');
            } else {
                $wd = $settings['weekly_anchor_day'];
                $end = $today;
                while ((int)$end->format('N') !== $wd) $end = $end->modify('+1 day');
                $start = $end->modify('-' . ($len - 1) . ' days');
            }
            $pay = $end;
            $name = ($freq === 'weekly' ? 'Week ending ' : 'Fortnight ending ') . $end->format('j F Y');
        }
        return ['name' => $name, 'frequency' => $freq, 'period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d'), 'pay_date' => $pay->format('Y-m-d')];
    }

    /** CSV download: UTF-8 with a BOM, and cells that could be formulas are made inert. */
    public static function csv(string $filename, array $head, array $rows): void
    {
        $safe = function ($v): string {
            $v = (string)($v ?? '');
            if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !is_numeric($v)) $v = "'" . $v;
            return $v;
        };
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $head);
        foreach ($rows as $r) fputcsv($out, array_map($safe, $r));
        fclose($out);
        exit;
    }

    /** Rand amount as entered ("12 345,67", "R12345.67") → cents, or null when it is not a number. */
    public static function cents($v): ?int
    {
        if (is_int($v)) return $v * 100;
        $s = str_replace([' ', "\u{00a0}", 'R', 'r'], '', trim((string)$v));
        if ($s === '') return 0;
        if (preg_match('/^-?\d+,\d{1,2}$/', $s)) $s = str_replace(',', '.', $s);
        $s = str_replace(',', '', $s);
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) return null;
        return (int)round((float)$s * 100);
    }
}
