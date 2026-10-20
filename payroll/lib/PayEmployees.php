<?php
// /payroll/lib/PayEmployees.php — the people on the payroll.
//   list / get / save / remove, ending and restoring employment, regular pay
//   items (allowances and deductions that repeat every pay day) and pay history.
// Every write is checked here, whatever the screen sent.

require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayEngine.php';

final class PayInvalid extends RuntimeException
{
    public array $errors;

    public function __construct(array $errors)
    {
        parent::__construct((string)(array_values($errors)[0] ?? 'Check the highlighted fields.'));
        $this->errors = $errors;
    }
}

final class PayEmployees
{
    public const LEAVE_REASONS = [
        'resigned' => 'Resigned', 'contract_ended' => 'Contract ended', 'dismissed' => 'Dismissed', 'retrenched' => 'Retrenched',
        'retired' => 'Retired', 'deceased' => 'Passed away', 'absconded' => 'Absconded', 'other' => 'Other',
    ];

    public static function list(PDO $db, int $companyId, array $f = []): array
    {
        $where = ['e.company_id = ?'];
        $args = [$companyId];
        $status = PayKit::key($f['status'] ?? 'active');
        if ($status === 'active') $where[] = '(e.termination_date IS NULL OR e.termination_date >= CURDATE())';
        elseif ($status === 'left') $where[] = '(e.termination_date IS NOT NULL AND e.termination_date < CURDATE())';
        if (isset(PayKit::FREQ[PayKit::key($f['frequency'] ?? '')])) { $where[] = 'e.pay_frequency = ?'; $args[] = $f['frequency']; }
        $q = trim(PayKit::key($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(CONCAT_WS(' ', e.first_name, e.last_name) LIKE ? OR e.employee_no LIKE ? OR e.email LIKE ? OR e.job_title LIKE ?)";
            array_push($args, $like, $like, $like, $like);
        }
        $st = $db->prepare("SELECT e.*, (SELECT MAX(r.pay_date) FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id WHERE x.employee_id = e.id AND r.status IN ('locked','posted')) AS last_paid
                              FROM employees e WHERE " . implode(' AND ', $where) . " ORDER BY e.first_name, e.last_name LIMIT 1000");
        $st->execute($args);
        $rows = array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
        $c = $db->prepare("SELECT SUM(termination_date IS NULL OR termination_date >= CURDATE()), SUM(termination_date IS NOT NULL AND termination_date < CURDATE()), COUNT(*) FROM employees WHERE company_id = ?");
        $c->execute([$companyId]);
        [$a, $l, $all] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
        return ['rows' => $rows, 'counts' => ['active' => $a, 'left' => $l, 'all' => $all]];
    }

    /** A row as the screens use it (no bank account number in lists — only whether it is there). */
    public static function shape(array $r): array
    {
        $today = date('Y-m-d');
        $left = !empty($r['termination_date']) && $r['termination_date'] < $today;
        $hourly = (int)$r['base_salary_cents'] === 0 && (int)$r['hourly_rate_cents'] > 0;
        $missing = [];
        if (trim((string)$r['tax_number']) === '') $missing[] = 'tax number';
        if (trim((string)$r['bank_account_no']) === '' || trim((string)$r['branch_code']) === '') $missing[] = 'bank details';
        if (trim((string)$r['id_number']) === '') $missing[] = 'ID number';
        if (empty($r['birth_date'])) $missing[] = 'date of birth';
        return [
            'id' => (int)$r['id'], 'no' => (string)$r['employee_no'], 'first_name' => (string)$r['first_name'], 'last_name' => (string)$r['last_name'],
            'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'job_title' => (string)($r['job_title'] ?? ''), 'email' => (string)($r['email'] ?? ''),
            'frequency' => (string)$r['pay_frequency'], 'type' => (string)$r['employment_type'],
            'basis' => $hourly ? 'hourly' : 'salary', 'monthly' => (int)$r['base_salary_cents'], 'hourly' => (int)$r['hourly_rate_cents'],
            'per_period' => $hourly ? null : PayEngine::periodSalary((int)$r['base_salary_cents'], (string)$r['pay_frequency']),
            'hire_date' => $r['hire_date'], 'termination_date' => $r['termination_date'], 'termination_reason' => $r['termination_reason'],
            'status' => $left ? 'left' : ($r['hire_date'] > $today ? 'starting' : (!empty($r['termination_date']) ? 'leaving' : 'active')),
            'missing' => $missing, 'last_paid' => $r['last_paid'] ?? null,
        ];
    }

    public static function get(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new PayProblem('That employee no longer exists.');
        $out = self::shape($r);
        foreach (['id_number', 'birth_date', 'tax_number', 'phone', 'bank_name', 'branch_code', 'bank_account_no', 'bank_account_type', 'default_project_id'] as $k) $out[$k] = $r[$k];
        $out['uif_included'] = (int)$r['uif_included'] === 1;
        $out['sdl_included'] = (int)$r['sdl_included'] === 1;
        $out['medical_members'] = (int)($r['medical_members'] ?? 0);
        $out['items'] = self::items($db, $companyId, $id);
        $out['history'] = self::history($db, $companyId, $id);
        $st = $db->prepare("SELECT COUNT(*) FROM pay_run_employees WHERE company_id = ? AND employee_id = ?");
        $st->execute([$companyId, $id]);
        $out['can_delete'] = (int)$st->fetchColumn() === 0;
        return $out;
    }

    /** The next employee number in the company's own style (E007 → E008), or E001. */
    public static function nextNumber(PDO $db, int $companyId): string
    {
        $st = $db->prepare("SELECT employee_no FROM employees WHERE company_id = ? ORDER BY id DESC LIMIT 50");
        $st->execute([$companyId]);
        $best = null;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('/^(.*?)(\d+)$/', (string)$no, $m) && ($best === null || (int)$m[2] > (int)$best[2])) $best = $m;
        }
        $exists = $db->prepare("SELECT 1 FROM employees WHERE company_id = ? AND employee_no = ?");
        [$prefix, $num, $width] = $best ? [$best[1], (int)$best[2], strlen($best[2])] : ['E', 0, 3];
        do {
            $num++;
            $candidate = $prefix . str_pad((string)$num, $width, '0', STR_PAD_LEFT);
            $exists->execute([$companyId, $candidate]);
        } while ($exists->fetchColumn());
        return $candidate;
    }

    /** Create or update. Returns [id, created, recalculated runs]. */
    public static function save(PDO $db, int $companyId, int $userId, array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $old = null;
        if ($id) {
            $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new PayProblem('That employee no longer exists.');
        }
        $s = fn($k, $max = 190) => mb_substr(trim(preg_replace('/\s+/u', ' ', PayKit::key($in[$k] ?? ''))), 0, $max);
        $errors = [];
        $v = [];
        $v['first_name'] = $s('first_name', 120);
        $v['last_name'] = $s('last_name', 120);
        if ($v['first_name'] === '') $errors['first_name'] = 'Enter their first name.';
        if ($v['last_name'] === '') $errors['last_name'] = 'Enter their surname.';
        $v['job_title'] = $s('job_title', 120) ?: null;
        $v['employee_no'] = $s('employee_no', 32);
        if ($v['employee_no'] === '') $v['employee_no'] = $old ? $old['employee_no'] : self::nextNumber($db, $companyId);
        $st = $db->prepare("SELECT id FROM employees WHERE company_id = ? AND employee_no = ? AND id <> ?");
        $st->execute([$companyId, $v['employee_no'], $id]);
        if ($st->fetchColumn()) $errors['employee_no'] = 'Someone else already has number ' . $v['employee_no'] . '.';

        $idn = preg_replace('/\s+/', '', $s('id_number', 32));
        $v['id_number'] = $idn !== '' ? $idn : null;
        $birth = $s('birth_date', 10);
        $fromId = $idn !== '' ? PayTax::birthDateFromId($idn) : null;
        if ($idn !== '' && preg_match('/^\d{13}$/', $idn) && !$fromId) $errors['id_number'] = 'That is not a valid South African ID number — check the digits. (Use the passport number for foreign nationals.)';
        elseif ($idn !== '' && !preg_match('/^[A-Za-z0-9]{5,20}$/', $idn)) $errors['id_number'] = 'Use only letters and digits.';
        if ($birth !== '' && !self::isDate($birth)) $errors['birth_date'] = 'Enter a real date.';
        $v['birth_date'] = $fromId ?: ($birth !== '' ? $birth : null);
        if ($v['birth_date'] && $v['birth_date'] > date('Y-m-d')) $errors['birth_date'] = 'The date of birth is in the future.';

        $tax = preg_replace('/\D/', '', $s('tax_number', 20));
        if ($tax !== '' && strlen($tax) !== 10) $errors['tax_number'] = 'A SARS income tax number has 10 digits.';
        $v['tax_number'] = $tax;
        $v['email'] = mb_strtolower($s('email', 190)) ?: null;
        if ($v['email'] && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address, or leave it empty.';
        $v['phone'] = $s('phone', 64) ?: null;

        $v['hire_date'] = $s('hire_date', 10);
        if (!self::isDate($v['hire_date'])) $errors['hire_date'] = 'Enter the date they started.';
        $v['employment_type'] = isset(PayKit::TYPES[PayKit::key($in['employment_type'] ?? '')]) ? $in['employment_type'] : 'permanent';
        $v['pay_frequency'] = isset(PayKit::FREQ[PayKit::key($in['pay_frequency'] ?? '')]) ? $in['pay_frequency'] : 'monthly';

        $basis = ($in['basis'] ?? 'salary') === 'hourly' ? 'hourly' : 'salary';
        $salary = PayKit::cents($in['monthly'] ?? '');
        $hourly = PayKit::cents($in['hourly'] ?? '');
        if ($basis === 'salary') {
            if ($salary === null || $salary <= 0) $errors['monthly'] = 'Enter the monthly salary.';
            elseif ($salary > 1000000000) $errors['monthly'] = 'That salary looks too large — check the amount.';
            if ($hourly === null || $hourly < 0) $errors['hourly'] = 'Enter an hourly rate, or leave it empty.';
            $v['base_salary_cents'] = max(0, (int)$salary);
            $v['hourly_rate_cents'] = max(0, (int)$hourly);
        } else {
            if ($hourly === null || $hourly <= 0) $errors['hourly'] = 'Enter the rate per hour.';
            elseif ($hourly > 10000000) $errors['hourly'] = 'That hourly rate looks too large — check the amount.';
            $v['base_salary_cents'] = 0;
            $v['hourly_rate_cents'] = max(0, (int)$hourly);
        }

        $v['uif_included'] = !empty($in['uif_included']) ? 1 : 0;
        $v['sdl_included'] = !empty($in['sdl_included']) ? 1 : 0;
        $mm = (int)($in['medical_members'] ?? 0);
        if ($mm < 0 || $mm > 20) $errors['medical_members'] = 'Between 0 and 20 people.';
        $v['medical_members'] = max(0, min(20, $mm));

        $v['bank_name'] = $s('bank_name', 120) ?: null;
        $branch = preg_replace('/\D/', '', $s('branch_code', 10));
        $acct = preg_replace('/\D/', '', $s('bank_account_no', 32));
        if ($branch !== '' && strlen($branch) !== 6) $errors['branch_code'] = 'Branch codes have 6 digits.';
        if ($acct !== '' && (strlen($acct) < 5 || strlen($acct) > 16)) $errors['bank_account_no'] = 'Account numbers have between 5 and 16 digits.';
        if ($acct !== '' && $branch === '' && !isset($errors['branch_code'])) $errors['branch_code'] = 'Enter the branch code too.';
        $v['branch_code'] = $branch !== '' ? $branch : null;
        $v['bank_account_no'] = $acct !== '' ? $acct : null;
        $v['bank_account_type'] = isset(PayKit::ACCOUNT_TYPES[PayKit::key($in['bank_account_type'] ?? '')]) ? $in['bank_account_type'] : null;

        $proj = (int)($in['default_project_id'] ?? 0);
        $v['default_project_id'] = null;
        if ($proj > 0) {
            $st = $db->prepare("SELECT 1 FROM projects WHERE project_id = ? AND company_id = ?");
            $st->execute([$proj, $companyId]);
            if ($st->fetchColumn()) $v['default_project_id'] = $proj;
            else $errors['default_project_id'] = 'That project no longer exists.';
        }
        if ($old && !empty($old['termination_date']) && $v['hire_date'] > $old['termination_date']) $errors['hire_date'] = 'The start date is after the day they left.';
        if ($errors) throw new PayInvalid($errors);

        $created = !$old;
        $db->beginTransaction();
        try {
            if ($old) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
                $db->prepare("UPDATE employees SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute(array_merge(array_values($v), [$id, $companyId]));
            } else {
                $v['company_id'] = $companyId;
                $v['created_by'] = $userId;
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($v)));
                $db->prepare("INSERT INTO employees ($cols, created_at) VALUES (" . implode(', ', array_fill(0, count($v), '?')) . ", NOW())")->execute(array_values($v));
                $id = (int)$db->lastInsertId();
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $changes = [];
        if ($old) {
            $watch = ['base_salary_cents' => 'salary', 'hourly_rate_cents' => 'hourly rate', 'pay_frequency' => 'pay frequency', 'bank_account_no' => 'bank account',
                      'tax_number' => 'tax number', 'uif_included' => 'UIF', 'medical_members' => 'medical aid members', 'employee_no' => 'number'];
            foreach ($watch as $k => $label) if ((string)$old[$k] !== (string)$v[$k]) $changes[] = $label;
        }
        PayKit::audit($db, $companyId, $userId, $created ? 'employee_created' : 'employee_updated',
            array_filter(['employee' => $v['first_name'] . ' ' . $v['last_name'], 'changed' => $changes ? implode(', ', $changes) : null]), 'employee', $id);
        $runs = PayRuns::refreshEmployee($db, $companyId, $userId, $id);
        return [$id, $created, $runs];
    }

    /** End employment on a date: they get a last, pro-rated pay in the run that covers it. */
    public static function terminate(PDO $db, int $companyId, int $userId, int $id, string $date, string $reason, string $note): array
    {
        $emp = self::row($db, $companyId, $id);
        $errors = [];
        if (!self::isDate($date)) $errors['termination_date'] = 'Enter their last working day.';
        elseif ($date < $emp['hire_date']) $errors['termination_date'] = 'Their last day is before they started (' . PayKit::date($emp['hire_date']) . ').';
        if (!isset(self::LEAVE_REASONS[$reason])) $errors['termination_reason'] = 'Choose why they left.';
        if ($errors) throw new PayInvalid($errors);
        $text = self::LEAVE_REASONS[$reason] . ($note !== '' ? ' — ' . mb_substr($note, 0, 150) : '');
        $db->prepare("UPDATE employees SET termination_date = ?, termination_reason = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$date, $text, $id, $companyId]);
        PayKit::audit($db, $companyId, $userId, 'employee_terminated', ['employee' => $emp['first_name'] . ' ' . $emp['last_name'], 'last day' => PayKit::date($date), 'reason' => $text], 'employee', $id);
        return PayRuns::refreshEmployee($db, $companyId, $userId, $id);
    }

    public static function reinstate(PDO $db, int $companyId, int $userId, int $id): array
    {
        $emp = self::row($db, $companyId, $id);
        if (empty($emp['termination_date'])) throw new PayProblem('They are still employed.');
        $db->prepare("UPDATE employees SET termination_date = NULL, termination_reason = NULL, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        PayKit::audit($db, $companyId, $userId, 'employee_reinstated', ['employee' => $emp['first_name'] . ' ' . $emp['last_name']], 'employee', $id);
        return PayRuns::refreshEmployee($db, $companyId, $userId, $id);
    }

    /** Only someone who was never in a pay run can be deleted; everyone else is ended instead. */
    public static function delete(PDO $db, int $companyId, int $userId, int $id): void
    {
        $emp = self::row($db, $companyId, $id);
        $st = $db->prepare("SELECT r.status, r.name FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id WHERE x.company_id = ? AND x.employee_id = ? ORDER BY r.status IN ('locked','posted') DESC LIMIT 1");
        $st->execute([$companyId, $id]);
        if ($in = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new PayProblem(in_array($in['status'], ['locked', 'posted'], true)
                ? 'They have been paid before, so their records stay. End their employment instead.'
                : 'They are in the ' . $in['name'] . ' run. Take them out of it first, or end their employment.');
        }
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM employee_payitems WHERE company_id = ? AND employee_id = ?")->execute([$companyId, $id]);
            $db->prepare("DELETE FROM employees WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        PayKit::audit($db, $companyId, $userId, 'employee_deleted', ['employee' => $emp['first_name'] . ' ' . $emp['last_name'], 'number' => $emp['employee_no']], 'employee', $id);
    }

    // ------------------------------------------------------------ regular items

    public static function items(PDO $db, int $companyId, int $employeeId): array
    {
        $st = $db->prepare("SELECT ep.*, pi.name, pi.type, pi.code, pi.active AS item_active FROM employee_payitems ep JOIN payitems pi ON pi.id = ep.payitem_id AND pi.company_id = ep.company_id
                             WHERE ep.company_id = ? AND ep.employee_id = ? ORDER BY ep.active DESC, FIELD(pi.type,'earning','benefit','reimbursement','contribution','deduction'), pi.name");
        $st->execute([$companyId, $employeeId]);
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'payitem_id' => (int)$r['payitem_id'], 'name' => (string)$r['name'], 'type' => (string)$r['type'],
            'unit' => $r['unit'] === 'percent' ? 'percent' : 'fixed', 'amount' => (int)$r['amount_cents'], 'percent' => (int)$r['rate_cents'] / 100,
            'from' => $r['effective_from'], 'to' => $r['effective_to'], 'active' => (int)$r['active'] === 1,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function saveItem(PDO $db, int $companyId, int $userId, int $employeeId, array $in): array
    {
        $emp = self::row($db, $companyId, $employeeId);
        $id = (int)($in['id'] ?? 0);
        $errors = [];
        $st = $db->prepare("SELECT * FROM payitems WHERE id = ? AND company_id = ? AND active = 1");
        $st->execute([(int)($in['payitem_id'] ?? 0), $companyId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) $errors['payitem_id'] = 'Choose a pay item.';
        elseif ($item['code'] === PayEngine::BASIC) $errors['payitem_id'] = 'The basic salary is set on the Pay tab.';
        $unit = ($in['unit'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $amount = 0;
        $rate = 0;
        if ($unit === 'fixed') {
            $amount = PayKit::cents($in['amount'] ?? '');
            if ($amount === null || $amount <= 0) $errors['amount'] = 'Enter the amount each pay day.';
        } else {
            $pct = str_replace(',', '.', trim(PayKit::key($in['percent'] ?? '')));
            if (!is_numeric($pct) || (float)$pct <= 0 || (float)$pct > 100) $errors['percent'] = 'Enter a percentage between 0 and 100.';
            else $rate = (int)round((float)$pct * 100);
        }
        $from = trim(PayKit::key($in['from'] ?? ''));
        $to = trim(PayKit::key($in['to'] ?? ''));
        if ($from !== '' && !self::isDate($from)) $errors['from'] = 'Enter a real date, or leave it empty.';
        if ($to !== '' && !self::isDate($to)) $errors['to'] = 'Enter a real date, or leave it empty.';
        if ($from !== '' && $to !== '' && $to < $from) $errors['to'] = 'The end date is before the start date.';
        if ($errors) throw new PayInvalid($errors);
        $vals = [(int)$item['id'], (int)$amount, $unit, $rate, $from ?: null, $to ?: null];
        if ($id) {
            $u = $db->prepare("UPDATE employee_payitems SET payitem_id = ?, amount_cents = ?, unit = ?, rate_cents = ?, effective_from = ?, effective_to = ?, active = 1 WHERE id = ? AND company_id = ? AND employee_id = ?");
            $u->execute(array_merge($vals, [$id, $companyId, $employeeId]));
            if (!$u->rowCount()) {
                $chk = $db->prepare("SELECT 1 FROM employee_payitems WHERE id = ? AND company_id = ? AND employee_id = ?");
                $chk->execute([$id, $companyId, $employeeId]);
                if (!$chk->fetchColumn()) throw new PayProblem('That item no longer exists.');
            }
        } else {
            $db->prepare("INSERT INTO employee_payitems (payitem_id, amount_cents, unit, rate_cents, effective_from, effective_to, active, company_id, employee_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())")
               ->execute(array_merge($vals, [$companyId, $employeeId]));
        }
        PayKit::audit($db, $companyId, $userId, 'employee_item_saved', ['employee' => $emp['first_name'] . ' ' . $emp['last_name'], 'item' => $item['name'],
            'amount' => $unit === 'fixed' ? PayKit::money((int)$amount) : ($rate / 100) . '% of basic'], 'employee', $employeeId);
        return PayRuns::refreshEmployee($db, $companyId, $userId, $employeeId);
    }

    public static function removeItem(PDO $db, int $companyId, int $userId, int $employeeId, int $id): array
    {
        $emp = self::row($db, $companyId, $employeeId);
        $st = $db->prepare("SELECT pi.name FROM employee_payitems ep JOIN payitems pi ON pi.id = ep.payitem_id WHERE ep.id = ? AND ep.company_id = ? AND ep.employee_id = ?");
        $st->execute([$id, $companyId, $employeeId]);
        $name = $st->fetchColumn();
        if ($name === false) throw new PayProblem('That item no longer exists.');
        $db->prepare("DELETE FROM employee_payitems WHERE id = ? AND company_id = ? AND employee_id = ?")->execute([$id, $companyId, $employeeId]);
        PayKit::audit($db, $companyId, $userId, 'employee_item_removed', ['employee' => $emp['first_name'] . ' ' . $emp['last_name'], 'item' => $name], 'employee', $employeeId);
        return PayRuns::refreshEmployee($db, $companyId, $userId, $employeeId);
    }

    /** Pay runs the person was in, newest first. */
    public static function history(PDO $db, int $companyId, int $employeeId): array
    {
        $st = $db->prepare("SELECT r.id, r.run_number, r.name, r.pay_date, r.status, x.gross_cents, x.paye_cents, x.net_cents
                              FROM pay_run_employees x JOIN pay_runs r ON r.id = x.run_id AND r.company_id = x.company_id
                             WHERE x.company_id = ? AND x.employee_id = ? ORDER BY r.pay_date DESC, r.id DESC LIMIT 60");
        $st->execute([$companyId, $employeeId]);
        return array_map(fn($r) => ['run_id' => (int)$r['id'], 'number' => $r['run_number'], 'name' => $r['name'], 'pay_date' => $r['pay_date'],
                                    'stage' => PayKit::stage($r['status']), 'gross' => (int)$r['gross_cents'], 'paye' => (int)$r['paye_cents'], 'net' => (int)$r['net_cents']],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function row(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new PayProblem('That employee no longer exists.');
        return $r;
    }

    public static function isDate(string $s): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));
    }
}
