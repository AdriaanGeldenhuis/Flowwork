<?php
// finances/lib/FinLedger.php
//
// The general ledger for one account: opening balance, every posted line in
// the period with the journal it belongs to and the other accounts in that
// journal, and a running balance on the account's normal side.
// Opening balance: a balance-sheet account carries everything posted before
// the period; an income or expense account starts at the beginning of the
// financial year the period starts in (so a year-end close never hides it).
// Optional project filter (lines tagged to the project or one of its boards).

require_once __DIR__ . '/Fin.php';

final class FinLedger
{
    public const MAX_ROWS = 5000;

    public static function detail(PDO $db, int $companyId, string $code, string $from, string $to, int $projectId = 0, int $limit = self::MAX_ROWS): array
    {
        $st = $db->prepare("SELECT account_id, account_code, account_name, account_type, account_subtype, normal_balance, is_active
                              FROM gl_accounts WHERE company_id = ? AND account_code = ?");
        $st->execute([$companyId, $code]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new FinUserError('That account is not on your chart of accounts.');
        $pl = in_array($a['account_type'], ['revenue', 'expense'], true);
        $sign = $a['normal_balance'] === 'credit' ? -1 : 1;

        $proj = '';
        $pArgs = [];
        if ($projectId > 0) {
            $proj = " AND (jl.project_id = ? OR (jl.project_id IS NULL AND jl.board_id IN (SELECT pb.board_id FROM project_boards pb WHERE pb.project_id = ? AND pb.company_id = ?)))";
            $pArgs = [$projectId, $projectId, $companyId];
        }
        $openFrom = $pl ? Fin::fyStart($db, $companyId, $from) : '1900-01-01';
        $st = $db->prepare("SELECT COALESCE(SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)), 0)
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date >= ? AND je.entry_date < ?$proj");
        $st->execute(array_merge([$companyId, $code, $openFrom, $from], $pArgs));
        $openC = (int)$st->fetchColumn();

        $st = $db->prepare("SELECT je.id journal_id, je.entry_date, je.reference, je.description memo, je.module, je.ref_type, je.source_type,
                                   je.reverses_journal_id, je.reversed_by_journal_id, jl.id line_id, jl.description, jl.debit, jl.credit, jl.project_id,
                                   (SELECT GROUP_CONCAT(DISTINCT o.account_code ORDER BY o.id SEPARATOR ',') FROM journal_lines o WHERE o.journal_id = je.id AND o.account_code <> jl.account_code) others
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date BETWEEN ? AND ?$proj
                          ORDER BY je.entry_date, je.id, jl.id
                             LIMIT " . ($limit + 1));
        $st->execute(array_merge([$companyId, $code, $from, $to], $pArgs));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $run = $openC;
        $dT = 0; $cT = 0;
        $out = [];
        require_once __DIR__ . '/FinJournals.php';
        foreach ($rows as $r) {
            $d = (int)round((float)$r['debit'] * 100);
            $c = (int)round((float)$r['credit'] * 100);
            $run += $d - $c;
            $dT += $d; $cT += $c;
            $out[] = [
                'journal_id' => (int)$r['journal_id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'],
                'memo' => (string)$r['memo'], 'description' => (string)$r['description'], 'source' => FinJournals::sourceOf($r),
                'debit' => $d / 100, 'credit' => $c / 100, 'balance' => $sign * $run / 100,
                'others' => array_slice(array_filter(explode(',', (string)$r['others'])), 0, 3),
                'reversal' => !empty($r['reverses_journal_id']), 'reversed' => !empty($r['reversed_by_journal_id']),
            ];
        }
        return [
            'account' => ['id' => (int)$a['account_id'], 'code' => (string)$a['account_code'], 'name' => (string)$a['account_name'], 'type' => (string)$a['account_type'],
                          'subtype' => (string)$a['account_subtype'], 'normal' => (string)$a['normal_balance'], 'active' => (bool)$a['is_active']],
            'from' => $from, 'to' => $to, 'opening' => $sign * $openC / 100, 'opening_from' => $pl ? $openFrom : null,
            'debit' => $dT / 100, 'credit' => $cT / 100, 'closing' => $more ? null : $sign * $run / 100,
            'rows' => $out, 'more' => $more, 'months' => self::months($db, $companyId, $code, $to, $sign, $pl),
        ];
    }

    /** Twelve month-end balances (or monthly movement for income / expense accounts) up to $to, for the sparkline. */
    public static function months(PDO $db, int $companyId, string $code, string $to, int $sign, bool $pl): array
    {
        $end = new DateTimeImmutable($to);
        $start = $end->modify('first day of this month')->modify('-11 months');
        $st = $db->prepare("SELECT DATE_FORMAT(je.entry_date, '%Y-%m') ym, SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)) v
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date BETWEEN ? AND ?
                               AND (je.module IS NULL OR je.module <> 'year_end')
                          GROUP BY ym");
        $st->execute([$companyId, $code, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        $mv = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        $base = 0;
        if (!$pl) {
            $st = $db->prepare("SELECT COALESCE(SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                                 WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date < ?");
            $st->execute([$companyId, $code, $start->format('Y-m-d')]);
            $base = (int)$st->fetchColumn();
        }
        $out = [];
        $run = $base;
        for ($i = 0; $i < 12; $i++) {
            $m = $start->modify("+$i months");
            $v = $mv[$m->format('Y-m')] ?? 0;
            $run += $v;
            $out[] = ['label' => $m->format('M'), 'ym' => $m->format('Y-m'), 'value' => $sign * ($pl ? $v : $run) / 100];
        }
        return $out;
    }
}
