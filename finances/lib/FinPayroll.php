<?php
// finances/lib/FinPayroll.php
//
// Payroll as Finance sees it: pay runs with their totals and whether they are
// in the books, posting a locked run (the same engine call as Payroll's own
// "post to finance"), and what is owed to SARS on the EMP201 (PAYE, UIF and
// SDL liability balances). Runs are calculated, approved and locked in
// /payroll/; Finance never changes them.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/PostingService.php';

final class FinPayroll
{
    public static function runs(PDO $db, int $companyId, array $f = []): array
    {
        $where = ['r.company_id = ?'];
        $args = [$companyId];
        $status = (string)($f['status'] ?? 'all');
        if ($status === 'to_post') $where[] = "r.status = 'locked'";
        elseif ($status === 'posted') $where[] = "r.status = 'posted'";
        elseif ($status === 'open') $where[] = "r.status IN ('draft','calculated','review','approved')";
        try {
            $st = $db->prepare("SELECT r.id, r.run_number, r.name, r.frequency, r.period_start, r.period_end, r.pay_date, r.status, r.journal_id, r.posted_at,
                                       COUNT(e.id) people, COALESCE(SUM(e.gross_cents),0) gross, COALESCE(SUM(e.paye_cents),0) paye,
                                       COALESCE(SUM(e.uif_employee_cents + e.uif_employer_cents),0) uif, COALESCE(SUM(e.sdl_cents),0) sdl,
                                       COALESCE(SUM(e.bank_amount_cents),0) bank, COALESCE(SUM(e.employer_cost_cents),0) cost
                                  FROM pay_runs r LEFT JOIN pay_run_employees e ON e.run_id = r.id AND e.company_id = r.company_id
                                 WHERE " . implode(' AND ', $where) . " GROUP BY r.id ORDER BY r.pay_date DESC, r.id DESC LIMIT 120");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $c = $db->prepare("SELECT SUM(status = 'locked'), SUM(status = 'posted'), SUM(status IN ('draft','calculated','review','approved')), COUNT(*) FROM pay_runs WHERE company_id = ?");
            $c->execute([$companyId]);
            [$tp, $po, $op, $all] = $c->fetch(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            return ['rows' => [], 'counts' => ['to_post' => 0, 'posted' => 0, 'open' => 0, 'all' => 0], 'liabilities' => [], 'available' => false];
        }
        $out = array_map(fn($r) => [
            'id' => (int)$r['id'], 'number' => (string)($r['run_number'] ?: '#' . $r['id']), 'name' => (string)($r['name'] ?? ''), 'frequency' => (string)$r['frequency'],
            'start' => $r['period_start'], 'end' => $r['period_end'], 'pay_date' => $r['pay_date'], 'status' => (string)$r['status'],
            'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null, 'people' => (int)$r['people'],
            'gross' => (int)$r['gross'] / 100, 'paye' => (int)$r['paye'] / 100, 'uif' => (int)$r['uif'] / 100, 'sdl' => (int)$r['sdl'] / 100,
            'net' => (int)$r['bank'] / 100, 'cost' => (int)$r['cost'] / 100, 'url' => '/payroll/run_view.php?id=' . (int)$r['id'],
        ], $rows);
        return ['rows' => $out, 'counts' => ['to_post' => (int)$tp, 'posted' => (int)$po, 'open' => (int)$op, 'all' => (int)$all],
                'liabilities' => self::liabilities($db, $companyId), 'available' => true];
    }

    /** PAYE / UIF / SDL owed (credit balance of each liability account) and the EMP201 due date. */
    public static function liabilities(PDO $db, int $companyId): array
    {
        $map = new AccountsMap($db, $companyId);
        $out = [];
        $due = (new DateTimeImmutable('first day of next month'))->modify('+6 days');
        if ((int)date('j') <= 7) $due = new DateTimeImmutable(date('Y-m-07'));
        while ((int)$due->format('N') >= 6) $due = $due->modify('-1 day');
        foreach (['paye' => ['finance_paye_account_id', 'PAYE'], 'uif' => ['finance_uif_account_id', 'UIF'], 'sdl' => ['finance_sdl_account_id', 'SDL']] as $k => [$key, $label]) {
            $code = $map->code($key);
            if (!$code) continue;
            $st = $db->prepare("SELECT COALESCE(SUM(jl.credit - jl.debit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                                 WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?");
            $st->execute([$companyId, $code]);
            $out[] = ['key' => $k, 'label' => $label, 'code' => $code, 'owed' => round((float)$st->fetchColumn(), 2)];
        }
        return ['rows' => $out, 'total' => round(array_sum(array_column($out, 'owed')), 2), 'due' => $due->format('Y-m-d')];
    }

    /** Post a locked run to the books (exactly what Payroll's "post to finance" does), in one transaction. */
    public static function post(PDO $db, int $companyId, int $userId, string $role, int $runId): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $runId) {
            $st = $db->prepare("SELECT status, journal_id FROM pay_runs WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$runId, $companyId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new FinUserError('That pay run was not found.');
            if ($r['status'] === 'posted') throw new FinUserError('This pay run is already in the books.', 409);
            if ($r['status'] !== 'locked') throw new FinUserError('Lock the pay run in Payroll first; only a locked run is posted.', 409);
            (new PostingService($db, $companyId, $userId))->postPayrollRun($runId);
            $db->prepare("UPDATE pay_runs SET status = 'posted', posted_by = ?, posted_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$userId, $runId, $companyId]);
            Fin::audit($db, $companyId, $userId, 'payrun_post_gl', 'pay_run', $runId, ['from' => 'finance']);
        });
    }
}
