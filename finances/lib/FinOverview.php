<?php
// finances/lib/FinOverview.php
//
// The Finance overview: one call that tells the owner and the bookkeeper how
// the business stands today and what needs doing.
//   - key numbers: cash in the books, what customers owe (and how much is
//     late), what is owed to suppliers, profit this month against last month,
//     the VAT position,
//   - cash flow: money in and out of the bank accounts per month for a year
//     (transfers between own accounts left out) and a forecast of what is due
//     in and out over the next weeks from open invoices, bills and VAT,
//   - needs attention: journals waiting for approval or posting, unmatched
//     and unreconciled bank lines, late invoices, bills falling due, VAT
//     periods to file, depreciation and payroll not yet in the books, months
//     not yet locked, low cash, large manual journals; each can be snoozed,
//   - aging (receivables and payables), income vs expenses per month, where
//     the money went this month, bank accounts, and recent postings.
// Read only. Amounts are rand (journal_lines / invoices / bills) unless a
// name ends in _cents (bank and VAT tables store cents).
// The AR and AP balance rules mirror the tested dashboard queries
// (tests/finance/test_dashboard_ar_kpi.php): open invoices exclude drafts and
// closed statuses and convert at COALESCE(NULLIF(exchange_rate,0),1); bill
// balances are total − payments − supplier credits.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';

final class FinOverview
{
    /** Invoice statuses that never carry a receivable. */
    public const AR_CLOSED = "('draft','paid','cancelled','written_off','uncollectible','refunded')";

    public static function build(PDO $db, int $companyId, int $userId, string $role, ?string $today = null): array
    {
        $today = Fin::ymd($today) ?: date('Y-m-d');
        $cfg = Fin::config($db, $companyId);
        $prefs = Fin::userPrefs($db, $companyId, $userId);
        $mods = $cfg['modules'];
        $off = array_unique(array_merge($cfg['overview_off'], $prefs['overview_off']));
        $want = fn(string $p) => !in_array($p, $off, true);

        $cash = self::cash($db, $companyId);
        $ar = $mods['receivables'] ? self::receivables($db, $companyId, $today) : null;
        $ap = $mods['payables'] ? self::payables($db, $companyId, $today, (int)$cfg['thresholds']['due_soon_days']) : null;
        $pl = self::profit($db, $companyId, $today);
        $vat = $mods['vat'] ? self::vat($db, $companyId, $today) : null;

        $panels = [];
        if ($want('kpis')) $panels['kpis'] = self::kpis($cash, $ar, $ap, $pl, $vat);
        if ($want('cashflow')) $panels['cashflow'] = self::cashflow($db, $companyId, $today, (int)$cfg['thresholds']['forecast_days'], $cash, $mods);
        if ($want('attention')) $panels['attention'] = self::attention($db, $companyId, $userId, $role, $today, $cfg, $prefs, $cash, $ar, $ap, $vat);
        if ($want('aging') && ($ar || $ap)) $panels['aging'] = ['ar' => $ar['aging'] ?? null, 'ap' => $ap['aging'] ?? null,
            'top_ar' => $ar ? self::topOverdueAr($db, $companyId, $today) : [], 'top_ap' => $ap ? self::topOverdueAp($db, $companyId, $today) : []];
        if ($want('profit')) $panels['profit'] = ['months' => $pl['months']];
        if ($want('spend')) $panels['spend'] = self::spend($db, $companyId, $today);
        if ($want('banks') && $mods['bank']) $panels['banks'] = self::banks($db, $companyId);
        if ($want('vat') && $vat) $panels['vat'] = $vat;
        if ($want('activity')) $panels['activity'] = self::recent($db, $companyId);

        return ['today' => $today, 'panels' => $panels, 'brief' => self::brief($cash, $ar, $ap, $pl, $vat)];
    }

    // ------------------------------------------------------------- figures

    /** Cash in the books (bank and cash accounts, posted journals) and per the bank statements. */
    public static function cash(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                              FROM journal_lines jl
                              JOIN journal_entries je ON je.id = jl.journal_id
                              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
                             WHERE je.company_id = ? AND je.status = 'posted'
                               AND ga.account_type = 'asset' AND ga.account_subtype IN ('bank','cash')");
        $st->execute([$companyId]);
        $books = round((float)$st->fetchColumn(), 2);
        $statement = null;
        try {
            $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(current_balance_cents), 0) c FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1");
            $st->execute([$companyId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] > 0) $statement = ((int)$r['c']) / 100;
        } catch (Throwable $e) {
            $statement = null;
        }
        return ['books' => $books, 'statement' => $statement];
    }

    public static function receivables(PDO $db, int $companyId, string $today): array
    {
        $rate = "COALESCE(NULLIF(i.exchange_rate,0),1)";
        $st = $db->prepare("
            SELECT COUNT(*) open_count,
                   COALESCE(SUM(i.balance_due * $rate), 0) outstanding,
                   SUM(i.due_date < ?) overdue_count,
                   COALESCE(SUM(CASE WHEN i.due_date < ? THEN i.balance_due * $rate END), 0) overdue,
                   COALESCE(SUM(CASE WHEN i.due_date >= ? OR i.due_date IS NULL THEN i.balance_due * $rate END), 0) b0,
                   SUM(i.due_date >= ? OR i.due_date IS NULL) n0,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 1 AND 30 THEN i.balance_due * $rate END), 0) b30,
                   SUM(DATEDIFF(?, i.due_date) BETWEEN 1 AND 30) n30,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 31 AND 60 THEN i.balance_due * $rate END), 0) b60,
                   SUM(DATEDIFF(?, i.due_date) BETWEEN 31 AND 60) n60,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, i.due_date) BETWEEN 61 AND 90 THEN i.balance_due * $rate END), 0) b90,
                   SUM(DATEDIFF(?, i.due_date) BETWEEN 61 AND 90) n90,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, i.due_date) > 90 THEN i.balance_due * $rate END), 0) b91,
                   SUM(DATEDIFF(?, i.due_date) > 90) n91
              FROM invoices i
             WHERE i.company_id = ? AND i.deleted_at IS NULL
               AND i.status NOT IN " . self::AR_CLOSED . " AND i.balance_due > 0");
        $st->execute(array_merge(array_fill(0, 12, $today), [$companyId]));
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'outstanding' => round((float)($r['outstanding'] ?? 0), 2), 'count' => (int)($r['open_count'] ?? 0),
            'overdue' => round((float)($r['overdue'] ?? 0), 2), 'overdue_count' => (int)($r['overdue_count'] ?? 0),
            'aging' => self::buckets($r),
        ];
    }

    /** Bill balances: total − payments − supplier credits (posted bills; paid ones drop out at zero). */
    private static function billBalances(): string
    {
        return "SELECT b.id, b.supplier_id, b.vendor_invoice_number, b.issue_date, b.due_date,
                       (b.total - IFNULL(p.paid, 0) - IFNULL(c.cred, 0)) balance
                  FROM ap_bills b
             LEFT JOIN (SELECT pa.bill_id, SUM(pa.amount) paid FROM ap_payment_allocations pa
                          JOIN ap_payments ap ON ap.id = pa.ap_payment_id AND ap.company_id = ? GROUP BY pa.bill_id) p ON p.bill_id = b.id
             LEFT JOIN (SELECT ca.bill_id, SUM(ca.amount) cred FROM vendor_credit_allocations ca
                          JOIN vendor_credits vc ON vc.id = ca.credit_id AND vc.company_id = ? GROUP BY ca.bill_id) c ON c.bill_id = b.id
                 WHERE b.company_id = ? AND b.status IN ('posted','paid')";
    }

    public static function payables(PDO $db, int $companyId, string $today, int $soonDays = 7): array
    {
        $soon = (new DateTimeImmutable($today))->modify("+$soonDays days")->format('Y-m-d');
        $st = $db->prepare("
            SELECT COUNT(*) open_count, COALESCE(SUM(balance), 0) outstanding,
                   SUM(due_date IS NOT NULL AND due_date < ?) overdue_count,
                   COALESCE(SUM(CASE WHEN due_date < ? THEN balance END), 0) overdue,
                   SUM(due_date IS NOT NULL AND due_date BETWEEN ? AND ?) soon_count,
                   COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN balance END), 0) soon,
                   COALESCE(SUM(CASE WHEN due_date IS NULL OR due_date >= ? THEN balance END), 0) b0,
                   SUM(due_date IS NULL OR due_date >= ?) n0,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN balance END), 0) b30,
                   SUM(DATEDIFF(?, due_date) BETWEEN 1 AND 30) n30,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN balance END), 0) b60,
                   SUM(DATEDIFF(?, due_date) BETWEEN 31 AND 60) n60,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN balance END), 0) b90,
                   SUM(DATEDIFF(?, due_date) BETWEEN 61 AND 90) n90,
                   COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) > 90 THEN balance END), 0) b91,
                   SUM(DATEDIFF(?, due_date) > 90) n91
              FROM (" . self::billBalances() . ") t
             WHERE balance > 0.005");
        $st->execute([$today, $today, $today, $soon, $today, $soon, $today, $today, $today, $today, $today, $today, $today, $today, $today, $today,
                      $companyId, $companyId, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'outstanding' => round((float)($r['outstanding'] ?? 0), 2), 'count' => (int)($r['open_count'] ?? 0),
            'overdue' => round((float)($r['overdue'] ?? 0), 2), 'overdue_count' => (int)($r['overdue_count'] ?? 0),
            'soon' => round((float)($r['soon'] ?? 0), 2), 'soon_count' => (int)($r['soon_count'] ?? 0),
            'aging' => self::buckets($r),
        ];
    }

    private static function buckets(array $r): array
    {
        $out = [];
        foreach (['0' => 'Not yet due', '30' => '1–30 days', '60' => '31–60 days', '90' => '61–90 days', '91' => 'Over 90 days'] as $k => $label) {
            $out[] = ['key' => 'b' . $k, 'label' => $label, 'value' => round((float)($r['b' . $k] ?? 0), 2), 'count' => (int)($r['n' . $k] ?? 0)];
        }
        return $out;
    }

    /** Income and expenses per month for 12 months, plus this month against last. Year-end closing journals are left out. */
    public static function profit(PDO $db, int $companyId, string $today): array
    {
        $t = new DateTimeImmutable($today);
        $start = $t->modify('first day of this month')->modify('-11 months');
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->modify("+$i months");
            $months[$m->format('Y-m')] = ['ym' => $m->format('Y-m'), 'label' => $m->format('M'), 'year' => (int)$m->format('Y'), 'income' => 0.0, 'expenses' => 0.0];
        }
        $st = $db->prepare("
            SELECT DATE_FORMAT(je.entry_date, '%Y-%m') ym,
                   COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' THEN jl.credit - jl.debit END), 0) inc,
                   COALESCE(SUM(CASE WHEN ga.account_type = 'expense' THEN jl.debit - jl.credit END), 0) exp
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_id
              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
               AND ga.account_type IN ('revenue','expense')
               AND (je.module IS NULL OR je.module <> 'year_end')
          GROUP BY ym");
        $st->execute([$companyId, $start->format('Y-m-d'), $t->format('Y-m-t')]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($months[$r['ym']])) continue;
            $months[$r['ym']]['income'] = round((float)$r['inc'], 2);
            $months[$r['ym']]['expenses'] = round((float)$r['exp'], 2);
        }
        $list = array_values($months);
        $cur = $list[11];
        $prev = $list[10];
        return [
            'months' => $list,
            'mtd' => round($cur['income'] - $cur['expenses'], 2),
            'prev' => round($prev['income'] - $prev['expenses'], 2),
            'income_mtd' => $cur['income'], 'expenses_mtd' => $cur['expenses'],
        ];
    }

    /** The VAT position: the period running now (estimated boxes), what is waiting to be filed, and the books' VAT accounts. */
    public static function vat(PDO $db, int $companyId, string $today): array
    {
        require_once __DIR__ . '/VatCalculator.php';
        $st = $db->prepare("SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
                              FROM journal_lines jl
                              JOIN journal_entries je ON je.id = jl.journal_id
                              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
                             WHERE je.company_id = ? AND je.status = 'posted' AND ga.account_subtype = 'vat_control'");
        $st->execute([$companyId]);
        $books = round((float)$st->fetchColumn(), 2);
        $periods = [];
        try {
            $st = $db->prepare("SELECT id, period_start, period_end, status, net_vat_cents FROM gl_vat_periods
                                 WHERE company_id = ? AND status IN ('open','prepared','adjusted') ORDER BY period_end LIMIT 6");
            $st->execute([$companyId]);
            $periods = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $periods = [];
        }
        $current = null;
        foreach ($periods as $p) {
            if ($p['period_start'] <= $today && $p['period_end'] >= $today) { $current = $p; break; }
        }
        $estimate = null;
        if ($current) {
            try {
                $map = new AccountsMap($db, $companyId);
                $boxes = VatCalculator::vat201Boxes($db, $companyId, $current['period_start'], $current['period_end'],
                    $map->code('finance_vat_output_account_id'), $map->code('finance_vat_input_account_id'),
                    VatCalculator::periodBasis($db, $companyId, $current));
                $estimate = ['output' => $boxes['box5_total_output_cents'] / 100, 'input' => $boxes['box9_total_input_cents'] / 100, 'net' => $boxes['box10_net_cents'] / 100];
            } catch (Throwable $e) {
                error_log('[finance/overview] vat estimate: ' . $e->getMessage());
            }
        }
        $toFile = array_values(array_filter($periods, fn($p) => $p['period_end'] < $today));
        return [
            'books' => $books,
            'current' => $current ? ['id' => (int)$current['id'], 'start' => $current['period_start'], 'end' => $current['period_end'], 'status' => $current['status'], 'estimate' => $estimate] : null,
            'to_file' => array_map(fn($p) => ['id' => (int)$p['id'], 'start' => $p['period_start'], 'end' => $p['period_end'], 'status' => $p['status'],
                                               'net' => $p['status'] === 'open' ? null : ((int)$p['net_vat_cents']) / 100, 'due' => self::vatDueDate($p['period_end'])], $toFile),
            'basis' => VatCalculator::companyBasis($db, $companyId),
        ];
    }

    /** eFiling: the last business day of the month after the period ends. */
    public static function vatDueDate(string $periodEnd): string
    {
        $d = (new DateTimeImmutable($periodEnd))->modify('first day of next month')->modify('last day of this month');
        while ((int)$d->format('N') >= 6) $d = $d->modify('-1 day');
        return $d->format('Y-m-d');
    }

    // --------------------------------------------------------------- panels

    private static function kpis(array $cash, ?array $ar, ?array $ap, array $pl, ?array $vat): array
    {
        $months = $pl['months'];
        $out = [[
            'key' => 'cash', 'label' => 'Cash in the bank', 'value' => $cash['books'], 'color' => 'var(--success)', 'href' => '#bank',
            'note' => $cash['statement'] !== null && abs($cash['statement'] - $cash['books']) >= 0.01 ? 'Statements say ' . Fin::money($cash['statement']) : 'per the books',
        ]];
        if ($ar) $out[] = ['key' => 'ar', 'label' => 'Customers owe you', 'value' => $ar['outstanding'], 'color' => 'var(--info)', 'href' => '#receivables',
            'note' => $ar['overdue'] > 0 ? Fin::money($ar['overdue']) . ' is late' : ($ar['count'] ? $ar['count'] . ' open, none late' : 'Nothing outstanding'), 'tone' => $ar['overdue'] > 0 ? 'warning' : null];
        if ($ap) $out[] = ['key' => 'ap', 'label' => 'You owe suppliers', 'value' => $ap['outstanding'], 'color' => 'var(--gold)', 'href' => '#payables',
            'note' => $ap['overdue'] > 0 ? Fin::money($ap['overdue']) . ' overdue' : ($ap['soon'] > 0 ? Fin::money($ap['soon']) . ' due soon' : 'Nothing due soon'), 'tone' => $ap['overdue'] > 0 ? 'danger' : null];
        $change = abs($pl['prev']) >= 1 ? (int)round((($pl['mtd'] - $pl['prev']) / abs($pl['prev'])) * 100) : null;
        $out[] = ['key' => 'profit', 'label' => 'Profit this month', 'value' => $pl['mtd'], 'color' => 'var(--accent-solid)', 'href' => '#reports?r=income_statement',
            'note' => 'Last month ' . Fin::money($pl['prev']), 'change' => $change, 'spark' => array_map(fn($m) => round($m['income'] - $m['expenses']), $months)];
        if ($vat) {
            $est = $vat['current']['estimate'] ?? null;
            $out[] = ['key' => 'vat', 'label' => $est && $est['net'] < 0 ? 'VAT refund (this period)' : 'VAT to pay (this period)',
                      'value' => $est ? abs($est['net']) : max(0, $vat['books']), 'color' => '#8a6fb8', 'href' => '#vat',
                      'note' => $vat['current'] ? 'Period ends ' . date('j M', strtotime($vat['current']['end'])) . ' · estimate' : ($vat['to_file'] ? count($vat['to_file']) . ' period(s) to file' : 'No open VAT period')];
        }
        return $out;
    }

    /** Bank money in and out per month (12 months back) and what is due in and out over the next weeks. */
    public static function cashflow(PDO $db, int $companyId, string $today, int $days, array $cash, array $mods): array
    {
        $t = new DateTimeImmutable($today);
        $start = $t->modify('first day of this month')->modify('-11 months');
        $hist = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->modify("+$i months");
            $hist[$m->format('Y-m')] = ['label' => $m->format('M'), 'year' => (int)$m->format('Y'), 'in' => 0.0, 'out' => 0.0, 'current' => $i === 11];
        }
        // Lines on bank / cash accounts, leaving out journals that only move money between them.
        $st = $db->prepare("
            SELECT DATE_FORMAT(je.entry_date, '%Y-%m') ym, COALESCE(SUM(jl.debit), 0) d, COALESCE(SUM(jl.credit), 0) c
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_id
              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
               AND ga.account_type = 'asset' AND ga.account_subtype IN ('bank','cash')
               AND EXISTS (SELECT 1 FROM journal_lines x
                             JOIN gl_accounts xa ON xa.company_id = je.company_id AND xa.account_code = x.account_code
                            WHERE x.journal_id = je.id AND NOT (xa.account_type = 'asset' AND xa.account_subtype IN ('bank','cash')))
          GROUP BY ym");
        $st->execute([$companyId, $start->format('Y-m-d'), $t->format('Y-m-t')]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($hist[$r['ym']])) continue;
            $hist[$r['ym']]['in'] = round((float)$r['d'], 2);
            $hist[$r['ym']]['out'] = round((float)$r['c'], 2);
        }

        // Forecast in weekly buckets: anything already late lands in the first week.
        $weeks = max(1, (int)ceil($days / 7));
        $buckets = [];
        for ($w = 0; $w < $weeks; $w++) {
            $from = $t->modify('+' . ($w * 7) . ' days');
            $to = $t->modify('+' . ($w * 7 + 6) . ' days');
            $buckets[] = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $from->format('j M'), 'in' => 0.0, 'out' => 0.0, 'items' => []];
        }
        $place = function (?string $due, float $amount, string $side, string $what) use (&$buckets, $today) {
            $due = $due ?: $today;
            foreach ($buckets as $i => $b) {
                if ($due <= $b['to']) {
                    $buckets[$i][$side] += $amount;
                    if (count($buckets[$i]['items']) < 40) $buckets[$i]['items'][] = [$side, $what, round($amount, 2), $due];
                    return;
                }
            }
        };
        $end = end($buckets)['to'];
        if (!empty($mods['receivables'])) {
            $st = $db->prepare("SELECT i.invoice_number, i.due_date, i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) amt, COALESCE(ca.name, '') who
                                  FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
                                 WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN " . self::AR_CLOSED . "
                                   AND i.balance_due > 0 AND (i.due_date IS NULL OR i.due_date <= ?)");
            $st->execute([$companyId, $end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $place($r['due_date'], (float)$r['amt'], 'in', trim($r['invoice_number'] . ' ' . $r['who']));
        }
        if (!empty($mods['payables'])) {
            $st = $db->prepare("SELECT t.vendor_invoice_number, t.due_date, t.balance, COALESCE(ca.name, '') who FROM (" . self::billBalances() . ") t
                                  LEFT JOIN crm_accounts ca ON ca.id = t.supplier_id
                                 WHERE t.balance > 0.005 AND (t.due_date IS NULL OR t.due_date <= ?)");
            $st->execute([$companyId, $companyId, $companyId, $end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $place($r['due_date'], (float)$r['balance'], 'out', trim($r['who'] . ' ' . $r['vendor_invoice_number']));
        }
        if (!empty($mods['vat'])) {
            try {
                // prepared returns, and filed ones not paid yet that are still to fall due
                $st = $db->prepare("SELECT period_end, status, net_vat_cents FROM gl_vat_periods WHERE company_id = ? AND status IN ('prepared','adjusted','filed') AND net_vat_cents > 0");
                $st->execute([$companyId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $due = self::vatDueDate($r['period_end']);
                    if ($r['status'] === 'filed' && $due < date('Y-m-d')) continue;
                    if ($due <= $end) $place($due, ((int)$r['net_vat_cents']) / 100, 'out', 'VAT to SARS · period ending ' . $r['period_end']);
                }
            } catch (Throwable $e) {
                // no VAT periods table
            }
        }
        $running = $cash['books'];
        foreach ($buckets as $i => $b) {
            $running += $b['in'] - $b['out'];
            $buckets[$i]['in'] = round($b['in'], 2);
            $buckets[$i]['out'] = round($b['out'], 2);
            $buckets[$i]['balance'] = round($running, 2);
        }
        return ['history' => array_values($hist), 'forecast' => $buckets, 'start_balance' => $cash['books'], 'days' => $days];
    }

    /** What needs doing, most urgent first. Each item: key, tone, icon, title, sub, href, amount?, count?, snoozable. */
    public static function attention(PDO $db, int $companyId, int $userId, string $role, string $today, array $cfg, array $prefs,
                                     array $cash, ?array $ar, ?array $ap, ?array $vat): array
    {
        $mods = $cfg['modules'];
        $th = $cfg['thresholds'];
        $items = [];
        $one = function (string $sql, array $args) use ($db) {
            try {
                $st = $db->prepare($sql);
                $st->execute($args);
                return $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                error_log('[finance/overview] attention: ' . $e->getMessage());
                return [];
            }
        };
        $writer = Fin::canWrite($role);

        // Books that do not balance (should never happen; shout if it does).
        $r = $one("SELECT COUNT(*) n FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id
                    WHERE je.company_id = ? AND je.status = 'posted' GROUP BY je.id
                   HAVING ABS(SUM(ROUND(jl.debit * 100)) - SUM(ROUND(jl.credit * 100))) > 0) x", [$companyId]);
        if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'unbalanced', 'tone' => 'danger', 'icon' => 'if-scale', 'title' => (int)$r['n'] . ' posted journal(s) do not balance',
            'sub' => 'Open the health checks to see which ones.', 'href' => '#close?view=health', 'count' => (int)$r['n'], 'snooze' => false];

        // Journals waiting.
        $r = $one("SELECT SUM(status IN ('draft','prepared')) drafts, SUM(status = 'approved') approved,
                          SUM(status IN ('draft','prepared') AND created_by <> ?) others
                     FROM journal_entries WHERE company_id = ? AND status IN ('draft','prepared','approved')
                      AND (module IS NULL OR module IN ('manual','fin',''))", [$userId, $companyId]);
        if ((int)($r['approved'] ?? 0) > 0) $items[] = ['key' => 'journals_approved', 'tone' => 'info', 'icon' => 'if-post', 'title' => (int)$r['approved'] . ' approved journal(s) to post',
            'sub' => 'They are not in the books until they are posted.', 'href' => '#journals?status=approved', 'count' => (int)$r['approved'], 'snooze' => true];
        if ((int)($r['drafts'] ?? 0) > 0) $items[] = ['key' => 'journals_draft', 'tone' => 'info', 'icon' => 'if-journal',
            'title' => (int)$r['drafts'] . ' draft journal(s)' . ($cfg['rules']['approval'] ? ' waiting for approval' : ' to finish'),
            'sub' => (int)($r['others'] ?? 0) > 0 ? (int)$r['others'] . ' prepared by someone else' : 'Drafts you started.', 'href' => '#journals?status=draft', 'count' => (int)$r['drafts'], 'snooze' => true];

        if ($mods['bank']) {
            $stale = (new DateTimeImmutable($today))->modify('-' . (int)$th['stale_bank_days'] . ' days')->format('Y-m-d');
            $r = $one("SELECT COUNT(*) n, COALESCE(SUM(ABS(amount_cents)), 0) c, MIN(tx_date) oldest FROM gl_bank_transactions
                        WHERE company_id = ? AND matched = 0 AND tx_date <= ?", [$companyId, $stale]);
            if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'bank_stale', 'tone' => 'warning', 'icon' => 'if-match', 'title' => (int)$r['n'] . ' bank line(s) not matched for over ' . (int)$th['stale_bank_days'] . ' days',
                'sub' => 'Oldest from ' . date('j M Y', strtotime($r['oldest'])) . '. Until they are matched, the books miss them.', 'href' => '#bank?status=unmatched', 'count' => (int)$r['n'], 'snooze' => true];
            $r = $one("SELECT COUNT(*) n, MIN(COALESCE(last_reconciled_date, '1900-01-01')) oldest FROM gl_bank_accounts
                        WHERE company_id = ? AND is_active = 1 AND (last_reconciled_date IS NULL OR last_reconciled_date < ?)",
                      [$companyId, (new DateTimeImmutable($today))->modify('first day of last month')->modify('-1 day')->format('Y-m-d')]);
            if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'bank_recon', 'tone' => 'warning', 'icon' => 'if-bank', 'title' => (int)$r['n'] . ' bank account(s) not reconciled to last month',
                'sub' => ($r['oldest'] ?? '') > '1900-01-01' ? 'Last reconciled ' . date('j M Y', strtotime($r['oldest'])) : 'Never reconciled', 'href' => '#bank', 'snooze' => true];
        }
        if ($th['low_cash'] > 0 && $cash['books'] < $th['low_cash']) $items[] = ['key' => 'low_cash', 'tone' => 'danger', 'icon' => 'if-cash',
            'title' => 'Cash is below ' . Fin::money($th['low_cash']), 'sub' => 'The books show ' . Fin::money($cash['books']) . ' in the bank.', 'href' => '#overview', 'snooze' => true];
        if ($ar && $ar['overdue_count'] > 0) $items[] = ['key' => 'ar_overdue', 'tone' => 'warning', 'icon' => 'if-in',
            'title' => $ar['overdue_count'] . ' late invoice(s) · ' . Fin::money($ar['overdue']), 'sub' => 'Send statements or reminders to get paid.', 'href' => '#receivables?status=overdue', 'amount' => $ar['overdue'], 'snooze' => true];
        if ($ap && $ap['overdue_count'] > 0) $items[] = ['key' => 'ap_overdue', 'tone' => 'danger', 'icon' => 'if-out',
            'title' => $ap['overdue_count'] . ' bill(s) past due · ' . Fin::money($ap['overdue']), 'sub' => 'Pay or agree new dates with the suppliers.', 'href' => '#payables?status=overdue', 'amount' => $ap['overdue'], 'snooze' => true];
        if ($ap && $ap['soon_count'] > 0) $items[] = ['key' => 'ap_soon', 'tone' => 'info', 'icon' => 'if-out',
            'title' => $ap['soon_count'] . ' bill(s) due in the next ' . (int)$th['due_soon_days'] . ' days · ' . Fin::money($ap['soon']), 'sub' => 'Plan the payment run.', 'href' => '#payables?status=due', 'amount' => $ap['soon'], 'snooze' => true];
        if ($mods['payables']) {
            $r = $one("SELECT COUNT(*) n FROM ap_bills WHERE company_id = ? AND status IN ('draft','review','approved')", [$companyId]);
            if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'ap_unposted', 'tone' => 'info', 'icon' => 'if-post', 'title' => (int)$r['n'] . ' supplier bill(s) not posted yet',
                'sub' => 'Drafts and bills from Receipts waiting to go into the books.', 'href' => '#payables?status=draft', 'count' => (int)$r['n'], 'snooze' => true];
        }
        if ($vat) {
            foreach ($vat['to_file'] as $p) {
                $late = $p['due'] < $today;
                $items[] = ['key' => 'vat_' . $p['id'], 'tone' => $late ? 'danger' : 'warning', 'icon' => 'if-vat',
                    'title' => ($p['status'] === 'open' ? 'Prepare and file' : 'File') . ' VAT for ' . date('j M', strtotime($p['start'])) . ' – ' . date('j M Y', strtotime($p['end'])),
                    'sub' => ($late ? 'Was due ' : 'Due ') . date('j M Y', strtotime($p['due'])) . ($p['net'] !== null ? ' · ' . ($p['net'] >= 0 ? 'to pay ' : 'refund ') . Fin::money(abs($p['net'])) : ''),
                    'href' => '#vat?id=' . $p['id'], 'snooze' => !$late];
            }
            if (!$vat['current']) $items[] = ['key' => 'vat_none', 'tone' => 'info', 'icon' => 'if-vat', 'title' => 'No VAT period covers today',
                'sub' => 'Create the next VAT period so sales and purchases land in a return.', 'href' => '#vat', 'snooze' => true];
        }
        if ($mods['assets']) {
            $lastMonth = (new DateTimeImmutable($today))->modify('first day of last month')->format('Y-m-01');
            $r = $one("SELECT (SELECT COUNT(*) FROM gl_fixed_assets WHERE company_id = ? AND status = 'active' AND purchase_date <= LAST_DAY(?)) assets,
                              (SELECT COUNT(*) FROM fa_depreciation_runs WHERE company_id = ? AND run_month = ? AND status = 'posted') runs",
                      [$companyId, $lastMonth, $companyId, $lastMonth]);
            if ((int)($r['assets'] ?? 0) > 0 && (int)($r['runs'] ?? 0) === 0) $items[] = ['key' => 'depreciation_' . substr($lastMonth, 0, 7), 'tone' => 'info', 'icon' => 'if-asset',
                'title' => 'Run depreciation for ' . date('F Y', strtotime($lastMonth)), 'sub' => (int)$r['assets'] . ' asset(s) in use.', 'href' => '#assets?run=' . substr($lastMonth, 0, 7), 'snooze' => true];
        }
        if ($mods['payroll']) {
            $r = $one("SELECT COUNT(*) n FROM pay_runs WHERE company_id = ? AND status IN ('approved','locked') AND (journal_id IS NULL OR journal_id = 0)", [$companyId]);
            if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'payroll_unposted', 'tone' => 'warning', 'icon' => 'if-payroll', 'title' => (int)$r['n'] . ' payroll run(s) approved but not in the books',
                'sub' => 'Post them so salaries, PAYE, UIF and SDL show in the ledger.', 'href' => '#payroll', 'count' => (int)$r['n'], 'snooze' => true];
        }
        // Month-end: last month should be locked once it is closed.
        $prevEnd = (new DateTimeImmutable($today))->modify('first day of this month')->modify('-1 day');
        $locked = Fin::ymd((string)($one("SELECT MAX(lock_date) d FROM gl_period_locks WHERE company_id = ? AND is_active = 1", [$companyId])['d'] ?? '')) ?: null;
        $twoBack = $prevEnd->modify('first day of this month')->modify('-1 day')->format('Y-m-d');
        if ((int)$prevEnd->format('j') && (int)(new DateTimeImmutable($today))->format('j') >= 10 && (!$locked || $locked < $twoBack)) {
            $items[] = ['key' => 'lock_' . $prevEnd->format('Y-m'), 'tone' => 'info', 'icon' => 'if-close', 'title' => 'Close the books for ' . $prevEnd->modify('first day of this month')->modify('-1 day')->format('F Y'),
                'sub' => $locked ? 'Locked through ' . date('j M Y', strtotime($locked)) . '. Locking stops back-dated changes.' : 'Nothing is locked yet. Locking stops back-dated changes.', 'href' => '#close', 'snooze' => true];
        }
        if ($th['large_journal'] > 0) {
            $r = $one("SELECT COUNT(*) n FROM journal_entries je WHERE je.company_id = ? AND je.status = 'posted' AND je.module = 'manual'
                         AND je.entry_date >= ? AND (SELECT SUM(debit) FROM journal_lines WHERE journal_id = je.id) >= ?",
                      [$companyId, (new DateTimeImmutable($today))->format('Y-m-01'), $th['large_journal']]);
            if ((int)($r['n'] ?? 0) > 0) $items[] = ['key' => 'large_' . substr($today, 0, 7), 'tone' => 'info', 'icon' => 'if-journal', 'title' => (int)$r['n'] . ' manual journal(s) over ' . Fin::money($th['large_journal']) . ' this month',
                'sub' => 'Worth a second look.', 'href' => '#journals?source=manual&min=' . $th['large_journal'], 'snooze' => true];
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn($a, $b) => ($order[$a['tone']] ?? 3) <=> ($order[$b['tone']] ?? 3));
        $snoozed = $prefs['snoozed'];
        $shown = array_values(array_filter($items, fn($i) => !isset($snoozed[$i['key']])));
        return ['items' => $shown, 'snoozed' => count($items) - count($shown), 'can_act' => $writer];
    }

    public static function topOverdueAr(PDO $db, int $companyId, string $today): array
    {
        $st = $db->prepare("SELECT i.id, i.invoice_number, i.customer_id, COALESCE(ca.name, '(deleted customer)') name, DATEDIFF(?, i.due_date) days,
                                   i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) balance
                              FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
                             WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN " . self::AR_CLOSED . "
                               AND i.balance_due > 0 AND i.due_date < ?
                          ORDER BY balance DESC LIMIT 5");
        $st->execute([$today, $companyId, $today]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'name' => (string)$r['name'], 'customer_id' => (int)$r['customer_id'],
                                    'days' => (int)$r['days'], 'balance' => round((float)$r['balance'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function topOverdueAp(PDO $db, int $companyId, string $today): array
    {
        $st = $db->prepare("SELECT t.id, t.vendor_invoice_number, t.supplier_id, COALESCE(ca.name, '(deleted supplier)') name, DATEDIFF(?, t.due_date) days, t.balance
                              FROM (" . self::billBalances() . ") t LEFT JOIN crm_accounts ca ON ca.id = t.supplier_id
                             WHERE t.balance > 0.005 AND t.due_date IS NOT NULL AND t.due_date < ?
                          ORDER BY t.balance DESC LIMIT 5");
        $st->execute([$today, $companyId, $companyId, $companyId, $today]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => (string)$r['vendor_invoice_number'], 'name' => (string)$r['name'], 'supplier_id' => (int)$r['supplier_id'],
                                    'days' => (int)$r['days'], 'balance' => round((float)$r['balance'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Where the money went: expense accounts this month (top 6 + other), with last month to compare. */
    public static function spend(PDO $db, int $companyId, string $today): array
    {
        $t = new DateTimeImmutable($today);
        $from = $t->format('Y-m-01');
        $st = $db->prepare("SELECT ga.account_code code, ga.account_name name, COALESCE(SUM(jl.debit - jl.credit), 0) v
                              FROM journal_lines jl
                              JOIN journal_entries je ON je.id = jl.journal_id
                              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
                             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                               AND ga.account_type = 'expense' AND (je.module IS NULL OR je.module <> 'year_end')
                          GROUP BY ga.account_code, ga.account_name HAVING v > 0 ORDER BY v DESC");
        $st->execute([$companyId, $from, $t->format('Y-m-t')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $label = 'this month';
        if (!$rows) {
            $p = $t->modify('first day of last month');
            $st->execute([$companyId, $p->format('Y-m-01'), $p->format('Y-m-t')]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $label = 'last month';
        }
        $out = [];
        $other = 0.0;
        foreach ($rows as $i => $r) {
            if ($i < 6) $out[] = ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'value' => round((float)$r['v'], 2)];
            else $other += (float)$r['v'];
        }
        if ($other > 0) $out[] = ['code' => '', 'name' => 'Everything else', 'value' => round($other, 2)];
        return ['label' => $label, 'rows' => $out, 'total' => round(array_sum(array_column($out, 'value')), 2)];
    }

    public static function banks(PDO $db, int $companyId): array
    {
        try {
            $st = $db->prepare("SELECT ba.id, ba.name, ba.bank_name, ba.account_no, ba.current_balance_cents, ba.last_reconciled_date, ga.account_code,
                                       (SELECT COUNT(*) FROM gl_bank_transactions t WHERE t.company_id = ba.company_id AND t.bank_account_id = ba.id AND t.matched = 0) unmatched
                                  FROM gl_bank_accounts ba LEFT JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                                 WHERE ba.company_id = ? AND ba.is_active = 1 ORDER BY ba.name");
            $st->execute([$companyId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['accounts' => []];
        }
        $gl = $db->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?");
        $out = [];
        foreach ($rows as $r) {
            $books = null;
            if ($r['account_code']) { $gl->execute([$companyId, $r['account_code']]); $books = round((float)$gl->fetchColumn(), 2); }
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'bank' => (string)($r['bank_name'] ?? ''), 'last4' => substr(preg_replace('/\D/', '', (string)$r['account_no']), -4),
                      'statement' => ((int)$r['current_balance_cents']) / 100, 'books' => $books, 'account_code' => (string)($r['account_code'] ?? ''),
                      'reconciled' => $r['last_reconciled_date'], 'unmatched' => (int)$r['unmatched']];
        }
        return ['accounts' => $out];
    }

    public static function recent(PDO $db, int $companyId, int $limit = 8): array
    {
        $st = $db->prepare("SELECT je.id, je.entry_date, je.reference, je.description, je.module, je.source_type, je.status, je.created_at,
                                   je.reverses_journal_id, je.reversed_by_journal_id,
                                   (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.journal_id = je.id) total
                              FROM journal_entries je WHERE je.company_id = ? ORDER BY je.created_at DESC, je.id DESC LIMIT " . max(1, min(50, $limit)));
        $st->execute([$companyId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'], 'description' => (string)$r['description'],
                                    'module' => (string)$r['module'], 'source' => (string)$r['source_type'], 'status' => FinJournalsStatus::of($r),
                                    'total' => round((float)$r['total'], 2), 'created_at' => $r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** One sentence per fact, for the greeting. */
    private static function brief(array $cash, ?array $ar, ?array $ap, array $pl, ?array $vat): array
    {
        $out = [];
        $out[] = ['text' => 'Cash in the books ' . Fin::money($cash['books']), 'href' => '#bank', 'tone' => $cash['books'] < 0 ? 'danger' : 'success'];
        if ($ar && $ar['overdue'] > 0) $out[] = ['text' => Fin::money($ar['overdue']) . ' late from customers', 'href' => '#receivables?status=overdue', 'tone' => 'warning'];
        if ($ap && $ap['soon'] > 0) $out[] = ['text' => Fin::money($ap['soon']) . ' to pay suppliers soon', 'href' => '#payables?status=due', 'tone' => 'gold'];
        if ($ap && $ap['overdue'] > 0) $out[] = ['text' => Fin::money($ap['overdue']) . ' overdue to suppliers', 'href' => '#payables?status=overdue', 'tone' => 'danger'];
        if ($vat && $vat['to_file']) $out[] = ['text' => count($vat['to_file']) . ' VAT period(s) to file', 'href' => '#vat', 'tone' => 'warning'];
        $out[] = ['text' => ($pl['mtd'] >= 0 ? 'Profit ' : 'Loss ') . Fin::money(abs($pl['mtd'])) . ' this month', 'href' => '#reports?r=income_statement', 'tone' => $pl['mtd'] >= 0 ? 'success' : 'danger'];
        return $out;
    }
}

/** How a journal reads to a person: draft, approved, posted, reversed or a reversal. */
final class FinJournalsStatus
{
    public static function of(array $r): string
    {
        $s = (string)($r['status'] ?? 'draft');
        if ($s === 'posted' && !empty($r['reversed_by_journal_id'])) return 'reversed';
        if ($s === 'posted' && !empty($r['reverses_journal_id'])) return 'reversal';
        return $s === 'prepared' ? 'draft' : $s;
    }
}
