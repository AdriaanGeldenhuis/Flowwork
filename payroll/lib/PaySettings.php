<?php
// /payroll/lib/PaySettings.php — the company's payroll settings and tax tables.
// The same payroll_settings row is edited by Admin → Payroll (pay schedule and
// posting); both check values the same way.
//
// Tax tables: Flowwork ships the official SARS tables (PayTax::OFFICIAL,
// copied into tax_tables_za with company_id NULL). A company can add its own
// table for a tax year — for a new year that starts before Flowwork is
// updated — and it wins over Flowwork's for that year. Only limits and rates
// are entered; the base amounts are worked out.

require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayTax.php';
require_once __DIR__ . '/PayEmployees.php';
require_once __DIR__ . '/PayItems.php';

final class PaySettings
{
    public static function load(PDO $db, int $companyId): array
    {
        $s = PayKit::settings($db, $companyId);
        $s['bank_accounts'] = self::bankAccounts($db, $companyId);
        $s['posts_to'] = self::postsTo($db, $companyId);
        return $s;
    }

    /** Banking → Accounts entries linked to the chart: where net pay leaves from. */
    public static function bankAccounts(PDO $db, int $companyId): array
    {
        try {
            $st = $db->prepare("SELECT ba.id, ba.name, ba.bank_name, ba.account_no, ga.account_code FROM gl_bank_accounts ba
                                  JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                                 WHERE ba.company_id = ? AND ba.is_active = 1 ORDER BY ba.name");
            $st->execute([$companyId]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => trim($r['name'] . ($r['account_no'] ? ' ··' . substr(preg_replace('/\D/', '', $r['account_no']), -4) : '')), 'code' => $r['account_code']],
                             $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return [];
        }
    }

    /** The accounts a pay run posts to, as Finance resolves them. */
    public static function postsTo(PDO $db, int $companyId): array
    {
        $out = [];
        try {
            require_once dirname(__DIR__, 2) . '/finances/lib/AccountsMap.php';
            require_once dirname(__DIR__, 2) . '/finances/lib/FinanceBootstrap.php';
            $map = new AccountsMap($db, $companyId);
            $names = PayItems::chartCodes($db, $companyId);
            foreach (['finance_wage_expense_account_id' => 'Salaries & wages', 'finance_paye_account_id' => 'PAYE owed', 'finance_uif_account_id' => 'UIF owed',
                      'finance_sdl_account_id' => 'SDL owed', 'finance_uif_expense_account_id' => 'Employer UIF', 'finance_sdl_expense_account_id' => 'Employer SDL'] as $k => $label) {
                $code = $map->code($k);
                $out[] = ['label' => $label, 'code' => $code, 'name' => $names[$code] ?? ''];
            }
        } catch (Throwable $e) {
            error_log('[payroll] posts to: ' . $e->getMessage());
        }
        return $out;
    }

    public static function save(PDO $db, int $companyId, int $userId, array $in): array
    {
        $cur = PayKit::settings($db, $companyId);
        $errors = [];
        $set = [];
        $words = [];
        $num = function (string $k, float $min, float $max, string $msg) use ($in, &$errors) {
            $v = str_replace(',', '.', trim((string)($in[$k] ?? '')));
            if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) { $errors[$k] = $msg; return null; }
            return (float)$v;
        };
        if (array_key_exists('default_frequency', $in)) {
            if (!isset(PayKit::FREQ[$in['default_frequency']])) $errors['default_frequency'] = 'Choose a pay frequency.';
            else $set['default_frequency'] = $in['default_frequency'];
        }
        if (array_key_exists('monthly_anchor_day', $in) && ($v = $num('monthly_anchor_day', 1, 31, 'A day from 1 to 31.')) !== null) $set['monthly_anchor_day'] = (int)$v;
        if (array_key_exists('weekly_anchor_day', $in)) {
            $v = (int)$in['weekly_anchor_day'];
            if (!isset(PayKit::WEEKDAYS[$v])) $errors['weekly_anchor_day'] = 'Choose a weekday.';
            else $set['weekly_anchor_day'] = $v;
        }
        if (array_key_exists('hours_per_week', $in) && ($v = $num('hours_per_week', 1, 80, 'Between 1 and 80 hours.')) !== null) $set['hours_per_week'] = round($v, 1);
        if (array_key_exists('variance_pct', $in) && ($v = $num('variance_pct', 0, 100, 'Between 0 and 100%.')) !== null) $set['require_approval_for_variance_pct'] = round($v, 2);
        foreach (['sdl_liable', 'auto_post_to_finance'] as $k) if (array_key_exists($k, $in)) $set[$k] = !empty($in[$k]) && $in[$k] !== '0' ? 1 : 0;
        if (array_key_exists('paye_reference', $in)) {
            $ref = preg_replace('/\D/', '', (string)$in['paye_reference']);
            if ($ref !== '' && !preg_match('/^7\d{9}$/', $ref)) $errors['paye_reference'] = 'A PAYE reference number has 10 digits and starts with 7.';
            else $set['paye_reference'] = $ref ?: null;
        }
        if (array_key_exists('bank_account_id', $in)) {
            $b = (int)$in['bank_account_id'];
            if ($b && !in_array($b, array_column(self::bankAccounts($db, $companyId), 'id'), true)) $errors['bank_account_id'] = 'Choose one of your bank accounts.';
            else $set['default_bank_account_id'] = $b ?: null;
        }
        $ot = $cur['overtime'];
        $otChanged = false;
        foreach (['ot_weekday' => 'weekday', 'ot_sunday' => 'sunday', 'ot_holiday' => 'holiday'] as $k => $key) {
            if (!array_key_exists($k, $in)) continue;
            if (($v = $num($k, 1, 3, 'Between 1× and 3×.')) !== null) { $ot[$key] = round($v, 2); $otChanged = true; }
        }
        if ($otChanged) $set['overtime_rules_json'] = json_encode($ot);
        if ($errors) throw new PayInvalid($errors);
        if (!$set) return self::load($db, $companyId);

        $row = $db->prepare("SELECT * FROM payroll_settings WHERE company_id = ?");
        $row->execute([$companyId]);
        $old = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($set as $k => $v) if ((string)($old[$k] ?? '') !== (string)$v) $words[] = $k;
        $db->prepare("UPDATE payroll_settings SET " . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set))) . ", updated_at = NOW() WHERE company_id = ?")
           ->execute(array_merge(array_values($set), [$companyId]));
        if ($words) PayKit::audit($db, $companyId, $userId, 'payroll_settings_updated', ['changed' => implode(', ', $words)]);
        return self::load($db, $companyId);
    }

    // ---------------------------------------------------------------- tax tables

    /** Every tax year Payroll knows: Flowwork's table, the company's own, and which one is used. */
    public static function taxTables(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT * FROM tax_tables_za WHERE company_id IS NULL OR company_id = ? ORDER BY effective_from DESC, company_id IS NULL");
        $st->execute([$companyId]);
        $years = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $from = PayTax::taxYearStart($r['effective_from']);
            $t = self::shape($r);
            $years[$from] = $years[$from] ?? ['from' => $from, 'to' => PayTax::taxYearEnd($from), 'label' => PayTax::taxYearLabel($from), 'flowwork' => null, 'company' => null];
            $years[$from][$r['company_id'] === null ? 'flowwork' : 'company'] = $t;
        }
        foreach (PayTax::OFFICIAL as $from => $o) {
            if (isset($years[$from])) continue;
            $years[$from] = ['from' => $from, 'to' => $o['to'], 'label' => PayTax::taxYearLabel($from), 'company' => null,
                             'flowwork' => self::shape(['brackets' => $o['brackets'], 'rebates' => $o['rebates'], 'medical' => $o['medical'], 'uif' => PayTax::UIF_CEILING_YEAR_CENTS])];
        }
        krsort($years);
        $current = PayTax::taxYearStart(date('Y-m-d'));
        $next = (string)((int)substr($current, 0, 4) + 1) . '-03-01';
        foreach ($years as &$y) {
            $y['in_use'] = $y['company'] ? 'company' : 'flowwork';
            $y['current'] = $y['from'] === $current;
        }
        unset($y);
        return ['years' => array_values($years), 'current' => $current, 'next' => $next, 'next_label' => PayTax::taxYearLabel($next), 'has_next' => isset($years[$next])];
    }

    private static function shape(array $r): array
    {
        if (isset($r['bracket_json'])) {
            $b = array_map(fn($x) => [(int)$x['threshold_cents'], (int)$x['rate_bp']], json_decode((string)$r['bracket_json'], true) ?: []);
            $reb = [(int)$r['rebate_primary_cents'], (int)$r['rebate_secondary_cents'], (int)$r['rebate_tertiary_cents']];
            $med = [(int)$r['medical_credit_main_cents'], (int)$r['medical_credit_dep_cents']];
            $uif = (int)$r['uif_cap_annual_cents'];
        } else {
            [$b, $reb, $med, $uif] = [$r['brackets'], $r['rebates'], $r['medical'], $r['uif']];
        }
        $th = fn(int $rebate) => $b ? (int)round($rebate * 10000 / max(1, $b[0][1])) : 0;
        return [
            'brackets' => array_map(fn($x) => ['from' => $x[0], 'rate' => $x[1] / 100, 'base' => $x[2]], PayTax::withBases($b)),
            'rebates' => $reb, 'medical' => $med, 'uif_month' => (int)round($uif / 12),
            'thresholds' => [$th($reb[0]), $th($reb[0] + $reb[1]), $th($reb[0] + $reb[1] + $reb[2])],
        ];
    }

    /** Save the company's own table for a tax year (rand amounts and percentages as typed). */
    public static function saveTaxTable(PDO $db, int $companyId, int $userId, array $in): void
    {
        $from = (string)($in['from'] ?? '');
        $errors = [];
        if (!preg_match('/^\d{4}-03-01$/', $from)) throw new PayProblem('Choose the tax year.');
        $rows = is_array($in['brackets'] ?? null) ? $in['brackets'] : [];
        $brackets = [];
        foreach ($rows as $i => $r) {
            $f = PayKit::cents($r['from'] ?? '');
            $rate = str_replace(',', '.', trim((string)($r['rate'] ?? '')));
            if ($f === null || $f < 0 || !is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100) { $errors['brackets'] = 'Row ' . ($i + 1) . ': enter the income it starts above and a rate from 0 to 100%.'; break; }
            $brackets[] = [$f, (int)round((float)$rate * 100)];
        }
        if (!$errors) {
            if (count($brackets) < 2 || count($brackets) > 12) $errors['brackets'] = 'Enter between 2 and 12 brackets.';
            elseif ($brackets[0][0] !== 0) $errors['brackets'] = 'The first bracket starts at R0.';
            else for ($i = 1; $i < count($brackets); $i++) if ($brackets[$i][0] <= $brackets[$i - 1][0]) { $errors['brackets'] = 'Each bracket must start above the one before it.'; break; }
        }
        $money = function (string $k, string $msg) use ($in, &$errors) {
            $v = PayKit::cents($in[$k] ?? '');
            if ($v === null || $v < 0) { $errors[$k] = $msg; return 0; }
            return $v;
        };
        $reb = [$money('rebate_primary', 'Enter the primary rebate.'), $money('rebate_secondary', 'Enter the secondary rebate (65 and older).'), $money('rebate_tertiary', 'Enter the tertiary rebate (75 and older).')];
        $med = [$money('medical_main', 'Enter the medical credit for the main member.'), $money('medical_extra', 'Enter the medical credit for each additional dependant.')];
        $uifMonth = $money('uif_month', 'Enter the UIF ceiling a month.');
        if ($uifMonth === 0 && !isset($errors['uif_month'])) $errors['uif_month'] = 'Enter the UIF ceiling a month.';
        if ($errors) throw new PayInvalid($errors);
        $json = json_encode(array_map(fn($b) => ['threshold_cents' => $b[0], 'rate_bp' => $b[1], 'cumulative_cents' => $b[2]], PayTax::withBases($brackets)));
        $to = PayTax::taxYearEnd($from);
        $st = $db->prepare("SELECT id FROM tax_tables_za WHERE company_id = ? AND effective_from = ?");
        $st->execute([$companyId, $from]);
        $vals = [$to, $json, $reb[0], $reb[1], $reb[2], $uifMonth * 12, $med[0], $med[1]];
        if ($id = $st->fetchColumn()) {
            $db->prepare("UPDATE tax_tables_za SET effective_to = ?, bracket_json = ?, rebate_primary_cents = ?, rebate_secondary_cents = ?, rebate_tertiary_cents = ?, uif_cap_annual_cents = ?,
                                 medical_credit_main_cents = ?, medical_credit_dep_cents = ? WHERE id = ?")->execute(array_merge($vals, [$id]));
        } else {
            $db->prepare("INSERT INTO tax_tables_za (effective_to, bracket_json, rebate_primary_cents, rebate_secondary_cents, rebate_tertiary_cents, uif_cap_annual_cents, medical_credit_main_cents,
                                 medical_credit_dep_cents, company_id, effective_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_merge($vals, [$companyId, $from]));
        }
        PayKit::audit($db, $companyId, $userId, 'tax_table_saved', ['tax year' => PayTax::taxYearLabel($from)]);
    }

    public static function deleteTaxTable(PDO $db, int $companyId, int $userId, string $from): void
    {
        $st = $db->prepare("DELETE FROM tax_tables_za WHERE company_id = ? AND effective_from = ?");
        $st->execute([$companyId, $from]);
        if (!$st->rowCount()) throw new PayProblem('There is no table of your own for that year.');
        PayKit::audit($db, $companyId, $userId, 'tax_table_removed', ['tax year' => PayTax::taxYearLabel($from)]);
    }
}
