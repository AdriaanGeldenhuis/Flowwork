<?php
// /payroll/lib/PayReports.php — what Payroll owes SARS and what it cost.
//
//   emp201()   one month: PAYE, UIF and SDL from runs paid that month, and the
//              7th-of-next-month due date (the last business day before it
//              when the 7th is a weekend)
//   recon()    the EMP501 reconciliation: interim (March – August) or annual
//              (March – February), month by month and per employee with the
//              IRP5 source codes (4102 PAYE, 4141 UIF, 4142 SDL, 4149 total)
//   summary()  per employee for any date range
//   costs()    wage cost by project
// Only finalised runs count (Finalised or In the books): a run being
// prepared can still change.

require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayTax.php';

final class PayReports
{
    private const FINAL = "r.status IN ('locked','posted')";

    public static function emp201(PDO $db, int $companyId, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new PayProblem('Choose a month.');
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $st = $db->prepare("SELECT r.id, r.run_number, r.name, r.pay_date, r.status, COUNT(x.id) people, COALESCE(SUM(x.gross_cents),0) gross, COALESCE(SUM(x.paye_cents),0) paye,
                                   COALESCE(SUM(x.uif_employee_cents),0) uif_ee, COALESCE(SUM(x.uif_employer_cents),0) uif_er, COALESCE(SUM(x.sdl_cents),0) sdl
                              FROM pay_runs r LEFT JOIN pay_run_employees x ON x.run_id = r.id AND x.company_id = r.company_id
                             WHERE r.company_id = ? AND r.pay_date BETWEEN ? AND ? GROUP BY r.id ORDER BY r.pay_date");
        $st->execute([$companyId, $from, $to]);
        $runs = [];
        $tot = ['paye' => 0, 'uif' => 0, 'sdl' => 0, 'gross' => 0, 'people' => 0];
        $pending = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = ['id' => (int)$r['id'], 'number' => $r['run_number'], 'name' => $r['name'], 'pay_date' => $r['pay_date'], 'stage' => PayKit::stage($r['status']),
                    'people' => (int)$r['people'], 'gross' => (int)$r['gross'], 'paye' => (int)$r['paye'], 'uif' => (int)$r['uif_ee'] + (int)$r['uif_er'], 'sdl' => (int)$r['sdl']];
            if (in_array($r['status'], ['locked', 'posted'], true)) {
                $runs[] = $row;
                foreach (['paye', 'uif', 'sdl', 'gross', 'people'] as $k) $tot[$k] += $row[$k];
            } else {
                $pending[] = $row;
            }
        }
        $due = self::dueDate($month);
        return ['month' => $month, 'label' => date('F Y', strtotime($from)), 'runs' => $runs, 'pending' => $pending,
                'totals' => $tot + ['total' => $tot['paye'] + $tot['uif'] + $tot['sdl']], 'due' => $due, 'overdue' => $tot['paye'] + $tot['uif'] + $tot['sdl'] > 0 && $due < date('Y-m-d')];
    }

    /** The 7th of the following month, or the last weekday before it. */
    public static function dueDate(string $month): string
    {
        $d = (new DateTimeImmutable($month . '-01'))->modify('first day of next month')->modify('+6 days');
        return PayKit::businessDay($d)->format('Y-m-d');
    }

    /** $yearStart: 'YYYY-03-01'; $kind: interim (Mar–Aug) or annual (Mar–Feb). */
    public static function recon(PDO $db, int $companyId, string $yearStart, string $kind): array
    {
        if (!preg_match('/^\d{4}-03-01$/', $yearStart)) throw new PayProblem('Choose a tax year.');
        $kind = $kind === 'interim' ? 'interim' : 'annual';
        $from = $yearStart;
        $to = $kind === 'interim' ? substr($yearStart, 0, 4) . '-08-31' : PayTax::taxYearEnd($yearStart);
        $months = [];
        $m = new DateTimeImmutable($from);
        while ($m->format('Y-m-d') <= $to) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'paye' => 0, 'uif' => 0, 'sdl' => 0];
            $m = $m->modify('first day of next month');
        }
        $st = $db->prepare("SELECT DATE_FORMAT(r.pay_date, '%Y-%m') ym, SUM(x.paye_cents) paye, SUM(x.uif_employee_cents + x.uif_employer_cents) uif, SUM(x.sdl_cents) sdl
                              FROM pay_runs r JOIN pay_run_employees x ON x.run_id = r.id AND x.company_id = r.company_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ? GROUP BY ym");
        $st->execute([$companyId, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($months[$r['ym']])) continue;
            $months[$r['ym']]['paye'] = (int)$r['paye'];
            $months[$r['ym']]['uif'] = (int)$r['uif'];
            $months[$r['ym']]['sdl'] = (int)$r['sdl'];
        }
        foreach ($months as &$mm) $mm['total'] = $mm['paye'] + $mm['uif'] + $mm['sdl'];
        unset($mm);

        // Per employee, with amounts by source code from the pay items
        $st = $db->prepare("SELECT e.id, e.employee_no, e.first_name, e.last_name, e.id_number, e.tax_number, e.birth_date, MIN(e.hire_date) hire_date, e.termination_date,
                                   SUM(x.gross_cents) gross, SUM(x.taxable_income_cents) taxable, SUM(x.paye_cents) paye, SUM(x.uif_employee_cents + x.uif_employer_cents) uif,
                                   SUM(x.sdl_cents) sdl, SUM(x.reimbursements_cents) reimb, MIN(r.period_start) first_period, MAX(r.period_end) last_period
                              FROM pay_runs r JOIN pay_run_employees x ON x.run_id = r.id AND x.company_id = r.company_id JOIN employees e ON e.id = x.employee_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ? GROUP BY e.id ORDER BY e.last_name, e.first_name");
        $st->execute([$companyId, $from, $to]);
        $people = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT l.employee_id, COALESCE(NULLIF(pi.paye_code,''), CASE pi.type WHEN 'earning' THEN '3601' WHEN 'benefit' THEN '3801' ELSE '' END) code, SUM(ABS(l.amount_cents)) amt
                              FROM pay_run_lines l JOIN pay_runs r ON r.id = l.run_id AND r.company_id = l.company_id JOIN payitems pi ON pi.id = l.payitem_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ? GROUP BY l.employee_id, code");
        $st->execute([$companyId, $from, $to]);
        $codes = [];
        $byEmp = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['code'] === '') continue;
            $codes[$r['code']] = true;
            $byEmp[(int)$r['employee_id']][$r['code']] = (int)$r['amt'];
        }
        ksort($codes);
        $rows = array_map(fn($p) => [
            'id' => (int)$p['id'], 'no' => $p['employee_no'], 'name' => trim($p['first_name'] . ' ' . $p['last_name']), 'id_number' => (string)$p['id_number'],
            'tax_number' => (string)$p['tax_number'], 'period' => PayKit::date(max($p['first_period'], $p['hire_date'] ?: $p['first_period'])) . ' – ' . PayKit::date($p['termination_date'] && $p['termination_date'] < $p['last_period'] ? $p['termination_date'] : $p['last_period']),
            'gross' => (int)$p['gross'], 'taxable' => (int)$p['taxable'], 'paye' => (int)$p['paye'], 'uif' => (int)$p['uif'], 'sdl' => (int)$p['sdl'],
            'codes' => $byEmp[(int)$p['id']] ?? [], 'missing_tax_number' => trim((string)$p['tax_number']) === '',
        ], $people);
        $tot = ['paye' => array_sum(array_column($months, 'paye')), 'uif' => array_sum(array_column($months, 'uif')), 'sdl' => array_sum(array_column($months, 'sdl'))];
        return ['kind' => $kind, 'from' => $from, 'to' => $to, 'label' => ($kind === 'interim' ? 'Interim (Mar – Aug) ' : 'Annual (Mar – Feb) ') . PayTax::taxYearLabel($from),
                'months' => array_values($months), 'people' => $rows, 'codes' => array_keys($codes), 'totals' => $tot + ['total' => array_sum($tot)]];
    }

    public static function summary(PDO $db, int $companyId, string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        $st = $db->prepare("SELECT e.id, e.employee_no, e.first_name, e.last_name, COUNT(x.id) runs, SUM(x.gross_cents) gross, SUM(x.paye_cents) paye, SUM(x.uif_employee_cents) uif,
                                   SUM(x.other_deductions_cents) ded, SUM(x.reimbursements_cents) reimb, SUM(x.net_cents) net, SUM(x.employer_cost_cents) cost
                              FROM pay_runs r JOIN pay_run_employees x ON x.run_id = r.id AND x.company_id = r.company_id JOIN employees e ON e.id = x.employee_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ? GROUP BY e.id ORDER BY e.first_name, e.last_name");
        $st->execute([$companyId, $from, $to]);
        $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'no' => $r['employee_no'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'runs' => (int)$r['runs'],
                                     'gross' => (int)$r['gross'], 'paye' => (int)$r['paye'], 'uif' => (int)$r['uif'], 'deductions' => (int)$r['ded'], 'reimb' => (int)$r['reimb'],
                                     'net' => (int)$r['net'], 'cost' => (int)$r['cost']], $st->fetchAll(PDO::FETCH_ASSOC));
        $tot = [];
        foreach (['gross', 'paye', 'uif', 'deductions', 'reimb', 'net', 'cost'] as $k) $tot[$k] = array_sum(array_column($rows, $k));
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $tot];
    }

    public static function costs(PDO $db, int $companyId, string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        // Earnings lines by project; the employer's UIF and SDL follow each person's earnings in proportion.
        $st = $db->prepare("SELECT l.project_id, p.name, p.project_number, l.employee_id, l.run_id, SUM(l.amount_cents) amt
                              FROM pay_run_lines l JOIN pay_runs r ON r.id = l.run_id AND r.company_id = l.company_id JOIN payitems pi ON pi.id = l.payitem_id
                              LEFT JOIN projects p ON p.project_id = l.project_id AND p.company_id = l.company_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ? AND pi.type = 'earning'
                             GROUP BY l.project_id, l.employee_id, l.run_id");
        $st->execute([$companyId, $from, $to]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT x.run_id, x.employee_id, x.gross_cents, x.uif_employer_cents + x.sdl_cents extra FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id
                             WHERE r.company_id = ? AND " . self::FINAL . " AND r.pay_date BETWEEN ? AND ?");
        $st->execute([$companyId, $from, $to]);
        $extra = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $extra[$r['run_id'] . ':' . $r['employee_id']] = [(int)$r['gross_cents'], (int)$r['extra']];
        $out = [];
        foreach ($lines as $l) {
            $key = $l['project_id'] ? (int)$l['project_id'] : 0;
            if (!isset($out[$key])) $out[$key] = ['project_id' => $key ?: null, 'name' => $key ? trim(($l['project_number'] ? $l['project_number'] . ' · ' : '') . ($l['name'] ?: 'Project #' . $key)) : 'Not linked to a project', 'wages' => 0, 'levies' => 0];
            $out[$key]['wages'] += (int)$l['amt'];
            [$g, $x] = $extra[$l['run_id'] . ':' . $l['employee_id']] ?? [0, 0];
            if ($g > 0) $out[$key]['levies'] += (int)round($x * (int)$l['amt'] / $g);
        }
        foreach ($out as &$o) $o['total'] = $o['wages'] + $o['levies'];
        unset($o);
        usort($out, fn($a, $b) => ($a['project_id'] === null) <=> ($b['project_id'] === null) ?: $b['total'] <=> $a['total']);
        return ['from' => $from, 'to' => $to, 'rows' => $out, 'totals' => ['wages' => array_sum(array_column($out, 'wages')), 'levies' => array_sum(array_column($out, 'levies')), 'total' => array_sum(array_column($out, 'total'))]];
    }

    private static function range(string $from, string $to): array
    {
        if (!PayEmployees::isDate($from) || !PayEmployees::isDate($to)) throw new PayProblem('Choose the dates.');
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to];
    }
}
