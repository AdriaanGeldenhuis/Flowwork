<?php
// finances/lib/FinBoot.php
//
// What every Finance page needs before it draws: the company's look, wording,
// parts and rules, the person's layout and what they may do, the financial
// year, the chart of accounts and tax codes (for pickers and Ctrl K), the bank
// accounts, the lock date and the counts in the sidebar. Embedded in each page
// as JSON (no extra round trip) and served by /finances/api/boot.php after
// settings change.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/CoaSchema.php';

final class FinBoot
{
    public static function payload(PDO $db, int $companyId, int $userId, string $role): array
    {
        $cfg = Fin::config($db, $companyId);
        $engine = Fin::engineSettings($db, $companyId);
        $st = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $st->execute([$userId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $today = date('Y-m-d');
        $fyStart = Fin::fyStart($db, $companyId, $today);
        $fyEnd = (new DateTimeImmutable($fyStart))->modify('+1 year -1 day')->format('Y-m-d');

        return [
            'company' => Fin::companyName($db, $companyId),
            'me' => ['id' => $userId, 'first_name' => (string)($me['first_name'] ?? ''), 'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))],
            'role' => $role,
            'can_write' => Fin::canWrite($role),
            'is_admin' => Fin::isAdmin($role),
            'caps' => Fin::caps($role),
            'today' => $today,
            'fy' => ['start' => $fyStart, 'end' => $fyEnd, 'month' => Fin::monthNumber($engine['fiscal_year_start'])],
            'engine' => $engine,
            'config' => $cfg,
            'prefs' => Fin::userPrefs($db, $companyId, $userId),
            'routes' => self::routes($cfg),
            'panels' => Fin::OVERVIEW_PANELS,
            'accounts' => self::accounts($db, $companyId),
            'coa' => ['subtypes' => CoaSchema::subtypesByType(), 'bands' => CoaSchema::codeBands()],
            'tax_codes' => self::taxCodes($db, $companyId),
            'banks' => self::banks($db, $companyId),
            'locked_through' => self::lockedThrough($db, $companyId),
            'people' => array_values(Fin::people($db, $companyId)),
            'counts' => self::counts($db, $companyId, $cfg),
        ];
    }

    /** Routes the company has switched on, with their (re)labels. */
    public static function routes(array $cfg): array
    {
        $out = [];
        foreach (Fin::ROUTES as $key => $r) {
            if ($r['module'] !== null && empty($cfg['modules'][$r['module']])) continue;
            $out[] = ['key' => $key, 'label' => $key === 'overview' ? 'Overview' : ($cfg['labels'][$key] ?? $r['label'])];
        }
        return $out;
    }

    public static function accounts(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT account_id, account_code, account_name, account_type, account_subtype, parent_id,
                                   is_active, is_control, is_system, allow_manual_journal, tax_code_id
                              FROM gl_accounts WHERE company_id = ? ORDER BY account_code");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[] = [
                'id' => (int)$a['account_id'], 'code' => (string)$a['account_code'], 'name' => (string)$a['account_name'],
                'type' => (string)$a['account_type'], 'subtype' => (string)($a['account_subtype'] ?? ''),
                'parent' => $a['parent_id'] ? (int)$a['parent_id'] : null, 'active' => (bool)$a['is_active'],
                'control' => (bool)$a['is_control'], 'system' => (bool)$a['is_system'],
                'manual' => (bool)$a['allow_manual_journal'], 'tax' => $a['tax_code_id'] ? (int)$a['tax_code_id'] : null,
            ];
        }
        return $out;
    }

    public static function taxCodes(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT tax_code_id, code, description, rate_percent, type FROM gl_tax_codes
                             WHERE company_id = ? AND is_active = 1 ORDER BY FIELD(code, 'STD', 'INPUT', 'ZERO', 'EXEMPT') DESC, code");
        $st->execute([$companyId]);
        return array_map(fn($t) => ['id' => (int)$t['tax_code_id'], 'code' => (string)$t['code'], 'name' => (string)$t['description'],
                                    'rate' => (float)$t['rate_percent'], 'type' => (string)$t['type']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function banks(PDO $db, int $companyId): array
    {
        try {
            $st = $db->prepare("SELECT ba.id, ba.name, ba.bank_name, ba.account_no, ba.currency, ba.current_balance_cents, ba.last_reconciled_date,
                                       ga.account_code
                                  FROM gl_bank_accounts ba LEFT JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                                 WHERE ba.company_id = ? AND ba.is_active = 1 ORDER BY ba.name");
            $st->execute([$companyId]);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($b) => [
            'id' => (int)$b['id'], 'name' => (string)$b['name'], 'bank' => (string)($b['bank_name'] ?? ''),
            'last4' => substr(preg_replace('/\D/', '', (string)$b['account_no']), -4), 'currency' => (string)($b['currency'] ?: 'ZAR'),
            'balance' => ((int)$b['current_balance_cents']) / 100, 'reconciled' => $b['last_reconciled_date'], 'account_code' => (string)($b['account_code'] ?? ''),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function lockedThrough(PDO $db, int $companyId): ?string
    {
        $st = $db->prepare("SELECT MAX(lock_date) FROM gl_period_locks WHERE company_id = ? AND is_active = 1");
        $st->execute([$companyId]);
        return $st->fetchColumn() ?: null;
    }

    /** Sidebar badges: what is waiting in each part. Cheap queries only. */
    public static function counts(PDO $db, int $companyId, array $cfg): array
    {
        $one = function (string $sql, array $args) use ($db): int {
            try {
                $st = $db->prepare($sql);
                $st->execute($args);
                return (int)$st->fetchColumn();
            } catch (Throwable $e) {
                return 0;
            }
        };
        $soon = (int)$cfg['thresholds']['due_soon_days'];
        return [
            'journals' => $one("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status IN ('draft','prepared','approved')", [$companyId]),
            'bank' => !empty($cfg['modules']['bank']) ? $one("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND matched = 0", [$companyId]) : 0,
            'receivables' => !empty($cfg['modules']['receivables']) ? $one("SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL
                AND status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded') AND balance_due > 0 AND due_date < CURDATE()", [$companyId]) : 0,
            'payables' => !empty($cfg['modules']['payables']) ? $one("SELECT COUNT(*) FROM ap_bills b
                WHERE b.company_id = ? AND b.status = 'posted' AND b.due_date IS NOT NULL AND b.due_date <= CURDATE() + INTERVAL ? DAY", [$companyId, $soon]) : 0,
            'vat' => !empty($cfg['modules']['vat']) ? $one("SELECT COUNT(*) FROM gl_vat_periods WHERE company_id = ? AND status IN ('open','prepared','adjusted') AND period_end < CURDATE()", [$companyId]) : 0,
        ];
    }

    /** Inline CSS variables for the company's / person's accent. */
    public static function styleVars(array $boot): string
    {
        $accent = $boot['prefs']['accent'] ?: $boot['config']['accent'];
        if (!$accent) return '';
        [$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');
        $dark = sprintf('#%02x%02x%02x', (int)($r * 0.78), (int)($g * 0.78), (int)($b * 0.78));
        $light = sprintf('#%02x%02x%02x', min(255, (int)($r + (255 - $r) * 0.45)), min(255, (int)($g + (255 - $g) * 0.45)), min(255, (int)($b + (255 - $b) * 0.45)));
        return ".fwm.fwf{--accent:$accent;--accent-hover:$dark;--accent-ink:$accent;--accent-solid:$accent;"
             . "--accent-grad:linear-gradient(140deg,$light 0%,$accent 46%,$dark 100%);"
             . "--accent-soft:rgba($r,$g,$b,.08);--accent-softer:rgba($r,$g,$b,.045);--accent-ring:rgba($r,$g,$b,.32)}"
             . ".fwm.fwf[data-theme=\"dark\"]{--accent:$light;--accent-hover:$light;--accent-ink:$light;--accent-solid:$accent;"
             . "--accent-soft:rgba($r,$g,$b,.16);--accent-softer:rgba($r,$g,$b,.09);--accent-ring:rgba($r,$g,$b,.42)}";
    }
}
