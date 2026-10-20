<?php
// /payroll/lib/PayItems.php — the things that can appear on a payslip.
// Basic salary and overtime are built in (their name, source code and
// account can change, their behaviour cannot). The usual South African
// items can be added in one go; SARS source codes are the ones used on
// IRP5 certificates (3601 income, 3605 annual payment, 3606 commission,
// 3713 other allowances, 3801 fringe benefits, 3810 employer medical aid,
// 4001 pension, 4003 provident, 4005 medical aid, 4006 retirement annuity).

require_once __DIR__ . '/PayKit.php';
require_once __DIR__ . '/PayEngine.php';
require_once __DIR__ . '/PayEmployees.php';

final class PayItems
{
    public const SYSTEM = [PayEngine::BASIC, PayEngine::OVERTIME];

    /** [code, name, type, taxable, uif, sdl, pre_tax, once-off, source code, account] */
    public const DEFAULTS = [
        ['BONUS', 'Bonus', 'earning', 1, 1, 1, 0, 1, '3605', null],
        ['COMMISSION', 'Commission', 'earning', 1, 1, 1, 0, 0, '3606', null],
        ['ALLOWANCE', 'Allowance', 'earning', 1, 1, 1, 0, 0, '3713', null],
        ['REIMBURSE', 'Reimbursement', 'reimbursement', 0, 0, 0, 0, 0, null, null],
        ['FRINGE', 'Fringe benefit', 'benefit', 1, 0, 1, 0, 0, '3801', null],
        ['PENSION', 'Pension fund', 'deduction', 0, 0, 0, 1, 0, '4001', '2220'],
        ['PROVIDENT', 'Provident fund', 'deduction', 0, 0, 0, 1, 0, '4003', '2220'],
        ['RA', 'Retirement annuity', 'deduction', 0, 0, 0, 1, 0, '4006', null],
        ['MEDICAL', 'Medical aid', 'deduction', 0, 0, 0, 0, 0, '4005', '2230'],
        ['LOAN', 'Staff loan repayment', 'deduction', 0, 0, 0, 0, 0, null, null],
        ['ER_MEDICAL', 'Medical aid (company share)', 'contribution', 1, 0, 1, 0, 0, '3810', '2230'],
    ];

    public static function list(PDO $db, int $companyId): array
    {
        PayEngine::systemItem($db, $companyId, PayEngine::BASIC);
        PayEngine::systemItem($db, $companyId, PayEngine::OVERTIME);
        $st = $db->prepare("SELECT pi.*, (SELECT COUNT(*) FROM employee_payitems ep WHERE ep.payitem_id = pi.id AND ep.active = 1) AS people,
                                   (SELECT COUNT(*) FROM pay_run_lines l WHERE l.payitem_id = pi.id) AS used
                              FROM payitems pi WHERE pi.company_id = ? ORDER BY pi.active DESC, FIELD(pi.type,'earning','deduction','reimbursement','benefit','contribution'), pi.code IN ('BASIC_SALARY','OVERTIME') DESC, pi.name");
        $st->execute([$companyId]);
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'type' => (string)$r['type'],
            'taxable' => (int)$r['taxable'] === 1, 'uif' => (int)$r['uif_subject'] === 1, 'sdl' => (int)$r['sdl_subject'] === 1,
            'pre_tax' => (int)($r['pre_tax'] ?? 0) === 1, 'irregular' => (int)($r['irregular'] ?? 0) === 1,
            'source_code' => (string)($r['paye_code'] ?? ''), 'gl' => (string)($r['gl_account_code'] ?? ''), 'active' => (int)$r['active'] === 1,
            'system' => in_array($r['code'], self::SYSTEM, true), 'people' => (int)$r['people'], 'used' => (int)$r['used'] > 0,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        $have = array_column($rows, 'code');
        $missing = array_values(array_filter(self::DEFAULTS, fn($d) => !in_array($d[0], $have, true)));
        return ['rows' => $rows, 'suggested' => array_map(fn($d) => ['code' => $d[0], 'name' => $d[1], 'type' => $d[2]], $missing)];
    }

    /** Add the usual items the company does not have yet (by code). */
    public static function addDefaults(PDO $db, int $companyId, int $userId, array $codes = []): int
    {
        $glOk = self::chartCodes($db, $companyId);
        $ins = $db->prepare("INSERT IGNORE INTO payitems (company_id, code, name, type, taxable, uif_subject, sdl_subject, pre_tax, irregular, paye_code, gl_account_code, basis, active, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'fixed', 1, NOW())");
        $n = 0;
        foreach (self::DEFAULTS as $d) {
            if ($codes && !in_array($d[0], $codes, true)) continue;
            $gl = $d[9] !== null && isset($glOk[$d[9]]) ? $d[9] : null;
            $ins->execute([$companyId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $gl]);
            $n += $ins->rowCount();
        }
        if ($n) PayKit::audit($db, $companyId, $userId, 'payitems_added', ['items' => $n]);
        return $n;
    }

    public static function save(PDO $db, int $companyId, int $userId, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $old = null;
        if ($id) {
            $st = $db->prepare("SELECT * FROM payitems WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new PayProblem('That pay item no longer exists.');
        }
        $system = $old && in_array($old['code'], self::SYSTEM, true);
        $errors = [];
        $name = mb_substr(trim(PayKit::key($in['name'] ?? '')), 0, 120);
        if ($name === '') $errors['name'] = 'Give it a name people will recognise on a payslip.';
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', trim(PayKit::key($in['code'] ?? ''))));
        if ($system) $code = $old['code'];
        if ($code === '') $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '_', $name), 0, 24)) ?: 'ITEM';
        $code = substr(trim($code, '_'), 0, 32);
        $dup = $db->prepare("SELECT 1 FROM payitems WHERE company_id = ? AND code = ? AND id <> ?");
        $dup->execute([$companyId, $code, $id]);
        if ($dup->fetchColumn()) $errors['code'] = 'Another item already uses the code ' . $code . '.';
        $type = $system ? $old['type'] : PayKit::key($in['type'] ?? '');
        if (!isset(PayKit::ITEM_TYPES[$type])) $errors['type'] = 'Choose what kind of item it is.';
        if ($old && $old['type'] !== $type && (int)$db->query("SELECT COUNT(*) FROM pay_run_lines WHERE payitem_id = " . (int)$id)->fetchColumn() > 0) {
            $errors['type'] = 'It is already on payslips, so its kind cannot change. Make a new item instead.';
        }
        $src = preg_replace('/\D/', '', PayKit::key($in['source_code'] ?? ''));
        if ($src !== '' && strlen($src) !== 4) $errors['source_code'] = 'SARS source codes have 4 digits, e.g. 3601.';
        $gl = trim(PayKit::key($in['gl'] ?? ''));
        if ($gl !== '') {
            $chart = self::chartCodes($db, $companyId);
            if ($chart && !isset($chart[$gl])) $errors['gl'] = 'There is no active account ' . $gl . ' in Finance.';
        }
        if ($errors) throw new PayInvalid($errors);

        $flag = fn($k) => !empty($in[$k]) ? 1 : 0;
        $taxable = $flag('taxable');
        $uif = $flag('uif');
        $sdl = $flag('sdl');
        $pre = in_array($type, ['deduction', 'contribution'], true) ? $flag('pre_tax') : 0;
        $once = $type === 'earning' ? $flag('irregular') : 0;
        if ($type === 'deduction' || $type === 'reimbursement') { $taxable = 0; $uif = 0; $sdl = 0; }
        if ($system) { $taxable = 1; $uif = 1; $sdl = 1; $once = 0; }
        $active = $system ? 1 : $flag('active');
        $vals = [$code, $name, $type, $taxable, $uif, $sdl, $pre, $once, $src ?: null, $gl ?: null, $active];
        if ($old) {
            $db->prepare("UPDATE payitems SET code = ?, name = ?, type = ?, taxable = ?, uif_subject = ?, sdl_subject = ?, pre_tax = ?, irregular = ?, paye_code = ?, gl_account_code = ?, active = ?, updated_at = NOW()
                          WHERE id = ? AND company_id = ?")->execute(array_merge($vals, [$id, $companyId]));
        } else {
            $db->prepare("INSERT INTO payitems (code, name, type, taxable, uif_subject, sdl_subject, pre_tax, irregular, paye_code, gl_account_code, active, company_id, basis, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'fixed', NOW())")->execute(array_merge($vals, [$companyId]));
            $id = (int)$db->lastInsertId();
        }
        PayKit::audit($db, $companyId, $userId, $old ? 'payitem_updated' : 'payitem_created', ['item' => $name, 'kind' => PayKit::ITEM_TYPES[$type][0]], 'payitem', $id);
        return $id;
    }

    /** Never-used items are deleted; used ones are switched off (old payslips still show them). */
    public static function remove(PDO $db, int $companyId, int $userId, int $id): string
    {
        $st = $db->prepare("SELECT * FROM payitems WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) throw new PayProblem('That pay item no longer exists.');
        if (in_array($it['code'], self::SYSTEM, true)) throw new PayProblem($it['name'] . ' is built in and cannot be removed.');
        $used = (int)$db->query("SELECT (SELECT COUNT(*) FROM pay_run_lines WHERE payitem_id = " . (int)$id . ") + (SELECT COUNT(*) FROM employee_payitems WHERE payitem_id = " . (int)$id . ")")->fetchColumn();
        if ($used) {
            $db->prepare("UPDATE payitems SET active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE employee_payitems SET active = 0 WHERE payitem_id = ? AND company_id = ?")->execute([$id, $companyId]);
            PayKit::audit($db, $companyId, $userId, 'payitem_archived', ['item' => $it['name']], 'payitem', $id);
            return 'archived';
        }
        $db->prepare("DELETE FROM payitems WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        PayKit::audit($db, $companyId, $userId, 'payitem_deleted', ['item' => $it['name']], 'payitem', $id);
        return 'deleted';
    }

    /** Active account codes of the company's chart (empty when Finance has no chart yet). */
    public static function chartCodes(PDO $db, int $companyId): array
    {
        try {
            $st = $db->prepare("SELECT account_code, account_name FROM gl_accounts WHERE company_id = ? AND is_active = 1");
            $st->execute([$companyId]);
            return $st->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            return [];
        }
    }
}
