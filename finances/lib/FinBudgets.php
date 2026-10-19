<?php
// finances/lib/FinBudgets.php
//
// Budgets per income / expense account per month (gl_budgets, calendar year
// and month, company-wide rows have project_id NULL), shown and edited by
// financial year, with the actual figures next to them.
//
// Saving changes only the cells that were sent: the old save deleted every
// unlocked month of the year and inserted what the page posted, so cells it
// did not send (a filtered view, another account group) were lost. A cell
// sent empty or nil is removed; a cell in a locked month is left alone.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/PeriodService.php';

final class FinBudgets
{
    /** The financial year starting in $fyStartYear: 12 [year, month] pairs. */
    public static function months(PDO $db, int $companyId, int $fyStartYear): array
    {
        $m0 = Fin::monthNumber(Fin::engineSettings($db, $companyId)['fiscal_year_start']);
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $mm = ($m0 - 1 + $i) % 12 + 1;
            $yy = $fyStartYear + intdiv($m0 - 1 + $i, 12);
            $out[] = [$yy, $mm];
        }
        return $out;
    }

    public static function grid(PDO $db, int $companyId, int $fyStartYear): array
    {
        $months = self::months($db, $companyId, $fyStartYear);
        $from = sprintf('%04d-%02d-01', $months[0][0], $months[0][1]);
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $months[11][0], $months[11][1])));
        $st = $db->prepare("SELECT account_id, account_code, account_name, account_type, parent_id FROM gl_accounts
                             WHERE company_id = ? AND is_active = 1 AND account_type IN ('revenue','expense') ORDER BY account_type = 'expense', account_code");
        $st->execute([$companyId]);
        $accounts = $st->fetchAll(PDO::FETCH_ASSOC);
        $b = $db->prepare("SELECT gl_account_id, period_year, period_month, amount_cents FROM gl_budgets
                            WHERE company_id = ? AND project_id IS NULL AND ((period_year * 100 + period_month) BETWEEN ? AND ?)");
        $b->execute([$companyId, $months[0][0] * 100 + $months[0][1], $months[11][0] * 100 + $months[11][1]]);
        $budget = [];
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) $budget[(int)$r['gl_account_id']][(int)$r['period_year'] * 100 + (int)$r['period_month']] = (int)$r['amount_cents'];
        $a = $db->prepare("SELECT jl.account_code, YEAR(je.entry_date) y, MONTH(je.entry_date) m, SUM(jl.credit - jl.debit) cr
                             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                            WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ? AND COALESCE(je.module,'') <> 'year_end'
                         GROUP BY jl.account_code, y, m");
        $a->execute([$companyId, $from, $to]);
        $actual = [];
        foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) $actual[$r['account_code']][(int)$r['y'] * 100 + (int)$r['m']] = (int)round((float)$r['cr'] * 100);
        $ps = new PeriodService($db, $companyId);
        $locked = array_map(fn($ym) => $ps->isLocked(date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ym[0], $ym[1])))), $months);
        $rows = [];
        foreach ($accounts as $acc) {
            $id = (int)$acc['account_id'];
            $sign = $acc['account_type'] === 'revenue' ? 1 : -1;  // actuals in natural sign (income credit, expense debit)
            $bud = []; $act = [];
            foreach ($months as [$y, $m]) {
                $k = $y * 100 + $m;
                $bud[] = isset($budget[$id][$k]) ? $budget[$id][$k] / 100 : null;
                $act[] = isset($actual[$acc['account_code']][$k]) ? $sign * $actual[$acc['account_code']][$k] / 100 : 0;
            }
            $rows[] = ['id' => $id, 'code' => (string)$acc['account_code'], 'name' => (string)$acc['account_name'], 'type' => (string)$acc['account_type'],
                       'parent' => $acc['parent_id'] ? (int)$acc['parent_id'] : null, 'budget' => $bud, 'actual' => $act];
        }
        $label = fn($ym) => date('M Y', strtotime(sprintf('%04d-%02d-01', $ym[0], $ym[1])));
        return ['fy' => $fyStartYear, 'from' => $from, 'to' => $to, 'months' => array_map(fn($ym) => ['y' => $ym[0], 'm' => $ym[1], 'label' => $label($ym)], $months),
                'locked' => $locked, 'rows' => $rows, 'today_index' => self::indexOf($months, (int)date('Y'), (int)date('n'))];
    }

    private static function indexOf(array $months, int $y, int $m): ?int
    {
        foreach ($months as $i => [$yy, $mm]) if ($yy === $y && $mm === $m) return $i;
        return null;
    }

    /** $cells: [{account_id, y, m, amount}] — only these cells change. @return int cells written */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $cells): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        if (count($cells) > 5000) throw new FinUserError('Save at most 5,000 cells at once.');
        $st = $db->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('revenue','expense')");
        $st->execute([$companyId]);
        $valid = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $ps = new PeriodService($db, $companyId);
        $clean = [];
        foreach ($cells as $c) {
            if (!is_array($c)) continue;
            $acc = (int)($c['account_id'] ?? 0);
            $y = (int)($c['y'] ?? 0);
            $m = (int)($c['m'] ?? 0);
            if (!isset($valid[$acc]) || $y < 2000 || $y > 2100 || $m < 1 || $m > 12) continue;
            $raw = $c['amount'] ?? '';
            $cents = ($raw === '' || $raw === null) ? 0 : Fin::cents($raw);
            if ($cents === null) throw new FinUserError('One of the amounts is not a number.');
            if ($cents < 0) throw new FinUserError('Budgets are entered as positive amounts.');
            if ($ps->isLocked(date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m))))) continue;
            $clean[$acc . ':' . $y . ':' . $m] = [$acc, $y, $m, $cents];
        }
        if (!$clean) return 0;
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $clean) {
            $del = $db->prepare("DELETE FROM gl_budgets WHERE company_id = ? AND gl_account_id = ? AND period_year = ? AND period_month = ? AND project_id IS NULL");
            $ins = $db->prepare("INSERT INTO gl_budgets (company_id, gl_account_id, period_year, period_month, amount_cents, project_id, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())");
            foreach ($clean as [$acc, $y, $m, $cents]) {
                $del->execute([$companyId, $acc, $y, $m]);
                if ($cents > 0) $ins->execute([$companyId, $acc, $y, $m, $cents]);
            }
            Fin::audit($db, $companyId, $userId, 'budget_saved', 'budget', null, ['cells' => count($clean)]);
            return count($clean);
        });
    }

    /** Copy last year's actuals (or budget) into an empty financial year, optionally grown by a percentage. */
    public static function seed(PDO $db, int $companyId, int $userId, string $role, int $fyStartYear, string $from, float $growPct, bool $overwrite): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        if ($growPct < -100 || $growPct > 500) throw new FinUserError('The change is between −100% and 500%.');
        $prev = self::grid($db, $companyId, $fyStartYear - 1);
        $cur = self::grid($db, $companyId, $fyStartYear);
        $cells = [];
        foreach ($prev['rows'] as $i => $r) {
            foreach ($cur['months'] as $j => $mo) {
                $have = $cur['rows'][$i]['budget'][$j] ?? null;
                if ($have !== null && !$overwrite) continue;
                $base = $from === 'budget' ? (float)($r['budget'][$j] ?? 0) : (float)$r['actual'][$j];
                $v = round(max(0, $base) * (1 + $growPct / 100), 2);
                if ($v > 0 || ($overwrite && $have !== null)) $cells[] = ['account_id' => $r['id'], 'y' => $mo['y'], 'm' => $mo['m'], 'amount' => number_format($v, 2, '.', '')];
            }
        }
        return self::save($db, $companyId, $userId, $role, $cells);
    }
}
