<?php
// /payroll/lib/PayRuns.php — a pay run from start to the books.
//
//   Being prepared (draft / calculated / review)
//       people are added and worked out; hours, overtime, bonuses and
//       once-off deductions are added per person; anything can change
//   Approved (approved)
//       figures frozen and checked; can be sent back to preparation
//   Finalised (locked)
//       payslips created (and emailed when asked); bank file available;
//       an admin can reopen it while it is not yet in the books
//   In the books (posted)
//       the wages journal is in Finance; nothing changes any more
//
// Every change to a run happens in one transaction that first locks the
// run's row, so two people working on the same run never overwrite each
// other's figures.

require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayEngine.php';
require_once __DIR__ . '/PayEmployees.php';
require_once __DIR__ . '/PayPayslips.php';

final class PayRuns
{
    public const OPEN = ['draft', 'calculated', 'review'];

    // ------------------------------------------------------------------ reads

    public static function list(PDO $db, int $companyId, array $f = []): array
    {
        $where = ['r.company_id = ?'];
        $args = [$companyId];
        $stage = PayKit::key($f['stage'] ?? 'all');
        if ($stage === 'open') $where[] = "r.status IN ('draft','calculated','review','approved')";
        elseif ($stage === 'final') $where[] = "r.status = 'locked'";
        elseif ($stage === 'booked') $where[] = "r.status = 'posted'";
        if (isset(PayKit::FREQ[PayKit::key($f['frequency'] ?? '')])) { $where[] = 'r.frequency = ?'; $args[] = $f['frequency']; }
        $st = $db->prepare("SELECT r.*, COUNT(x.id) people, COALESCE(SUM(x.gross_cents),0) gross, COALESCE(SUM(x.paye_cents),0) paye,
                                   COALESCE(SUM(x.uif_employee_cents + x.uif_employer_cents),0) uif, COALESCE(SUM(x.sdl_cents),0) sdl,
                                   COALESCE(SUM(x.net_cents),0) net, COALESCE(SUM(x.employer_cost_cents),0) cost
                              FROM pay_runs r LEFT JOIN pay_run_employees x ON x.run_id = r.id AND x.company_id = r.company_id
                             WHERE " . implode(' AND ', $where) . " GROUP BY r.id ORDER BY r.pay_date DESC, r.id DESC LIMIT 300");
        $st->execute($args);
        $rows = array_map(fn($r) => self::shapeRun($r) + ['people' => (int)$r['people'], 'totals' => [
            'gross' => (int)$r['gross'], 'paye' => (int)$r['paye'], 'uif' => (int)$r['uif'], 'sdl' => (int)$r['sdl'], 'net' => (int)$r['net'], 'cost' => (int)$r['cost']]],
            $st->fetchAll(PDO::FETCH_ASSOC));
        $c = $db->prepare("SELECT SUM(status IN ('draft','calculated','review','approved')), SUM(status = 'locked'), SUM(status = 'posted'), COUNT(*) FROM pay_runs WHERE company_id = ?");
        $c->execute([$companyId]);
        [$o, $fi, $b, $a] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
        return ['rows' => $rows, 'counts' => ['open' => $o, 'final' => $fi, 'booked' => $b, 'all' => $a]];
    }

    public static function shapeRun(array $r): array
    {
        $stage = PayKit::stage($r['status']);
        return [
            'id' => (int)$r['id'], 'number' => (string)$r['run_number'], 'name' => (string)$r['name'], 'frequency' => (string)$r['frequency'],
            'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'pay_date' => $r['pay_date'],
            'period' => PayKit::period($r['period_start'], $r['period_end']), 'status' => (string)$r['status'], 'stage' => $stage,
            'stage_label' => PayKit::STAGE_LABEL[$stage], 'notes' => (string)($r['notes'] ?? ''), 'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null,
            'approved_at' => $r['approved_at'], 'locked_at' => $r['locked_at'], 'posted_at' => $r['posted_at'],
        ];
    }

    /** The run with everyone in it, totals and what still needs attention. */
    public static function get(PDO $db, int $companyId, int $id): array
    {
        $run = self::row($db, $companyId, $id);
        $out = self::shapeRun($run);
        $st = $db->prepare("SELECT x.*, e.employee_no, e.first_name, e.last_name, e.job_title, e.email, e.base_salary_cents, e.hourly_rate_cents, e.termination_date, e.hire_date
                              FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                             WHERE x.run_id = ? AND x.company_id = ? ORDER BY e.first_name, e.last_name");
        $st->execute([$id, $companyId]);
        $people = [];
        $tot = ['gross' => 0, 'paye' => 0, 'uif_ee' => 0, 'uif_er' => 0, 'sdl' => 0, 'deductions' => 0, 'reimb' => 0, 'net' => 0, 'cost' => 0, 'bank' => 0];
        $settings = PayKit::settings($db, $companyId);
        $adj = $db->prepare("SELECT employee_id, COUNT(*) FROM pay_run_lines WHERE run_id = ? AND company_id = ? AND is_adhoc = 1 GROUP BY employee_id");
        $adj->execute([$id, $companyId]);
        $adjCounts = $adj->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $debug = json_decode((string)$p['calc_debug_json'], true) ?: [];
            $var = $p['variance_vs_last_cents'] !== null ? (int)$p['variance_vs_last_cents'] : null;
            $prevNet = $var !== null ? (int)$p['net_cents'] - $var : null;
            $bigChange = $var !== null && $prevNet > 0 && abs($var) * 100 / $prevNet >= $settings['variance_pct'];
            $people[] = [
                'employee_id' => (int)$p['employee_id'], 'no' => $p['employee_no'], 'name' => trim($p['first_name'] . ' ' . $p['last_name']), 'job_title' => (string)$p['job_title'],
                'hourly' => (int)$p['base_salary_cents'] === 0 && (int)$p['hourly_rate_cents'] > 0, 'email' => (string)$p['email'],
                'gross' => (int)$p['gross_cents'], 'paye' => (int)$p['paye_cents'], 'uif' => (int)$p['uif_employee_cents'], 'deductions' => (int)$p['other_deductions_cents'],
                'reimb' => (int)$p['reimbursements_cents'], 'net' => (int)$p['net_cents'], 'cost' => (int)$p['employer_cost_cents'],
                'variance' => $var, 'big_change' => $bigChange, 'adjustments' => (int)($adjCounts[$p['employee_id']] ?? 0),
                'warnings' => $debug['warnings'] ?? [], 'leaving' => !empty($p['termination_date']) && $p['termination_date'] <= $run['period_end'],
                'payslip' => !empty($p['payslip_path']), 'emailed_at' => $p['payslip_emailed_at'], 'email_error' => $p['payslip_email_error'],
            ];
            $tot['gross'] += (int)$p['gross_cents']; $tot['paye'] += (int)$p['paye_cents']; $tot['uif_ee'] += (int)$p['uif_employee_cents'];
            $tot['uif_er'] += (int)$p['uif_employer_cents']; $tot['sdl'] += (int)$p['sdl_cents']; $tot['deductions'] += (int)$p['other_deductions_cents'];
            $tot['reimb'] += (int)$p['reimbursements_cents']; $tot['net'] += (int)$p['net_cents']; $tot['cost'] += (int)$p['employer_cost_cents'];
            $tot['bank'] += (int)$p['bank_amount_cents'];
        }
        $out['people'] = $people;
        $out['totals'] = $tot;
        $out['missing'] = in_array($run['status'], self::OPEN, true) ? self::eligible($db, $companyId, $run, true) : [];
        $out['bank_files'] = [];
        try {
            $b = $db->prepare("SELECT created_at, employee_count, total_amount_cents, u.first_name FROM bank_file_exports bf LEFT JOIN users u ON u.id = bf.created_by
                                WHERE bf.company_id = ? AND bf.run_id = ? ORDER BY bf.id DESC LIMIT 5");
            $b->execute([$companyId, $id]);
            $out['bank_files'] = array_map(fn($r) => ['at' => $r['created_at'], 'people' => (int)$r['employee_count'], 'total' => (int)$r['total_amount_cents'], 'by' => (string)$r['first_name']],
                                           $b->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
        }
        $out['tax_year'] = PayTax::taxYearLabel($run['pay_date']);
        return $out;
    }

    /** One person's payslip on this run: every line, the statutory amounts and how the tax was worked out. */
    public static function person(PDO $db, int $companyId, int $runId, int $employeeId): array
    {
        $run = self::row($db, $companyId, $runId);
        $st = $db->prepare("SELECT x.*, e.* , x.id AS rid FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                             WHERE x.run_id = ? AND x.company_id = ? AND x.employee_id = ?");
        $st->execute([$runId, $companyId, $employeeId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new PayProblem('They are not in this pay run.');
        $st = $db->prepare("SELECT l.*, pi.code, pi.name AS item_name, pi.type FROM pay_run_lines l LEFT JOIN payitems pi ON pi.id = l.payitem_id
                             WHERE l.run_id = ? AND l.company_id = ? AND l.employee_id = ? ORDER BY l.is_adhoc, FIELD(pi.type,'earning','benefit','reimbursement','contribution','deduction'), l.id");
        $st->execute([$runId, $companyId, $employeeId]);
        $lines = array_map(fn($l) => ['id' => (int)$l['id'], 'adjustment' => (int)$l['is_adhoc'] === 1, 'type' => (string)($l['type'] ?? 'earning'), 'code' => (string)$l['code'],
                                      'description' => (string)($l['description'] ?: $l['item_name']), 'qty' => (float)$l['qty'], 'rate' => (int)$l['rate_cents'],
                                      'amount' => (int)$l['amount_cents'], 'note' => (string)($l['adhoc_reason'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
        $settings = PayKit::settings($db, $companyId);
        $emp = $p;
        $emp['id'] = $employeeId;
        return [
            'run' => self::shapeRun($run), 'employee' => ['id' => $employeeId, 'no' => $p['employee_no'], 'name' => trim($p['first_name'] . ' ' . $p['last_name']),
                'job_title' => (string)$p['job_title'], 'email' => (string)$p['email'], 'hourly' => (int)$p['base_salary_cents'] === 0 && (int)$p['hourly_rate_cents'] > 0,
                'hourly_rate' => PayEngine::hourlyRate($emp, $settings['hours_per_week']), 'frequency' => $p['pay_frequency']],
            'lines' => $lines,
            'totals' => ['gross' => (int)$p['gross_cents'], 'taxable' => (int)$p['taxable_income_cents'], 'paye' => (int)$p['paye_cents'], 'uif_ee' => (int)$p['uif_employee_cents'],
                         'uif_er' => (int)$p['uif_employer_cents'], 'sdl' => (int)$p['sdl_cents'], 'deductions' => (int)$p['other_deductions_cents'],
                         'reimb' => (int)$p['reimbursements_cents'], 'net' => (int)$p['net_cents'], 'cost' => (int)$p['employer_cost_cents'], 'variance' => $p['variance_vs_last_cents'] !== null ? (int)$p['variance_vs_last_cents'] : null],
            'explain' => json_decode((string)$p['calc_debug_json'], true) ?: [],
            'overtime' => $settings['overtime'],
            'payslip' => ['generated_at' => $p['payslip_generated_at'], 'emailed_at' => $p['payslip_emailed_at'], 'email_error' => $p['payslip_email_error']],
        ];
    }

    // ----------------------------------------------------------------- writes

    /** Start a run: create it, add everyone it should pay and work them out. */
    public static function create(PDO $db, int $companyId, int $userId, array $in): array
    {
        $freq = isset(PayKit::FREQ[PayKit::key($in['frequency'] ?? '')]) ? $in['frequency'] : null;
        $name = mb_substr(trim(PayKit::key($in['name'] ?? '')), 0, 120);
        $start = trim(PayKit::key($in['period_start'] ?? ''));
        $end = trim(PayKit::key($in['period_end'] ?? ''));
        $pay = trim(PayKit::key($in['pay_date'] ?? ''));
        $notes = mb_substr(trim(PayKit::key($in['notes'] ?? '')), 0, 1000);
        $errors = [];
        if (!$freq) $errors['frequency'] = 'Choose how often this run pays.';
        if (!PayEmployees::isDate($start)) $errors['period_start'] = 'Enter the first day of the period.';
        if (!PayEmployees::isDate($end)) $errors['period_end'] = 'Enter the last day of the period.';
        if (!PayEmployees::isDate($pay)) $errors['pay_date'] = 'Enter the pay day.';
        if (!$errors) {
            $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
            if ($end < $start) $errors['period_end'] = 'The period ends before it starts.';
            elseif ($days > 31) $errors['period_end'] = 'A pay period can be at most 31 days.';
            elseif ($freq === 'weekly' && $days !== 7) $errors['period_end'] = 'A weekly period is 7 days (this one is ' . $days . ').';
            elseif ($freq === 'fortnight' && $days !== 14) $errors['period_end'] = 'A two-weekly period is 14 days (this one is ' . $days . ').';
            if ($pay < (new DateTimeImmutable($start))->modify('-7 days')->format('Y-m-d') || $pay > (new DateTimeImmutable($end))->modify('+31 days')->format('Y-m-d')) {
                $errors['pay_date'] = 'The pay day should be close to the period (within a month after it ends).';
            }
        }
        if ($name === '') $name = $freq === 'monthly' && !$errors ? date('F Y', strtotime($start)) : 'Pay run';
        if ($errors) throw new PayInvalid($errors);

        $table = PayEngine::table($db, $companyId, $pay); // fail early when the tax year has no table
        PayKit::settings($db, $companyId); // the row locked below exists

        $db->beginTransaction();
        try {
            // One run is started at a time per company, so two people pressing
            // "Start" together cannot both pass the overlap check.
            $db->prepare("SELECT id FROM payroll_settings WHERE company_id = ? FOR UPDATE")->execute([$companyId]);
            $st = $db->prepare("SELECT run_number, name FROM pay_runs WHERE company_id = ? AND frequency = ? AND period_start <= ? AND period_end >= ? LIMIT 1");
            $st->execute([$companyId, $freq, $end, $start]);
            if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
                throw new PayInvalid(['period_start' => 'Run ' . $o['run_number'] . ' (' . $o['name'] . ') already pays ' . strtolower(PayKit::FREQ[$freq]) . ' staff for these dates. Add bonuses or extra hours to that run instead.']);
            }
            $number = PayKit::nextRunNumber($db, $companyId, $pay);
            $db->prepare("INSERT INTO pay_runs (company_id, run_number, name, frequency, period_start, period_end, pay_date, anchor_ref, status, notes, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, NOW())")
               ->execute([$companyId, $number, $name, $freq, $start, $end, $pay, substr($start, 0, 7) . ' ' . $freq, $notes ?: null, $userId]);
            $runId = (int)$db->lastInsertId();
            $run = self::lock($db, $companyId, $runId);
            $people = self::eligible($db, $companyId, $run, false);
            $settings = PayKit::settings($db, $companyId);
            $items = PayEngine::items($db, $companyId);
            foreach ($people as $emp) self::calcPerson($db, $companyId, $run, $emp, $settings, $table, $items);
            if ($people) $db->prepare("UPDATE pay_runs SET status = 'calculated', updated_at = NOW() WHERE id = ?")->execute([$runId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        PayKit::audit($db, $companyId, $userId, 'payrun_created', ['run' => $number . ' · ' . $name, 'period' => PayKit::period($start, $end), 'people' => count($people)], 'pay_run', $runId);
        return ['id' => $runId, 'people' => count($people)];
    }

    /** Work everyone out again (pay items, salaries or tax tables may have changed). */
    public static function recalc(PDO $db, int $companyId, int $userId, int $runId): int
    {
        return self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId) {
            self::mustBeOpen($run);
            $n = self::recalcAll($db, $companyId, $run);
            return $n;
        });
    }

    /** Add people who belong in the run but are not in it yet (all of them, or the ids given). */
    public static function addPeople(PDO $db, int $companyId, int $userId, int $runId, array $ids): int
    {
        $n = self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId, $ids) {
            self::mustBeOpen($run);
            $ids = array_map('intval', $ids);
            $people = array_filter(self::eligible($db, $companyId, $run, true), fn($e) => !$ids || in_array((int)$e['id'], $ids, true));
            if (!$people) throw new PayProblem('Everyone who should be paid in this run is already in it.');
            $settings = PayKit::settings($db, $companyId);
            $table = PayEngine::table($db, $companyId, $run['pay_date']);
            $items = PayEngine::items($db, $companyId);
            foreach ($people as $emp) self::calcPerson($db, $companyId, $run, $emp, $settings, $table, $items);
            if ($run['status'] === 'draft') $db->prepare("UPDATE pay_runs SET status = 'calculated', updated_at = NOW() WHERE id = ?")->execute([$run['id']]);
            return count($people);
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_people_added', ['run' => self::label($db, $companyId, $runId), 'people' => $n], 'pay_run', $runId);
        return $n;
    }

    public static function removePerson(PDO $db, int $companyId, int $userId, int $runId, int $employeeId): void
    {
        $name = self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId, $employeeId) {
            self::mustBeOpen($run);
            $st = $db->prepare("SELECT CONCAT_WS(' ', e.first_name, e.last_name) FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id WHERE x.run_id = ? AND x.company_id = ? AND x.employee_id = ?");
            $st->execute([$run['id'], $companyId, $employeeId]);
            $name = $st->fetchColumn();
            if ($name === false) throw new PayProblem('They are not in this pay run.');
            $db->prepare("DELETE FROM pay_run_lines WHERE run_id = ? AND company_id = ? AND employee_id = ?")->execute([$run['id'], $companyId, $employeeId]);
            $db->prepare("DELETE FROM pay_run_employees WHERE run_id = ? AND company_id = ? AND employee_id = ?")->execute([$run['id'], $companyId, $employeeId]);
            return $name;
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_person_removed', ['run' => self::label($db, $companyId, $runId), 'employee' => $name], 'pay_run', $runId);
    }

    /**
     * Add something to one person's pay on this run.
     *   kind=hours     hours worked (hourly-paid people): qty hours × their rate
     *   kind=overtime  qty hours × hourly rate × multiplier (weekday / sunday / holiday)
     *   kind=item      any pay item: an amount, or qty × rate
     */
    public static function addAdjustment(PDO $db, int $companyId, int $userId, int $runId, int $employeeId, array $in): array
    {
        $res = self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId, $employeeId, $in) {
            self::mustBeOpen($run);
            $emp = self::member($db, $companyId, $run, $employeeId);
            $settings = PayKit::settings($db, $companyId);
            $kind = PayKit::key($in['kind'] ?? 'item');
            $note = mb_substr(trim(PayKit::key($in['note'] ?? '')), 0, 255) ?: null;
            $errors = [];
            $qty = str_replace(',', '.', trim(PayKit::key($in['qty'] ?? '')));
            if ($kind === 'hours' || $kind === 'overtime') {
                if (!is_numeric($qty) || (float)$qty <= 0 || (float)$qty > 744) $errors['qty'] = 'Enter the number of hours (up to 744).';
                if ($errors) throw new PayInvalid($errors);
                $hours = round((float)$qty, 2);
                if ($kind === 'hours') {
                    $item = PayEngine::systemItem($db, $companyId, PayEngine::BASIC);
                    $rate = (int)$emp['hourly_rate_cents'];
                    if ($rate <= 0) throw new PayInvalid(['qty' => 'They have no hourly rate — set it on their profile first.']);
                    $desc = 'Hours worked';
                } else {
                    $item = PayEngine::systemItem($db, $companyId, PayEngine::OVERTIME);
                    $when = in_array(PayKit::key($in['when'] ?? ''), ['weekday', 'sunday', 'holiday'], true) ? $in['when'] : 'weekday';
                    $mult = $settings['overtime'][$when];
                    $rate = (int)round(PayEngine::hourlyRate($emp, $settings['hours_per_week']) * $mult);
                    $desc = ['weekday' => 'Overtime', 'sunday' => 'Sunday work', 'holiday' => 'Public holiday work'][$when] . ' (' . rtrim(rtrim(number_format($mult, 2, '.', ''), '0'), '.') . '×)';
                }
                $amount = (int)round($hours * $rate);
                $line = [$item['id'], $desc, $hours, $rate, $amount];
            } else {
                $st = $db->prepare("SELECT * FROM payitems WHERE id = ? AND company_id = ? AND active = 1");
                $st->execute([(int)($in['payitem_id'] ?? 0), $companyId]);
                $item = $st->fetch(PDO::FETCH_ASSOC);
                if (!$item) throw new PayInvalid(['payitem_id' => 'Choose what to add.']);
                $desc = mb_substr(trim(PayKit::key($in['description'] ?? '')), 0, 120) ?: $item['name'];
                $useQty = $qty !== '' && is_numeric($qty) && (float)$qty !== 1.0;
                if ($useQty) {
                    if ((float)$qty <= 0 || (float)$qty > 10000) $errors['qty'] = 'Enter a quantity above 0.';
                    $rate = PayKit::cents($in['rate'] ?? '');
                    if ($rate === null || $rate <= 0) $errors['rate'] = 'Enter the rate.';
                    if ($errors) throw new PayInvalid($errors);
                    $hours = round((float)$qty, 3);
                    $amount = (int)round($hours * $rate);
                } else {
                    $amount = PayKit::cents($in['amount'] ?? '');
                    if ($amount === null || $amount === 0) throw new PayInvalid(['amount' => 'Enter the amount.']);
                    if (abs($amount) > 1000000000) throw new PayInvalid(['amount' => 'That amount looks too large — check it.']);
                    $hours = 1;
                    $rate = $amount;
                }
                // Deductions are stored negative; what was typed keeps its sign, so a
                // negative deduction refunds and a negative earning takes money back.
                $sign = $item['type'] === 'deduction' ? -1 : 1;
                $line = [$item['id'], $desc, $hours, $sign * $rate, $sign * $amount];
            }
            $db->prepare("INSERT INTO pay_run_lines (company_id, run_id, employee_id, payitem_id, description, qty, rate_cents, amount_cents, project_id, is_adhoc, adhoc_reason)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
               ->execute([$companyId, $run['id'], $employeeId, $line[0], $line[1], $line[2], $line[3], $line[4], $emp['default_project_id'] ?: null, $note]);
            self::calcPerson($db, $companyId, $run, $emp, $settings);
            return ['name' => $emp['first_name'] . ' ' . $emp['last_name'], 'what' => $line[1], 'amount' => $line[4]];
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_adjusted', ['run' => self::label($db, $companyId, $runId), 'employee' => $res['name'], 'added' => $res['what'] . ' ' . PayKit::money($res['amount'])], 'pay_run', $runId);
        return $res;
    }

    public static function removeAdjustment(PDO $db, int $companyId, int $userId, int $runId, int $lineId): void
    {
        $res = self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId, $lineId) {
            self::mustBeOpen($run);
            $st = $db->prepare("SELECT * FROM pay_run_lines WHERE id = ? AND run_id = ? AND company_id = ? AND is_adhoc = 1");
            $st->execute([$lineId, $run['id'], $companyId]);
            $line = $st->fetch(PDO::FETCH_ASSOC);
            if (!$line) throw new PayProblem('That line is already gone.');
            $db->prepare("DELETE FROM pay_run_lines WHERE id = ?")->execute([$lineId]);
            $emp = self::member($db, $companyId, $run, (int)$line['employee_id']);
            self::calcPerson($db, $companyId, $run, $emp, PayKit::settings($db, $companyId));
            return ['name' => $emp['first_name'] . ' ' . $emp['last_name'], 'what' => $line['description']];
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_adjustment_removed', ['run' => self::label($db, $companyId, $runId), 'employee' => $res['name'], 'removed' => $res['what']], 'pay_run', $runId);
    }

    public static function rename(PDO $db, int $companyId, int $userId, int $runId, string $name, string $notes): void
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') throw new PayInvalid(['name' => 'Give the run a name.']);
        self::atomic($db, $companyId, $runId, function (array $run) use ($db, $name, $notes) {
            if (in_array($run['status'], ['locked', 'posted'], true)) throw new PayProblem('A finalised run keeps its name — it is on the payslips.');
            $db->prepare("UPDATE pay_runs SET name = ?, notes = ?, updated_at = NOW() WHERE id = ?")->execute([$name, mb_substr(trim($notes), 0, 1000) ?: null, $run['id']]);
        });
    }

    /** Freeze the figures after a final recalculation and the checks. */
    public static function approve(PDO $db, int $companyId, int $userId, int $runId): void
    {
        self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId, $userId) {
            self::mustBeOpen($run);
            $n = self::recalcAll($db, $companyId, $run);
            if ($n === 0) throw new PayProblem('There is nobody in this run yet.');
            $problems = self::blockers($db, $companyId, (int)$run['id']);
            if ($problems) throw new PayProblem(implode(' ', $problems));
            self::freezeBankDetails($db, $companyId, (int)$run['id']);
            $db->prepare("UPDATE pay_runs SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$userId, $run['id']]);
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_approved', ['run' => self::label($db, $companyId, $runId)], 'pay_run', $runId);
    }

    /** Back to preparation: from Approved, or (admins) from Finalised while it is not in the books. */
    public static function reopen(PDO $db, int $companyId, int $userId, string $role, int $runId): void
    {
        $from = self::atomic($db, $companyId, $runId, function (array $run) use ($db, $role) {
            if ($run['status'] === 'posted') throw new PayProblem('This run is in the books, so it cannot change. Correct it in the next run.');
            if ($run['status'] === 'locked' && strtolower($role) !== 'admin') throw new PayProblem('Only an admin can reopen a finalised run.');
            if (!in_array($run['status'], ['approved', 'locked'], true)) throw new PayProblem('This run is already being prepared.');
            $db->prepare("UPDATE pay_runs SET status = 'calculated', approved_by = NULL, approved_at = NULL, locked_by = NULL, locked_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$run['id']]);
            if ($run['status'] === 'locked') {
                // The payslips were for figures that may now change: finalising again
                // creates new ones and emails everyone, including those emailed before.
                $db->prepare("UPDATE pay_run_employees SET payslip_path = NULL, payslip_generated_at = NULL, payslip_emailed_at = NULL, payslip_email_error = NULL
                               WHERE run_id = ? AND company_id = ?")->execute([$run['id'], $run['company_id']]);
                foreach (glob(dirname(PayPayslips::path((int)$run['company_id'], (int)$run['id'], 0)) . '/payslip_*.pdf') ?: [] as $f) @unlink($f);
            }
            return $run['status'];
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_reopened', ['run' => self::label($db, $companyId, $runId), 'was' => PayKit::STAGE_LABEL[PayKit::stage($from)]], 'pay_run', $runId);
    }

    /**
     * Finalise: lock the run, create every payslip, email them when asked,
     * and post to the books when the company posts automatically.
     */
    public static function finalise(PDO $db, int $companyId, int $userId, int $runId, bool $email): array
    {
        self::atomic($db, $companyId, $runId, function (array $run) use ($db, $userId) {
            if ($run['status'] !== 'approved') throw new PayProblem($run['status'] === 'locked' || $run['status'] === 'posted' ? 'This run is already finalised.' : 'Approve the run first.');
            $db->prepare("UPDATE pay_runs SET status = 'locked', locked_by = ?, locked_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$userId, $run['id']]);
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_finalised', ['run' => self::label($db, $companyId, $runId)], 'pay_run', $runId);
        $out = ['payslips' => 0, 'emailed' => null, 'posted' => false, 'post_error' => null];
        try {
            $out['payslips'] = PayPayslips::generateAll($db, $companyId, $runId);
        } catch (Throwable $e) {
            error_log('[payroll] payslips for run ' . $runId . ': ' . $e->getMessage());
        }
        if ($email) $out['emailed'] = PayPayslips::emailAll($db, $companyId, $userId, $runId, true);
        if (PayKit::settings($db, $companyId)['auto_post_to_finance']) {
            try {
                self::post($db, $companyId, $userId, $runId);
                $out['posted'] = true;
            } catch (Throwable $e) {
                $out['post_error'] = self::human($e);
            }
        }
        return $out;
    }

    /** Write the wages journal to Finance (PAYE, UIF, SDL, deductions and net pay). */
    public static function post(PDO $db, int $companyId, int $userId, int $runId): void
    {
        require_once dirname(__DIR__, 2) . '/finances/lib/PostingService.php';
        $svc = new PostingService($db, $companyId, $userId);
        self::atomic($db, $companyId, $runId, function (array $run) use ($db, $svc, $userId) {
            if ($run['status'] === 'posted') throw new PayProblem('This run is already in the books.');
            if ($run['status'] !== 'locked') throw new PayProblem('Finalise the run before it goes to the books.');
            $svc->postPayrollRun((int)$run['id']);
            $db->prepare("UPDATE pay_runs SET status = 'posted', posted_by = ?, posted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$userId, $run['id']]);
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_post_gl', ['run' => self::label($db, $companyId, $runId)], 'pay_run', $runId);
    }

    /** A run that never got as far as payslips can be thrown away. */
    public static function delete(PDO $db, int $companyId, int $userId, int $runId): void
    {
        $label = self::label($db, $companyId, $runId);
        self::atomic($db, $companyId, $runId, function (array $run) use ($db, $companyId) {
            if (in_array($run['status'], ['locked', 'posted'], true)) throw new PayProblem('Finalised runs are kept: their payslips may already have gone out.');
            $db->prepare("DELETE FROM pay_run_lines WHERE run_id = ? AND company_id = ?")->execute([$run['id'], $companyId]);
            $db->prepare("DELETE FROM pay_run_employees WHERE run_id = ? AND company_id = ?")->execute([$run['id'], $companyId]);
            $db->prepare("DELETE FROM pay_runs WHERE id = ? AND company_id = ?")->execute([$run['id'], $companyId]);
        });
        PayKit::audit($db, $companyId, $userId, 'payrun_deleted', ['run' => $label], 'pay_run', $runId);
    }

    /** The bank payments file: one row per person with net pay and bank details. */
    public static function bankRows(PDO $db, int $companyId, int $runId): array
    {
        $run = self::row($db, $companyId, $runId);
        if (!in_array($run['status'], ['locked', 'posted'], true)) throw new PayProblem('Finalise the run first — the bank file pays the final figures.');
        $st = $db->prepare("SELECT e.employee_no, e.first_name, e.last_name, e.bank_name, e.branch_code, e.bank_account_no, e.bank_account_type, x.bank_amount_cents, x.calc_debug_json
                              FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                             WHERE x.run_id = ? AND x.company_id = ? AND x.bank_amount_cents > 0 ORDER BY e.first_name, e.last_name");
        $st->execute([$runId, $companyId]);
        $ok = [];
        $missing = [];
        $changed = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            // Pay into the account as it was when the run was approved.
            $frozen = (json_decode((string)$r['calc_debug_json'], true) ?: [])['bank'] ?? null;
            if (is_array($frozen)) {
                $now = [(string)$r['bank_name'], (string)$r['branch_code'], (string)$r['bank_account_no'], (string)$r['bank_account_type']];
                if ($now !== [(string)$frozen['bank_name'], (string)$frozen['branch_code'], (string)$frozen['bank_account_no'], (string)$frozen['bank_account_type']]) $changed[] = $name;
                foreach (['bank_name', 'branch_code', 'bank_account_no', 'bank_account_type'] as $k) $r[$k] = $frozen[$k];
            }
            unset($r['calc_debug_json']);
            if (trim((string)$r['bank_account_no']) === '' || trim((string)$r['branch_code']) === '') $missing[] = $name;
            else $ok[] = $r;
        }
        return ['run' => $run, 'rows' => $ok, 'missing' => $missing, 'changed' => $changed];
    }

    /** Keep each person's bank details as they are at approval; the bank file pays into these. */
    private static function freezeBankDetails(PDO $db, int $companyId, int $runId): void
    {
        $st = $db->prepare("SELECT x.id, x.calc_debug_json, e.bank_name, e.branch_code, e.bank_account_no, e.bank_account_type
                              FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id WHERE x.run_id = ? AND x.company_id = ?");
        $st->execute([$runId, $companyId]);
        $upd = $db->prepare("UPDATE pay_run_employees SET calc_debug_json = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['calc_debug_json'], true) ?: [];
            $d['bank'] = ['bank_name' => $r['bank_name'], 'branch_code' => $r['branch_code'], 'bank_account_no' => $r['bank_account_no'], 'bank_account_type' => $r['bank_account_type']];
            $upd->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $r['id']]);
        }
    }

    // -------------------------------------------------- keeping runs current

    /**
     * After someone's details change: work them out again in every run being
     * prepared (or take them out when they no longer belong in it), and say
     * which approved runs still carry the old figures.
     */
    public static function refreshEmployee(PDO $db, int $companyId, int $userId, int $employeeId): array
    {
        $st = $db->prepare("SELECT r.id, r.status FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id WHERE x.company_id = ? AND x.employee_id = ? AND r.status IN ('draft','calculated','review','approved')");
        $st->execute([$companyId, $employeeId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['status'] === 'approved') { $out[] = ['run' => self::label($db, $companyId, (int)$r['id']), 'run_id' => (int)$r['id'], 'action' => 'stale']; continue; }
            try {
                $action = self::atomic($db, $companyId, (int)$r['id'], function (array $run) use ($db, $companyId, $employeeId) {
                    if (!in_array($run['status'], self::OPEN, true)) return null;
                    $e = $db->prepare("SELECT * FROM employees WHERE id = ? AND company_id = ? AND pay_frequency = ? AND hire_date <= ? AND (termination_date IS NULL OR termination_date >= ?)");
                    $e->execute([$employeeId, $companyId, $run['frequency'], $run['period_end'], $run['period_start']]);
                    $emp = $e->fetch(PDO::FETCH_ASSOC);
                    if (!$emp) {
                        $db->prepare("DELETE FROM pay_run_lines WHERE run_id = ? AND company_id = ? AND employee_id = ?")->execute([$run['id'], $companyId, $employeeId]);
                        $db->prepare("DELETE FROM pay_run_employees WHERE run_id = ? AND company_id = ? AND employee_id = ?")->execute([$run['id'], $companyId, $employeeId]);
                        return 'removed';
                    }
                    self::calcPerson($db, $companyId, $run, $emp, PayKit::settings($db, $companyId));
                    return 'updated';
                });
                if ($action) $out[] = ['run' => self::label($db, $companyId, (int)$r['id']), 'run_id' => (int)$r['id'], 'action' => $action];
            } catch (PayProblem $e) {
                $out[] = ['run' => self::label($db, $companyId, (int)$r['id']), 'run_id' => (int)$r['id'], 'action' => 'error', 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /** People who belong in a run (right frequency, employed in the period); $notIn: only those not in it yet. */
    public static function eligible(PDO $db, int $companyId, array $run, bool $notIn): array
    {
        $sql = "SELECT e.* FROM employees e WHERE e.company_id = ? AND e.pay_frequency = ? AND e.hire_date <= ? AND (e.termination_date IS NULL OR e.termination_date >= ?)";
        $args = [$companyId, $run['frequency'], $run['period_end'], $run['period_start']];
        if ($notIn) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM pay_run_employees x WHERE x.run_id = ? AND x.employee_id = e.id)";
            $args[] = $run['id'];
        }
        $st = $db->prepare($sql . " ORDER BY e.first_name, e.last_name");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$notIn) return $rows;
        return array_map(fn($e) => $e + ['name' => trim($e['first_name'] . ' ' . $e['last_name'])], $rows);
    }

    /** Work one person out and store the result (adjustment lines are kept as they are). */
    private static function calcPerson(PDO $db, int $companyId, array $run, array $emp, array $settings, ?array $table = null, ?array $items = null): array
    {
        $r = PayEngine::for($db, $companyId, $emp, $run, $settings, $table, $items);
        $prev = $db->prepare("SELECT x.net_cents FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id
                               WHERE x.company_id = ? AND x.employee_id = ? AND r.id <> ? AND r.status IN ('locked','posted') AND r.pay_date <= ? ORDER BY r.pay_date DESC, r.id DESC LIMIT 1");
        $prev->execute([$companyId, $emp['id'], $run['id'], $run['pay_date']]);
        $prevNet = $prev->fetchColumn();
        $variance = $prevNet === false ? null : $r['net_cents'] - (int)$prevNet;
        $db->prepare("INSERT INTO pay_run_employees (company_id, run_id, employee_id, gross_cents, taxable_income_cents, paye_cents, uif_employee_cents, uif_employer_cents, sdl_cents,
                             other_deductions_cents, reimbursements_cents, net_cents, employer_cost_cents, bank_amount_cents, variance_vs_last_cents, calc_debug_json)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE gross_cents = VALUES(gross_cents), taxable_income_cents = VALUES(taxable_income_cents), paye_cents = VALUES(paye_cents),
                             uif_employee_cents = VALUES(uif_employee_cents), uif_employer_cents = VALUES(uif_employer_cents), sdl_cents = VALUES(sdl_cents),
                             other_deductions_cents = VALUES(other_deductions_cents), reimbursements_cents = VALUES(reimbursements_cents), net_cents = VALUES(net_cents),
                             employer_cost_cents = VALUES(employer_cost_cents), bank_amount_cents = VALUES(bank_amount_cents), variance_vs_last_cents = VALUES(variance_vs_last_cents),
                             calc_debug_json = VALUES(calc_debug_json)")
           ->execute([$companyId, $run['id'], $emp['id'], $r['gross_cents'], $r['taxable_income_cents'], $r['paye_cents'], $r['uif_employee_cents'], $r['uif_employer_cents'],
                      $r['sdl_cents'], $r['other_deductions_cents'], $r['reimbursements_cents'], $r['net_cents'], $r['employer_cost_cents'], $r['bank_amount_cents'],
                      $variance, json_encode($r['debug'], JSON_UNESCAPED_UNICODE)]);
        $db->prepare("DELETE FROM pay_run_lines WHERE run_id = ? AND company_id = ? AND employee_id = ? AND is_adhoc = 0")->execute([$run['id'], $companyId, $emp['id']]);
        $ins = $db->prepare("INSERT INTO pay_run_lines (company_id, run_id, employee_id, payitem_id, description, qty, rate_cents, amount_cents, project_id, is_adhoc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        foreach ($r['lines'] as $l) {
            if (!empty($l['is_adhoc'])) continue;
            $ins->execute([$companyId, $run['id'], $emp['id'], $l['payitem_id'], mb_substr((string)$l['description'], 0, 255), $l['qty'], $l['rate_cents'], $l['amount_cents'], $l['project_id'] ?: null]);
        }
        return $r;
    }

    private static function recalcAll(PDO $db, int $companyId, array $run): int
    {
        $st = $db->prepare("SELECT e.* FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id WHERE x.run_id = ? AND x.company_id = ?");
        $st->execute([$run['id'], $companyId]);
        $people = $st->fetchAll(PDO::FETCH_ASSOC);
        $settings = PayKit::settings($db, $companyId);
        $table = PayEngine::table($db, $companyId, $run['pay_date']);
        $items = PayEngine::items($db, $companyId);
        foreach ($people as $emp) self::calcPerson($db, $companyId, $run, $emp, $settings, $table, $items);
        if ($people && $run['status'] === 'draft') $db->prepare("UPDATE pay_runs SET status = 'calculated', updated_at = NOW() WHERE id = ?")->execute([$run['id']]);
        return count($people);
    }

    /** What stops a run from being approved, in plain words. */
    public static function blockers(PDO $db, int $companyId, int $runId): array
    {
        $st = $db->prepare("SELECT CONCAT_WS(' ', e.first_name, e.last_name) name, x.calc_debug_json FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id WHERE x.run_id = ? AND x.company_id = ?");
        $st->execute([$runId, $companyId]);
        $neg = $hours = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $w = (json_decode((string)$r['calc_debug_json'], true) ?: [])['warnings'] ?? [];
            if (in_array('negative_net', $w, true)) $neg[] = $r['name'];
            if (in_array('no_hours', $w, true)) $hours[] = $r['name'];
        }
        $out = [];
        if ($neg) $out[] = 'Deductions are more than the pay for ' . self::names($neg) . '.';
        if ($hours) $out[] = 'Enter the hours worked for ' . self::names($hours) . ', or take ' . (count($hours) === 1 ? 'them' : 'them') . ' out of this run.';
        return $out;
    }

    private static function names(array $n): string
    {
        if (count($n) <= 3) return count($n) === 1 ? $n[0] : implode(', ', array_slice($n, 0, -1)) . ' and ' . end($n);
        return implode(', ', array_slice($n, 0, 2)) . ' and ' . (count($n) - 2) . ' others';
    }

    private static function member(PDO $db, int $companyId, array $run, int $employeeId): array
    {
        $st = $db->prepare("SELECT e.* FROM pay_run_employees x JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id WHERE x.run_id = ? AND x.company_id = ? AND x.employee_id = ?");
        $st->execute([$run['id'], $companyId, $employeeId]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) throw new PayProblem('They are not in this pay run.');
        return $emp;
    }

    private static function mustBeOpen(array $run): void
    {
        if (!in_array($run['status'], self::OPEN, true)) {
            throw new PayProblem($run['status'] === 'approved' ? 'This run is approved, so its figures are frozen. Send it back to change it.' : 'This run is finalised and cannot change.');
        }
    }

    public static function row(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new PayProblem('That pay run no longer exists.');
        return $r;
    }

    private static function lock(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new PayProblem('That pay run no longer exists.');
        return $r;
    }

    /** Run $fn with the run's row locked, in one transaction. */
    private static function atomic(PDO $db, int $companyId, int $runId, callable $fn)
    {
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $res = $fn(self::lock($db, $companyId, $runId));
            if ($own) $db->commit();
            return $res;
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function label(PDO $db, int $companyId, int $runId): string
    {
        $st = $db->prepare("SELECT CONCAT(run_number, ' · ', name) FROM pay_runs WHERE id = ? AND company_id = ?");
        $st->execute([$runId, $companyId]);
        return (string)($st->fetchColumn() ?: 'Run #' . $runId);
    }

    /** An exception's message when it is meant for people; otherwise a general one (and a log line). */
    public static function human(Throwable $e): string
    {
        if ($e instanceof PayProblem || $e instanceof PayInvalid || in_array(get_class($e), ['Exception', 'RuntimeException'], true)) return $e->getMessage();
        error_log('[payroll] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return 'Something went wrong. Please try again.';
    }
}
