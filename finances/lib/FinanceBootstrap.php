<?php
// finances/lib/FinanceBootstrap.php
//
// Self-healing finance foundation for a company. Every save/posting path
// depends on three things existing per company:
//   1. the core VAT codes (STD, ZERO, EXEMPT, INPUT) in gl_tax_codes,
//   2. a chart of accounts, and
//   3. an account for every finance_* mapping role (AccountsMap::DEFAULTS).
//
// These used to come only from manual migration / CLI steps, so a company
// created later — or a legacy chart whose codes differ from the SARS seed —
// failed in the middle of daily work with "Unknown tax code 'ZERO'" or
// "Unknown GL account code(s): 1020". This class provisions whatever is
// missing, idempotently, at the point of need:
//   - missing core tax codes are inserted (an inactive one is reactivated);
//   - a company with no chart at all gets the full SARS chart (CoaSeed);
//   - a mapping role with no usable account is resolved to the company's own
//     account for that role (by subtype, then by name), or the seed account
//     is created, and the choice is pinned in company_settings so it is
//     stable and visible (and changeable) in Finance Settings.
// An explicit mapping that points at a real account is never overridden.
//
// Provisioning is best-effort: a database error in a provisioning step is
// logged and the caller carries on exactly as it did before this class
// existed (lock errors excepted — see guarded()), so self-healing can never
// break a flow that would otherwise have worked.

require_once __DIR__ . '/CoaSeed.php';
require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/Audit.php';

class FinanceBootstrap
{
    /** code => [description, rate_percent, type] — mirrors Migration 2026-07-06-02. */
    public const CORE_TAX_CODES = [
        'STD'    => ['Standard rate output VAT (15%)', 15.00, 'output'],
        'ZERO'   => ['Zero-rated output (0%)', 0.00, 'output'],
        'EXEMPT' => ['Exempt supplies', 0.00, 'exempt'],
        'INPUT'  => ['Standard rate input VAT (15%)', 15.00, 'input'],
    ];

    /**
     * What makes an account fit each mapping role.
     *   types    allowed account_type values
     *   subtypes account_subtype values that identify the role
     *   names    lower-case name fragments that identify the role
     *   exclude  lower-case name fragments that disqualify an account
     *   match    'subtype_or_name' (default): subtype OR name identifies it;
     *            'subtype_and_name': subtype AND (any) name are required;
     *            'name': the name is required (legacy charts reuse the code);
     *            'type': the type alone validates the default code — names
     *            are only used to search (a renamed seed account stays valid)
     *   control  'prefer' | 'avoid' | null — is_control ordering on search
     */
    private const ROLES = [
        'finance_ar_account_id' => [
            'types' => ['asset'], 'subtypes' => ['accounts_receivable'],
            'names' => ['receivable', 'debtors'],
            'exclude' => ['allowance', 'doubtful', 'sundry', 'staff', 'loan'], 'control' => 'prefer'],
        'finance_ap_account_id' => [
            'types' => ['liability'], 'subtypes' => ['accounts_payable'],
            'names' => ['accounts payable', 'trade creditors'],
            'exclude' => ['sundry'], 'control' => 'prefer'],
        'finance_bank_account_id' => [
            'types' => ['asset'], 'subtypes' => ['bank'], 'names' => ['bank'],
            'exclude' => ['control'], 'control' => 'avoid'],
        'finance_sales_account_id' => [
            'types' => ['revenue'], 'subtypes' => [], 'names' => ['sales', 'turnover'],
            'exclude' => ['return', 'discount', 'zero', 'exempt', 'export'], 'match' => 'type'],
        'finance_vat_output_account_id' => [
            'types' => ['liability'], 'subtypes' => ['vat_control'], 'names' => ['output'],
            'exclude' => ['input'], 'match' => 'subtype_and_name'],
        'finance_vat_input_account_id' => [
            'types' => ['liability'], 'subtypes' => ['vat_control'], 'names' => ['input'],
            'exclude' => ['output', 'pending'], 'match' => 'subtype_and_name'],
        'finance_vat_control_account_id' => [
            'types' => ['liability'], 'subtypes' => ['vat_control'], 'names' => [],
            'exclude' => ['output', 'input'], 'match' => 'subtype_and_name'],
        'finance_inventory_account_id' => [
            'types' => ['asset'], 'subtypes' => ['inventory'], 'names' => ['inventory', 'stock on hand'],
            'exclude' => ['transit', 'progress', 'raw material', 'finished']],
        'finance_cogs_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['cost of goods sold', 'cost of sales'],
            'exclude' => ['opening', 'closing'], 'match' => 'type'],
        'finance_expense_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['purchases'],
            'exclude' => [], 'match' => 'type'],
        'finance_wage_expense_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['salaries', 'wages'],
            'exclude' => ['payable', 'director'], 'match' => 'type'],
        'finance_paye_account_id' => [
            'types' => ['liability'], 'subtypes' => ['paye_liability'], 'names' => ['paye'],
            'exclude' => []],
        'finance_uif_account_id' => [
            'types' => ['liability'], 'subtypes' => ['uif_liability'], 'names' => ['uif'],
            'exclude' => []],
        'finance_sdl_account_id' => [
            'types' => ['liability'], 'subtypes' => ['sdl_liability'], 'names' => ['sdl', 'skills development'],
            'exclude' => []],
        'finance_uif_expense_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['uif'],
            'exclude' => [], 'match' => 'type'],
        'finance_sdl_expense_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['sdl', 'skills development'],
            'exclude' => [], 'match' => 'type'],
        // Resolved by NAME: legacy charts reuse 6800 for Depreciation.
        'finance_bad_debt_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['bad debt'],
            'exclude' => ['provision', 'doubtful'], 'match' => 'name'],
        'finance_fx_gain_account_id' => [
            'types' => ['revenue'], 'subtypes' => ['forex_gain'],
            'names' => ['forex gain', 'exchange gain', 'fx gain'], 'exclude' => []],
        'finance_fx_loss_account_id' => [
            'types' => ['expense'], 'subtypes' => ['forex_loss'],
            'names' => ['forex loss', 'exchange loss', 'fx loss'], 'exclude' => []],
        'finance_gain_on_disposal_account_id' => [
            'types' => ['revenue'], 'subtypes' => [], 'names' => ['disposal'],
            'exclude' => ['loss'], 'match' => 'type'],
        'finance_loss_on_disposal_account_id' => [
            'types' => ['expense'], 'subtypes' => [], 'names' => ['disposal'],
            'exclude' => ['profit', 'gain'], 'match' => 'type'],
        'finance_retained_earnings_account_id' => [
            'types' => ['equity'], 'subtypes' => ['retained_earnings'],
            'names' => ['retained earnings', 'retained income'],
            'exclude' => ['dividend']],
    ];

    /** @var array<int, true> companies whose foundation was verified this request */
    private static array $verified = [];

    private static int $savepoints = 0;

    /**
     * Make sure the company has its core tax codes and a chart of accounts.
     * Cheap after the first call per request (and per company).
     */
    public static function ensure(PDO $db, int $companyId): void
    {
        if ($companyId <= 0 || isset(self::$verified[$companyId])) {
            return;
        }
        $changed = self::ensureTaxCodes($db, $companyId);
        $changed = self::ensureChart($db, $companyId) || $changed;
        // Only a clean check is cached: work done inside a caller's
        // transaction may still roll back, so verify again next time.
        if (!$changed) {
            self::$verified[$companyId] = true;
        }
    }

    /**
     * Full set-up for a (new) company: tax codes, chart, and every mapping
     * role pinned in company_settings so Finance Settings shows them.
     */
    public static function provisionCompany(PDO $db, int $companyId): void
    {
        self::inTransaction($db, function () use ($db, $companyId) {
            self::ensure($db, $companyId);
            $map = new AccountsMap($db, $companyId);
            foreach (array_keys(AccountsMap::DEFAULTS) as $key) {
                if ($map->explicitCode($key) === null) {
                    self::resolveMapping($db, $companyId, $key, true);
                }
            }
        });
    }

    /**
     * Insert any missing core VAT code (reactivating an inactive one).
     *
     * @return bool true when anything was changed
     */
    public static function ensureTaxCodes(PDO $db, int $companyId): bool
    {
        return self::guarded('tax codes', fn () => self::provisionTaxCodes($db, $companyId), false);
    }

    private static function provisionTaxCodes(PDO $db, int $companyId): bool
    {
        $stmt = $db->prepare("SELECT code FROM gl_tax_codes WHERE company_id = ? AND is_active = 1");
        $stmt->execute([$companyId]);
        $active = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $active[strtoupper(trim((string)$code))] = true;
        }
        $missing = array_diff_key(self::CORE_TAX_CODES, $active);
        if (!$missing) {
            return false;
        }

        $find = $db->prepare("SELECT tax_code_id FROM gl_tax_codes WHERE company_id = ? AND code = ? LIMIT 1");
        $reactivate = $db->prepare("UPDATE gl_tax_codes SET is_active = 1 WHERE tax_code_id = ?");
        $insert = $db->prepare(
            "INSERT INTO gl_tax_codes (company_id, code, description, rate_percent, type, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        foreach ($missing as $code => [$description, $rate, $type]) {
            $find->execute([$companyId, $code]);
            $existingId = $find->fetchColumn();
            if ($existingId) {
                $reactivate->execute([(int)$existingId]);
            } else {
                $insert->execute([$companyId, $code, $description, $rate, $type]);
            }
        }
        Audit::log('finance_tax_codes_provisioned', ['codes' => array_keys($missing)],
            'company', $companyId, $companyId);
        return true;
    }

    /**
     * Seed the SARS chart for a company that has no accounts at all.
     *
     * @return bool true when the chart was seeded
     */
    public static function ensureChart(PDO $db, int $companyId): bool
    {
        return self::guarded('chart of accounts', function () use ($db, $companyId) {
            $stmt = $db->prepare("SELECT 1 FROM gl_accounts WHERE company_id = ? LIMIT 1");
            $stmt->execute([$companyId]);
            if ($stmt->fetchColumn()) {
                return false;
            }
            // All-or-nothing: a half-seeded chart would no longer count as
            // empty and would never be completed.
            $count = self::inTransaction($db, function () use ($db, $companyId) {
                self::provisionTaxCodes($db, $companyId); // seed rows link default tax codes
                return CoaSeed::seedCompany($db, $companyId);
            });
            if ($count > 0) {
                Audit::log('finance_chart_seeded', ['accounts' => $count], 'company', $companyId, $companyId);
            }
            return $count > 0;
        }, false);
    }

    /**
     * Account code for a mapping role that has no usable explicit setting.
     *
     * Returns the canonical default code without writing anything when that
     * account exists and plays the role. Otherwise finds the company's own
     * account for the role, or creates the seed account, and pins it.
     * $pinDefault also pins a valid default (used when provisioning).
     *
     * @return string|null null only for an unknown mapping key
     */
    public static function resolveMapping(PDO $db, int $companyId, string $key, bool $pinDefault = false): ?string
    {
        return self::guarded("mapping $key", fn () => self::provisionMapping($db, $companyId, $key, $pinDefault), null);
    }

    private static function provisionMapping(PDO $db, int $companyId, string $key, bool $pinDefault): ?string
    {
        $role = self::ROLES[$key] ?? null;
        $defaultCode = AccountsMap::DEFAULTS[$key] ?? null;
        if ($role === null || $defaultCode === null) {
            return null;
        }

        $default = self::accountByCode($db, $companyId, $defaultCode);
        if (!$default && self::ensureChart($db, $companyId)) {
            $default = self::accountByCode($db, $companyId, $defaultCode);
        }
        if ($default && self::playsRole($default, $role)) {
            if ($pinDefault) {
                self::pinMapping($db, $companyId, $key, (int)$default['account_id']);
            }
            return $defaultCode;
        }

        $account = self::findRoleAccount($db, $companyId, $key, $role);
        $how = $account ? 'matched' : 'created';
        // Create + pin together, so a failure leaves neither behind.
        $account = self::inTransaction($db, function () use ($db, $companyId, $key, $role, $defaultCode, $account) {
            $account = $account ?: self::createRoleAccount($db, $companyId, $defaultCode, $role);
            self::pinMapping($db, $companyId, $key, (int)$account['account_id']);
            return $account;
        });
        Audit::log('finance_mapping_provisioned', [
            'setting_key'  => $key,
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'how'          => $how,
        ], 'company', $companyId, $companyId);
        return (string)$account['account_code'];
    }

    // ------------------------------------------------------------------

    /**
     * Run a provisioning step. A database error is logged and $fallback
     * returned, so the caller proceeds as it would have without
     * provisioning. Deadlocks / lock-wait timeouts are rethrown: MariaDB may
     * have rolled back the caller's whole transaction, which must fail.
     */
    private static function guarded(string $what, callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            $info = $e->errorInfo ?? [];
            if (($info[0] ?? '') === '40001' || in_array((int)($info[1] ?? 0), [1205, 1213], true)) {
                throw $e;
            }
            error_log("FinanceBootstrap: provisioning $what failed: " . $e->getMessage());
            return $fallback;
        }
    }

    /**
     * Run $fn atomically: in its own transaction, or — inside the caller's —
     * under a savepoint, so a failed step leaves no partial rows behind.
     */
    private static function inTransaction(PDO $db, callable $fn)
    {
        if ($db->inTransaction()) {
            $savepoint = 'finance_bootstrap_' . (++self::$savepoints);
            $db->exec("SAVEPOINT $savepoint");
            try {
                $result = $fn();
                $db->exec("RELEASE SAVEPOINT $savepoint");
                return $result;
            } catch (Throwable $e) {
                try {
                    $db->exec("ROLLBACK TO SAVEPOINT $savepoint");
                } catch (Throwable $ignored) {
                    // The server already rolled the transaction back (deadlock).
                }
                throw $e;
            }
        }
        $db->beginTransaction();
        try {
            $result = $fn();
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function accountByCode(PDO $db, int $companyId, string $code): ?array
    {
        $stmt = $db->prepare(
            "SELECT account_id, account_code, account_name, account_type, account_subtype, is_control
               FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1"
        );
        $stmt->execute([$companyId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function nameMatches(string $name, array $fragments): bool
    {
        $name = strtolower($name);
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($name, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /** Does this account fit the role (see ROLES for the rules)? */
    private static function playsRole(array $account, array $role): bool
    {
        if (!in_array($account['account_type'], $role['types'], true)) {
            return false;
        }
        $name = (string)$account['account_name'];
        if (self::nameMatches($name, $role['exclude'])) {
            return false;
        }
        $subtypeOk = in_array((string)$account['account_subtype'], $role['subtypes'], true);
        $nameOk = self::nameMatches($name, $role['names']);
        switch ($role['match'] ?? 'subtype_or_name') {
            case 'type':
                return true;
            case 'name':
                return $nameOk;
            case 'subtype_and_name':
                return $subtypeOk && (!$role['names'] || $nameOk);
            default:
                return $subtypeOk || $nameOk;
        }
    }

    /**
     * The company's own account for a role: (bank only) the GL account
     * linked to a Banking → Accounts entry, then accounts carrying the role's
     * subtype, then accounts named for the role. Header accounts (those with
     * children) and inactive accounts are never chosen. Within a tier,
     * accounts that already carry postings win, then the lowest code.
     */
    private static function findRoleAccount(PDO $db, int $companyId, string $key, array $role): ?array
    {
        if ($key === 'finance_bank_account_id') {
            $stmt = $db->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name, ga.account_type,
                        ga.account_subtype, ga.is_control
                   FROM gl_bank_accounts ba
                   JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                  WHERE ba.company_id = ? AND ba.is_active = 1 AND ga.is_active = 1
                  ORDER BY ba.id"
            );
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // The user linked it as a bank account — its name/subtype
                // need not say "bank" (e.g. "FNB Business Cheque").
                if (in_array($row['account_type'], $role['types'], true)) {
                    return $row;
                }
            }
        }

        $ph = implode(',', array_fill(0, count($role['types']), '?'));
        $stmt = $db->prepare(
            "SELECT a.account_id, a.account_code, a.account_name, a.account_type,
                    a.account_subtype, a.is_control,
                    EXISTS (SELECT 1 FROM journal_lines jl
                              JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = a.company_id
                               AND jl.account_code = a.account_code) AS has_postings
               FROM gl_accounts a
              WHERE a.company_id = ? AND a.is_active = 1 AND a.account_type IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.parent_id = a.account_id)"
        );
        $stmt->execute(array_merge([$companyId], $role['types']));
        $candidates = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn(array $a) => self::playsRole($a, $role)
        ));

        $control = $role['control'] ?? null;
        usort($candidates, function (array $a, array $b) use ($control) {
            if ($control !== null && (int)$a['is_control'] !== (int)$b['is_control']) {
                $aFirst = $control === 'prefer' ? (int)$a['is_control'] : 1 - (int)$a['is_control'];
                return $aFirst ? -1 : 1;
            }
            if ((int)$a['has_postings'] !== (int)$b['has_postings']) {
                return (int)$b['has_postings'] <=> (int)$a['has_postings'];
            }
            return strcmp((string)$a['account_code'], (string)$b['account_code']);
        });

        $tiers = [];
        if ($role['subtypes']) {
            $tiers[] = fn(array $a) => in_array((string)$a['account_subtype'], $role['subtypes'], true);
        }
        if ($role['names']) {
            $tiers[] = fn(array $a) => self::nameMatches((string)$a['account_name'], $role['names']);
        }
        foreach ($tiers as $inTier) {
            foreach ($candidates as $a) {
                if ($inTier($a)) {
                    return $a;
                }
            }
        }
        return null;
    }

    /**
     * Create the seed account for a role at its default code, or at the next
     * free code in the same hundred when a different account holds it.
     */
    private static function createRoleAccount(PDO $db, int $companyId, string $defaultCode, array $role): array
    {
        $def = CoaSeed::definition($defaultCode);
        if ($def === null) {
            throw new Exception("No seed account for code $defaultCode — configure the mapping in Finance Settings");
        }

        $base = (int)$defaultCode;
        $hundred = intdiv($base, 100) * 100;
        $candidates = [$base];
        for ($n = $base + 1; $n <= $hundred + 99; $n++) {
            $candidates[] = $n;
        }
        for ($n = $hundred + 1; $n < $base; $n++) {
            $candidates[] = $n;
        }
        foreach ($candidates as $n) {
            $code = (string)$n;
            $existing = self::accountByCode($db, $companyId, $code);
            if ($existing) {
                continue;
            }
            $parentId = null;
            if ($def['parent_code'] !== null) {
                $parent = self::accountByCode($db, $companyId, $def['parent_code']);
                if ($parent && $parent['account_type'] === $def['account_type']) {
                    $parentId = (int)$parent['account_id'];
                }
            }
            CoaSeed::insertAccount($db, $companyId, $def, $code, $parentId);
            // Re-read: a concurrent request may have created the code first.
            $created = self::accountByCode($db, $companyId, $code);
            if ($created && self::playsRole($created, $role)) {
                return $created;
            }
        }
        throw new Exception("No free account code near $defaultCode — configure the mapping in Finance Settings");
    }

    /** Store the mapping as the account id (what AccountsMap and Finance Settings read). */
    private static function pinMapping(PDO $db, int $companyId, string $key, int $accountId): void
    {
        $stmt = $db->prepare("SELECT 1 FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1");
        $stmt->execute([$companyId, $key]);
        if ($stmt->fetchColumn()) {
            $db->prepare("UPDATE company_settings SET setting_value = ? WHERE company_id = ? AND setting_key = ?")
               ->execute([(string)$accountId, $companyId, $key]);
        } else {
            $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)")
               ->execute([$companyId, $key, (string)$accountId]);
        }
    }
}
