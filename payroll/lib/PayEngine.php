<?php
// /payroll/lib/PayEngine.php — works out one person's pay for one pay run.
//
//   calculate()  is pure: employee + run + settings + tax table + pay items
//                + regular items + this run's adjustments in, payslip out.
//   for()        loads those inputs from the database and calls calculate().
//
// Pay item types and what they do:
//   earning        paid in cash; taxable / UIF / SDL as the item says;
//                  "once-off" items (bonus, annual payment) are taxed at the
//                  margin instead of being annualised
//   benefit        a taxable fringe benefit — counted for tax, never paid out
//   reimbursement  paid back in cash; not income, so no tax, UIF or SDL
//   deduction      taken off net pay; "before tax" items (pension, provident,
//                  retirement annuity) also reduce taxable income, up to the
//                  27.5% / R350 000 limit
//   contribution   paid by the employer on top of pay (cost to company);
//                  taxable as a fringe benefit when the item says so
//
// Amounts on lines are positive, except deductions, which are negative.

require_once __DIR__ . '/PayTax.php';

final class PayProblem extends RuntimeException
{
}

final class PayEngine
{
    public const BASIC = 'BASIC_SALARY';
    public const OVERTIME = 'OVERTIME';

    /** The tax table for a date: the company's own, else Flowwork's, else the built-in official one. */
    public static function table(PDO $db, int $companyId, string $date): array
    {
        $row = null;
        try {
            $st = $db->prepare("SELECT * FROM tax_tables_za
                                 WHERE (company_id = ? OR company_id IS NULL) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                                 ORDER BY company_id IS NULL, effective_from DESC LIMIT 1");
            $st->execute([$companyId, $date, $date]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log('[payroll] tax table: ' . $e->getMessage());
        }
        if ($row) {
            $brackets = array_map(fn($b) => [(int)$b['threshold_cents'], (int)$b['rate_bp']], json_decode((string)$row['bracket_json'], true) ?: []);
            if ($brackets) {
                return [
                    'source' => $row['company_id'] === null ? 'flowwork' : 'company', 'id' => (int)$row['id'],
                    'from' => $row['effective_from'], 'to' => $row['effective_to'], 'label' => PayTax::taxYearLabel($row['effective_from']),
                    'brackets' => $brackets,
                    'rebates' => [(int)$row['rebate_primary_cents'], (int)$row['rebate_secondary_cents'], (int)$row['rebate_tertiary_cents']],
                    'medical' => [(int)$row['medical_credit_main_cents'], (int)$row['medical_credit_dep_cents']],
                    'uif_ceiling_year' => (int)$row['uif_cap_annual_cents'] ?: PayTax::UIF_CEILING_YEAR_CENTS,
                ];
            }
        }
        $start = PayTax::taxYearStart($date);
        if (isset(PayTax::OFFICIAL[$start])) {
            $o = PayTax::OFFICIAL[$start];
            return ['source' => 'built-in', 'id' => null, 'from' => $start, 'to' => $o['to'], 'label' => PayTax::taxYearLabel($start),
                    'brackets' => $o['brackets'], 'rebates' => $o['rebates'], 'medical' => $o['medical'], 'uif_ceiling_year' => PayTax::UIF_CEILING_YEAR_CENTS];
        }
        throw new PayProblem('There is no SARS tax table for the ' . PayTax::taxYearLabel($date) . ' tax year yet. Add it under Payroll → Settings → Tax tables before paying anyone in it.');
    }

    /** The pay item with this code, created the first time it is needed. */
    public static function systemItem(PDO $db, int $companyId, string $code): array
    {
        $st = $db->prepare("SELECT * FROM payitems WHERE company_id = ? AND code = ?");
        $st->execute([$companyId, $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $def = [self::BASIC => ['Basic salary', '3601'], self::OVERTIME => ['Overtime', '3601']][$code] ?? null;
        if (!$def) throw new InvalidArgumentException("Unknown system pay item $code");
        $db->prepare("INSERT INTO payitems (company_id, code, name, type, taxable, uif_subject, sdl_subject, paye_code, basis, active, created_at)
                      VALUES (?, ?, ?, 'earning', 1, 1, 1, ?, 'fixed', 1, NOW())")->execute([$companyId, $code, $def[0], $def[1]]);
        $st->execute([$companyId, $code]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Monthly salary → the amount for one period of $frequency. */
    public static function periodSalary(int $monthlyCents, string $frequency): int
    {
        $periods = PayTax::PERIODS[$frequency] ?? 12;
        return (int)round($monthlyCents * 12 / $periods);
    }

    /** Hourly rate for overtime: the employee's own, else salary ÷ (hours a week × 52 ÷ 12). */
    public static function hourlyRate(array $emp, float $hoursPerWeek): int
    {
        if ((int)$emp['hourly_rate_cents'] > 0) return (int)$emp['hourly_rate_cents'];
        $hours = max(1.0, $hoursPerWeek) * 52 / 12;
        return (int)round((int)$emp['base_salary_cents'] / $hours);
    }

    /** Days the person was employed in the period, and the period's length. */
    public static function daysEmployed(array $emp, array $run): array
    {
        $ps = new DateTimeImmutable($run['period_start']);
        $pe = new DateTimeImmutable($run['period_end']);
        $len = $ps->diff($pe)->days + 1;
        $from = max($ps, new DateTimeImmutable($emp['hire_date'] ?: $run['period_start']));
        $to = !empty($emp['termination_date']) ? min($pe, new DateTimeImmutable($emp['termination_date'])) : $pe;
        $days = $to < $from ? 0 : $from->diff($to)->days + 1;
        return [$days, $len];
    }

    /**
     * One person's pay for one run.
     *
     * @param array $emp        employees row
     * @param array $run        pay_runs row (frequency, period_start, period_end, pay_date)
     * @param array $settings   ['sdl_liable' => bool]
     * @param array $table      from table()
     * @param array $items      payitems rows by id
     * @param array $basic      the BASIC_SALARY payitems row
     * @param array $recurring  employee_payitems rows active in the period
     * @param array $adjust     this run's adjustment lines (pay_run_lines with is_adhoc = 1)
     */
    public static function calculate(array $emp, array $run, array $settings, array $table, array $items, array $basic, array $recurring, array $adjust): array
    {
        $freq = $run['frequency'];
        $lines = [];
        $warnings = [];

        // Basic salary — salaried people only; hourly people are paid for the hours entered on the run.
        $monthly = (int)$emp['base_salary_cents'];
        $basicAmount = 0;
        if ($monthly > 0) {
            $full = self::periodSalary($monthly, $freq);
            [$days, $len] = self::daysEmployed($emp, $run);
            $basicAmount = $days >= $len ? $full : (int)round($full * $days / max(1, $len));
            $lines[] = ['payitem_id' => (int)$basic['id'], 'description' => $days >= $len ? 'Basic salary' : "Basic salary ($days of $len days)",
                        'qty' => 1, 'rate_cents' => $full, 'amount_cents' => $basicAmount, 'is_adhoc' => 0, 'adhoc_reason' => null,
                        'project_id' => $emp['default_project_id'] ?? null];
            if ($days === 0) $warnings[] = 'not_employed';
        } elseif ((int)$emp['hourly_rate_cents'] > 0) {
            $hasHours = false;
            foreach ($adjust as $a) if ((int)$a['payitem_id'] === (int)$basic['id'] && (float)$a['qty'] > 0) $hasHours = true;
            if (!$hasHours) $warnings[] = 'no_hours';
        } else {
            $warnings[] = 'no_pay';
        }

        // Regular items for this person (fixed amount, or a percentage of the basic salary)
        foreach ($recurring as $r) {
            $it = $items[(int)$r['payitem_id']] ?? null;
            if (!$it) continue;
            if (($r['unit'] ?? 'fixed') === 'percent') {
                $pct = (int)$r['rate_cents']; // hundredths of a percent: 750 = 7.50%
                $amt = (int)round($basicAmount * $pct / 10000);
                $desc = $it['name'] . ' (' . rtrim(rtrim(number_format($pct / 100, 2, '.', ''), '0'), '.') . '%)';
                $qty = 1;
                $rate = $amt;
            } else {
                $amt = (int)$r['amount_cents'];
                $desc = $it['name'];
                $qty = 1;
                $rate = $amt;
            }
            if ($amt === 0) continue;
            $sign = $it['type'] === 'deduction' ? -1 : 1;
            $lines[] = ['payitem_id' => (int)$it['id'], 'description' => $desc, 'qty' => $qty, 'rate_cents' => $sign * $rate, 'amount_cents' => $sign * abs($amt),
                        'is_adhoc' => 0, 'adhoc_reason' => null, 'project_id' => null];
        }

        // This run's adjustments, kept as entered
        foreach ($adjust as $a) {
            $lines[] = ['payitem_id' => (int)$a['payitem_id'], 'description' => $a['description'], 'qty' => (float)$a['qty'], 'rate_cents' => (int)$a['rate_cents'],
                        'amount_cents' => (int)$a['amount_cents'], 'is_adhoc' => 1, 'adhoc_reason' => $a['adhoc_reason'] ?? null, 'project_id' => $a['project_id'] ?? null,
                        'id' => isset($a['id']) ? (int)$a['id'] : null];
        }

        // Sort the money into buckets
        $cash = 0; $benefits = 0; $reimb = 0; $deductions = 0; $retirement = 0; $contrib = 0;
        $taxRegular = 0; $taxOnceOff = 0; $uifPay = 0; $sdlPay = 0;
        foreach ($lines as $l) {
            $it = (int)$l['payitem_id'] === (int)$basic['id'] ? $basic : ($items[(int)$l['payitem_id']] ?? null);
            if (!$it) continue;
            $amt = (int)$l['amount_cents'];
            $abs = abs($amt);
            $taxable = (int)$it['taxable'] === 1;
            switch ($it['type']) {
                case 'earning':
                case 'benefit':
                case 'contribution':
                    if ($it['type'] === 'earning') $cash += $amt;
                    elseif ($it['type'] === 'benefit') $benefits += $amt;
                    else $contrib += $amt;
                    if ($taxable) { if ((int)($it['irregular'] ?? 0) === 1) $taxOnceOff += $amt; else $taxRegular += $amt; }
                    if ($it['type'] !== 'contribution') {
                        if ((int)$it['uif_subject'] === 1) $uifPay += $amt;
                        if ((int)$it['sdl_subject'] === 1) $sdlPay += $amt;
                    }
                    break;
                case 'reimbursement':
                    $reimb += $abs;
                    break;
                case 'deduction':
                    $deductions += $abs;
                    if ((int)($it['pre_tax'] ?? 0) === 1) $retirement += $abs;
                    break;
            }
        }

        // Tax
        $deductible = PayTax::retirementDeductible($freq, $retirement, $taxRegular + $taxOnceOff);
        $regularAfter = max(0, $taxRegular - $deductible);
        $onceOffAfter = $taxOnceOff - max(0, $deductible - $taxRegular);
        $age = PayTax::ageAtYearEnd($emp['birth_date'] ?? null, $run['pay_date']);
        $medicalMembers = (int)($emp['medical_members'] ?? 0);
        $tax = PayTax::paye($table, $freq, $regularAfter, max(0, $onceOffAfter), $age, $medicalMembers);
        $paye = $tax['paye'];

        // UIF and SDL
        $uifEe = $uifEr = 0;
        $uifSteps = null;
        if ((int)$emp['uif_included'] === 1) {
            [$uifEe, $uifEr, $base, $cap] = PayTax::uif($freq, $uifPay, $table['uif_ceiling_year'] ?? null);
            $uifSteps = ['pay' => $uifPay, 'ceiling' => $cap, 'base' => $base];
        }
        $sdl = (!empty($settings['sdl_liable']) && (int)$emp['sdl_included'] === 1) ? PayTax::sdl($sdlPay) : 0;

        $net = $cash + $reimb - $paye - $uifEe - $deductions;
        if ($net < 0) $warnings[] = 'negative_net';
        if ($age === null && $taxRegular + $taxOnceOff > 0) $warnings[] = 'no_birth_date';
        if (trim((string)($emp['tax_number'] ?? '')) === '' && $paye > 0) $warnings[] = 'no_tax_number';
        if (trim((string)($emp['bank_account_no'] ?? '')) === '' && $net > 0) $warnings[] = 'no_bank';

        return [
            'gross_cents' => $cash,
            'taxable_income_cents' => $regularAfter + max(0, $onceOffAfter),
            'paye_cents' => $paye,
            'uif_employee_cents' => $uifEe,
            'uif_employer_cents' => $uifEr,
            'sdl_cents' => $sdl,
            'other_deductions_cents' => $deductions,
            'reimbursements_cents' => $reimb,
            'net_cents' => $net,
            'employer_cost_cents' => $cash + $reimb + $uifEr + $sdl + $contrib,
            'bank_amount_cents' => max(0, $net),
            'lines' => $lines,
            'debug' => [
                'tax_table' => ['label' => $table['label'], 'source' => $table['source']],
                'benefits' => $benefits, 'employer_contributions' => $contrib,
                'retirement' => ['contributions' => $retirement, 'deductible' => $deductible],
                'paye' => $tax['steps'], 'uif' => $uifSteps, 'sdl_pay' => $sdlPay, 'sdl_liable' => !empty($settings['sdl_liable']),
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    /** Load everything calculate() needs for one person and run it. */
    public static function for(PDO $db, int $companyId, array $emp, array $run, array $settings, ?array $table = null, ?array $items = null): array
    {
        $table = $table ?? self::table($db, $companyId, $run['pay_date']);
        $items = $items ?? self::items($db, $companyId);
        $basic = self::systemItem($db, $companyId, self::BASIC);
        $st = $db->prepare("SELECT * FROM employee_payitems WHERE company_id = ? AND employee_id = ? AND active = 1
                               AND (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to >= ?) ORDER BY id");
        $st->execute([$companyId, $emp['id'], $run['period_end'], $run['period_start']]);
        $recurring = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT * FROM pay_run_lines WHERE company_id = ? AND run_id = ? AND employee_id = ? AND is_adhoc = 1 ORDER BY id");
        $st->execute([$companyId, $run['id'], $emp['id']]);
        $adjust = $st->fetchAll(PDO::FETCH_ASSOC);
        return self::calculate($emp, $run, $settings, $table, $items, $basic, $recurring, $adjust);
    }

    /** Every pay item of the company by id (inactive ones too: old lines still point at them). */
    public static function items(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT * FROM payitems WHERE company_id = ?");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }
}
