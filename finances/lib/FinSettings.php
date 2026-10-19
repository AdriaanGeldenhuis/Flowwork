<?php
// finances/lib/FinSettings.php
//
// "Make it yours" for the company (administrators): wording, which parts of
// Finance are on, the overview layout, thresholds and rules — kept in
// fin_prefs (user 0) — plus the settings the posting engine reads from
// company_settings (financial year start, VAT basis, segregation of duties,
// account mappings) and exchange rates. Each person's own look is saved
// through /finances/api/prefs.php.
//
// Account mappings keep the old screen's checks (the account exists, is the
// right kind, and the roles that must differ do), now also against the
// mappings already saved, not only the ones in the same request.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';

final class FinSettings
{
    /** key => [label, group, allowed account types] */
    public const MAPPINGS = [
        'bank_account_id'              => ['Default bank account', 'Money', ['asset']],
        'ar_account_id'                => ['Receivables (debtors control)', 'Money', ['asset']],
        'ap_account_id'                => ['Payables (creditors control)', 'Money', ['liability']],
        'sales_account_id'             => ['Sales', 'Trading', ['revenue']],
        'cogs_account_id'              => ['Cost of sales', 'Trading', ['expense']],
        'inventory_account_id'         => ['Inventory', 'Trading', ['asset']],
        'expense_account_id'           => ['Default expense (bills without an account)', 'Trading', ['expense']],
        'vat_output_account_id'        => ['VAT output', 'VAT', ['liability']],
        'vat_input_account_id'         => ['VAT input', 'VAT', ['liability', 'asset']],
        'vat_control_account_id'       => ['VAT control (owed to / by SARS)', 'VAT', ['liability', 'asset']],
        'bad_debt_account_id'          => ['Bad debts', 'VAT', ['expense']],
        'wage_expense_account_id'      => ['Salaries and wages', 'Payroll', ['expense']],
        'paye_account_id'              => ['PAYE owed', 'Payroll', ['liability']],
        'uif_account_id'               => ['UIF owed', 'Payroll', ['liability']],
        'sdl_account_id'               => ['SDL owed', 'Payroll', ['liability']],
        'uif_expense_account_id'       => ['UIF (employer cost)', 'Payroll', ['expense']],
        'sdl_expense_account_id'       => ['SDL (employer cost)', 'Payroll', ['expense']],
        'gain_on_disposal_account_id'  => ['Gain on disposal of assets', 'Assets and year end', ['revenue']],
        'loss_on_disposal_account_id'  => ['Loss on disposal of assets', 'Assets and year end', ['expense']],
        'fx_gain_account_id'           => ['Exchange gains', 'Assets and year end', ['revenue']],
        'fx_loss_account_id'           => ['Exchange losses', 'Assets and year end', ['expense']],
        'retained_earnings_account_id' => ['Retained earnings', 'Assets and year end', ['equity']],
    ];
    private const DISTINCT = ['ar_account_id', 'ap_account_id', 'bank_account_id', 'vat_output_account_id', 'vat_input_account_id', 'vat_control_account_id', 'sales_account_id', 'cogs_account_id', 'inventory_account_id'];
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function load(PDO $db, int $companyId, bool $admin): array
    {
        $cfg = Fin::config($db, $companyId);
        $out = ['company' => $cfg, 'defaults' => Fin::COMPANY_DEFAULTS, 'panels' => Fin::OVERVIEW_PANELS, 'engine' => Fin::engineSettings($db, $companyId), 'months' => self::MONTHS];
        if (!$admin) return $out;
        $st = $db->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ? AND setting_key LIKE 'finance\\_%'");
        $st->execute([$companyId]);
        $saved = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $map = new AccountsMap($db, $companyId);
        $maps = [];
        foreach (self::MAPPINGS as $k => [$label, $group, $types]) {
            $code = '';
            try { $code = $map->code('finance_' . $k); } catch (Throwable $e) { $code = ''; }
            $st = $db->prepare("SELECT account_id, account_name FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1");
            $st->execute([$companyId, $code]);
            $a = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $maps[] = ['key' => $k, 'label' => $label, 'group' => $group, 'types' => $types, 'pinned' => ($saved['finance_' . $k] ?? '') !== '',
                       'account_id' => $a ? (int)$a['account_id'] : null, 'code' => $code, 'name' => $a['account_name'] ?? ''];
        }
        $out['mappings'] = $maps;
        $out['rates'] = self::rates($db, $companyId);
        $out['vat_in_flight'] = (int)self::scalar($db, "SELECT COUNT(*) FROM gl_vat_periods WHERE company_id = ? AND status IN ('prepared','adjusted')", [$companyId]);
        return $out;
    }

    private static function scalar(PDO $db, string $sql, array $args)
    {
        try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchColumn(); } catch (Throwable $e) { return 0; }
    }

    /** Company look, wording, parts, layout, thresholds and rules (any subset). */
    public static function saveCompany(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can change company settings.');
        $D = Fin::COMPANY_DEFAULTS;
        $ch = [];
        if (isset($in['labels']) && is_array($in['labels'])) {
            $l = [];
            foreach ($D['labels'] as $k => $def) {
                if (!array_key_exists($k, $in['labels'])) continue;
                $v = trim(preg_replace('/[\x00-\x1F<>]/u', '', (string)$in['labels'][$k]));
                if (mb_strlen($v) > 40) throw new FinUserError('Keep each word under 40 characters.');
                if ($v !== '' && $v !== $def) $l[$k] = $v;
            }
            $ch['labels'] = $l;
        }
        if (isset($in['modules']) && is_array($in['modules'])) {
            $m = [];
            foreach ($D['modules'] as $k => $def) if (array_key_exists($k, $in['modules'])) $m[$k] = (bool)$in['modules'][$k];
            $ch['modules'] = $m;
        }
        if (isset($in['rules']) && is_array($in['rules'])) {
            $r = [];
            foreach ($D['rules'] as $k => $def) if (array_key_exists($k, $in['rules'])) $r[$k] = (bool)$in['rules'][$k];
            $ch['rules'] = $r;
        }
        if (isset($in['thresholds']) && is_array($in['thresholds'])) {
            $t = $in['thresholds'];
            foreach (['low_cash', 'large_journal'] as $k) if (isset($t[$k]) && (!is_numeric($t[$k]) || (float)$t[$k] < 0)) throw new FinUserError('Amounts are nil or more.');
            $ch['thresholds'] = array_intersect_key($t, $D['thresholds']);
        }
        if (array_key_exists('accent', $in)) {
            $c = Fin::color((string)$in['accent']);
            if ((string)$in['accent'] !== '' && $c === '') throw new FinUserError('Choose a colour.');
            $ch['accent'] = $c;
        }
        if (isset($in['overview']) && is_array($in['overview'])) $ch['overview'] = array_values(array_intersect($in['overview'], array_keys(Fin::OVERVIEW_PANELS)));
        if (isset($in['overview_off']) && is_array($in['overview_off'])) $ch['overview_off'] = array_values(array_intersect($in['overview_off'], array_keys(Fin::OVERVIEW_PANELS)));
        if (!$ch) throw new FinUserError('Nothing to save.');
        Fin::writePrefs($db, $companyId, 0, $ch);
        Fin::audit($db, $companyId, $userId, 'finance_company_settings_saved', 'settings', null, ['parts' => array_keys($ch)]);
        return Fin::config($db, $companyId);
    }

    public static function resetCompany(PDO $db, int $companyId, int $userId, string $role, string $part): array
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can change company settings.');
        $parts = $part === 'all' ? ['labels', 'modules', 'rules', 'thresholds', 'accent', 'overview', 'overview_off'] : array_intersect([$part], ['labels', 'modules', 'rules', 'thresholds', 'accent', 'overview', 'overview_off']);
        if (!$parts) throw new FinUserError('Nothing to reset.');
        Fin::writePrefs($db, $companyId, 0, array_fill_keys($parts, null));
        Fin::audit($db, $companyId, $userId, 'finance_company_settings_reset', 'settings', null, ['parts' => array_values($parts)]);
        return Fin::config($db, $companyId);
    }

    private static function upsert(PDO $db, int $companyId, string $key, ?string $value): void
    {
        $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$companyId, $key, $value ?? '']);
    }

    /** Financial year start, VAT basis, segregation of duties (the old settings page's rules). */
    public static function saveEngine(PDO $db, int $companyId, int $userId, string $role, array $in): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can change these settings.');
        $set = [];
        if (array_key_exists('fiscal_year_start', $in)) {
            if (!in_array($in['fiscal_year_start'], self::MONTHS, true)) throw new FinUserError('Choose the month the financial year starts.');
            $cur = Fin::engineSettings($db, $companyId)['fiscal_year_start'];
            if ($in['fiscal_year_start'] !== $cur) {
                $closed = (int)self::scalar($db, "SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND module = 'year_end' AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL", [$companyId]);
                if ($closed) throw new FinUserError('A year has been closed on the current year start. Changing it would put the next close on a different boundary; ask your accountant first and reopen the closed years if needed.');
            }
            $set['finance_fiscal_year_start'] = $in['fiscal_year_start'];
        }
        if (array_key_exists('vat_basis', $in)) {
            if (!in_array($in['vat_basis'], ['invoice', 'payments'], true)) throw new FinUserError('The VAT basis is invoice or payments.');
            $cur = Fin::engineSettings($db, $companyId)['vat_basis'];
            if ($in['vat_basis'] !== $cur && (int)self::scalar($db, "SELECT COUNT(*) FROM gl_vat_periods WHERE company_id = ? AND status IN ('prepared','adjusted')", [$companyId])) {
                throw new FinUserError('A VAT return is prepared but not filed. File it (or take it back to open) before changing the basis.');
            }
            $set['finance_vat_basis'] = $in['vat_basis'];
        }
        if (array_key_exists('require_sod', $in)) $set['finance_require_sod'] = !empty($in['require_sod']) && $in['require_sod'] !== '0' ? '1' : '0';
        if (!$set) throw new FinUserError('Nothing to save.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $set) {
            foreach ($set as $k => $v) self::upsert($db, $companyId, $k, $v);
            Fin::audit($db, $companyId, $userId, 'finance_settings_saved', 'settings', null, ['updated' => array_keys($set)] + $set);
        });
        Fin::flush();
    }

    /** $in: {mapping_key: account_id|null} */
    public static function saveMappings(PDO $db, int $companyId, int $userId, string $role, array $in): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can change account mappings.');
        $upd = [];
        $chk = $db->prepare("SELECT account_type, is_active FROM gl_accounts WHERE company_id = ? AND account_id = ? LIMIT 1");
        foreach ($in as $k => $v) {
            if (!isset(self::MAPPINGS[$k])) continue;
            if ($v === '' || $v === null || $v === 0 || $v === '0') { $upd[$k] = null; continue; }
            $id = is_int($v) || (is_string($v) && ctype_digit($v)) ? (int)$v : 0;
            if ($id <= 0) throw new FinUserError('Choose an account for ' . self::MAPPINGS[$k][0] . '.');
            $chk->execute([$companyId, $id]);
            $a = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$a) throw new FinUserError('That account for ' . self::MAPPINGS[$k][0] . ' is not on your chart.');
            if (!in_array($a['account_type'], self::MAPPINGS[$k][2], true)) throw new FinUserError(self::MAPPINGS[$k][0] . ' must be ' . implode(' or ', self::MAPPINGS[$k][2]) . ' account.');
            $upd[$k] = $id;
        }
        if (!$upd) throw new FinUserError('Nothing to save.');
        // the roles that must differ, checked against what is saved too
        $map = new AccountsMap($db, $companyId);
        $now = [];
        foreach (self::DISTINCT as $k) {
            if (array_key_exists($k, $upd)) { if ($upd[$k]) $now[$k] = $upd[$k]; continue; }
            // what the engine actually uses for the role (a pinned setting or the chart's standard account)
            try { $code = $map->code('finance_' . $k); } catch (Throwable $e) { $code = ''; }
            $st = $db->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1");
            $st->execute([$companyId, $code]);
            if ($id = (int)$st->fetchColumn()) $now[$k] = $id;
        }
        $seen = [];
        foreach ($now as $k => $id) {
            if (isset($seen[$id])) throw new FinUserError(self::MAPPINGS[$seen[$id]][0] . ' and ' . self::MAPPINGS[$k][0] . ' need different accounts.');
            $seen[$id] = $k;
        }
        Fin::atomic($db, function () use ($db, $companyId, $userId, $upd) {
            foreach ($upd as $k => $id) self::upsert($db, $companyId, 'finance_' . $k, $id === null ? '' : (string)$id);
            Fin::audit($db, $companyId, $userId, 'finance_settings_saved', 'settings', null, ['updated' => array_keys($upd)]);
        });
    }

    public static function rates(PDO $db, int $companyId): array
    {
        try {
            $st = $db->prepare("SELECT currency_code, rate_date, rate_to_zar, source FROM gl_exchange_rates WHERE company_id = ? ORDER BY rate_date DESC, currency_code LIMIT 60");
            $st->execute([$companyId]);
            return array_map(fn($r) => ['currency' => (string)$r['currency_code'], 'date' => $r['rate_date'], 'rate' => (float)$r['rate_to_zar'], 'source' => (string)$r['source']], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function saveRate(PDO $db, int $companyId, int $userId, string $role, array $in): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $cur = strtoupper(trim((string)($in['currency'] ?? '')));
        $lib = dirname(__DIR__, 2) . '/qi/lib/Currencies.php';
        if (is_file($lib)) require_once $lib;
        $valid = class_exists('Currencies') ? (Currencies::isValid($cur) && $cur !== Currencies::BASE) : (bool)preg_match('/^[A-Z]{3}$/', $cur) && $cur !== 'ZAR';
        if (!$valid) throw new FinUserError('Choose a currency (not rand).');
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the date of the rate.');
        $rate = is_numeric($in['rate'] ?? null) ? (float)$in['rate'] : 0;
        if ($rate <= 0 || $rate > 100000) throw new FinUserError('Enter how many rand one unit is worth.');
        $db->prepare("INSERT INTO gl_exchange_rates (company_id, currency_code, rate_date, rate_to_zar, source) VALUES (?, ?, ?, ?, 'manual')
                      ON DUPLICATE KEY UPDATE rate_to_zar = VALUES(rate_to_zar), source = 'manual'")->execute([$companyId, $cur, $date, round($rate, 6)]);
        Fin::audit($db, $companyId, $userId, 'exchange_rate_saved', 'settings', null, ['currency' => $cur, 'date' => $date, 'rate' => $rate]);
    }
}
