<?php
// finances/lib/FinReports.php
//
// Every Finance report in one shape: a title, the columns, rows (groups,
// lines, totals), a few headline numbers and checks. The screen draws it,
// print uses the same page, and CSV is written from the same rows — so the
// three never disagree.
//
// The sums are the old reports' (trial balance, profit and loss, balance
// sheet, aging, VAT, fixed assets, wear and tear, capital goods VAT,
// sequence gaps, retention), now worked in integer cents. Three are changed
// on purpose and tested in tests/finance/test_fin_reports.php:
//   - Balance sheet: on the last day of a closed year the old sheet showed a
//     stray negative "retained earnings (prior periods, unclosed)" line next
//     to the closing journal's retained earnings. The current year's result
//     now includes that year's closing journal, so the line is gone; the
//     totals were and are the same.
//   - Cash flow: receivables, payables and inventory are found through the
//     same mapping the posting engine uses (the old report read only a pinned
//     setting and, without one, put the receivables movement under
//     investing). All other current assets and operating liabilities are
//     working capital, accumulated depreciation is the non-cash add-back, and
//     every bank / cash account is cash. The report proves itself: operating +
//     investing + financing equals the change in cash, and says so.
//   - Year-end closing journals are left out of the VAT revenue check.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';

final class FinReports
{
    public const CATALOG = [
        'statements' => ['label' => 'Financial statements', 'items' => [
            'income_statement' => ['Profit and loss', 'Income less expenses for a period, with the comparison you choose.', 'range'],
            'balance_sheet'    => ['Balance sheet', 'What the business owns and owes on a date.', 'date'],
            'cashflow'         => ['Cash flow statement', 'Where cash came from and went (indirect method), proven against the bank.', 'range'],
            'trial_balance'    => ['Trial balance', 'Every account with its debit or credit balance on a date.', 'date'],
        ]],
        'parties' => ['label' => 'Customers and suppliers', 'items' => [
            'ar_aging'  => ['Aged receivables', 'What customers owe, by how late it is.', 'date', 'receivables'],
            'ap_aging'  => ['Aged payables', 'What you owe suppliers, by how late it is.', 'date', 'payables'],
            'tieouts'   => ['Control account tie-outs', 'Receivables, payables, bank and VAT: the detail against the ledger.', 'date'],
        ]],
        'tax' => ['label' => 'Tax', 'items' => [
            'vat_summary'       => ['VAT by month', 'Output, input and net VAT per month on your basis.', 'range', 'vat'],
            'vat_recon'         => ['VAT reconciliation', 'Each return against the ledger and what was filed.', 'none', 'vat'],
            'capital_goods_vat' => ['Capital goods VAT', 'Input VAT claimed on capital purchases (Box 7).', 'range', 'vat'],
            'wear_and_tear'     => ['Wear-and-tear register', 'SARS tax allowances against book depreciation.', 'none', 'assets'],
        ]],
        'assets' => ['label' => 'Assets and planning', 'items' => [
            'fa_schedule'      => ['Fixed asset schedule', 'Cost, depreciation and book value per asset.', 'date', 'assets'],
            'budget_vs_actual' => ['Budget against actual', 'This financial year so far, per account.', 'none', 'budgets'],
        ]],
        'compliance' => ['label' => 'Compliance', 'items' => [
            'sequence_gaps' => ['Numbering gaps', 'Missing or repeated invoice and credit note numbers (SARS).', 'year'],
            'retention'     => ['Record retention', 'Records per year and how long SARS needs them kept.', 'none'],
        ]],
    ];

    /** The catalog with the parts the company switched off left out. */
    public static function catalog(array $modules): array
    {
        $out = [];
        foreach (self::CATALOG as $g => $grp) {
            $items = [];
            foreach ($grp['items'] as $k => $it) {
                if (isset($it[3]) && empty($modules[$it[3]])) continue;
                $items[] = ['key' => $k, 'label' => $it[0], 'about' => $it[1], 'params' => $it[2]];
            }
            if ($items) $out[] = ['key' => $g, 'label' => $grp['label'], 'items' => $items];
        }
        return $out;
    }

    public static function run(PDO $db, int $companyId, string $key, array $p, array $modules = []): array
    {
        $known = [];
        foreach (self::catalog($modules) as $g) foreach ($g['items'] as $it) $known[$it['key']] = true;
        if (!isset($known[$key])) throw new FinUserError('That report was not found.');
        $today = date('Y-m-d');
        $asOf = Fin::ymd($p['as_of'] ?? '') ?: $today;
        $to = Fin::ymd($p['to'] ?? '') ?: $today;
        $from = Fin::ymd($p['from'] ?? '') ?: Fin::fyStart($db, $companyId, $to);
        if ($from > $to) throw new FinUserError('The start date is after the end date.');
        $r = match ($key) {
            'trial_balance' => self::trialBalance($db, $companyId, $asOf),
            'income_statement' => self::incomeStatement($db, $companyId, $from, $to, (string)($p['compare'] ?? 'none')),
            'balance_sheet' => self::balanceSheet($db, $companyId, $asOf),
            'cashflow' => self::cashflow($db, $companyId, $from, $to),
            'ar_aging' => self::aging($db, $companyId, 'ar', $asOf),
            'ap_aging' => self::aging($db, $companyId, 'ap', $asOf),
            'tieouts' => self::tieouts($db, $companyId, $asOf),
            'vat_summary' => self::vatSummary($db, $companyId, $from, $to),
            'vat_recon' => self::vatRecon($db, $companyId),
            'capital_goods_vat' => self::capitalGoodsVat($db, $companyId, $from, $to),
            'wear_and_tear' => self::wearAndTear($db, $companyId),
            'fa_schedule' => self::faSchedule($db, $companyId, $asOf),
            'budget_vs_actual' => self::budgetVsActual($db, $companyId, (int)($p['fy'] ?? 0)),
            'sequence_gaps' => self::sequenceGaps($db, $companyId, Fin::clampInt($p['year'] ?? date('Y'), 2000, 2100)),
            'retention' => self::retention($db, $companyId),
        };
        return $r + ['key' => $key, 'company' => Fin::companyName($db, $companyId), 'generated' => date('Y-m-d H:i'),
                     'params' => ['as_of' => $asOf, 'from' => $from, 'to' => $to]];
    }

    // ------------------------------------------------------------ helpers

    private static function col(string $key, string $label, bool $money = false, string $align = ''): array
    {
        return ['key' => $key, 'label' => $label, 'money' => $money, 'align' => $money ? 'r' : $align];
    }

    /** Posted movement per account code (debit − credit, cents) for a date window. */
    private static function movements(PDO $db, int $companyId, ?string $from, string $to, bool $withYearEnd = true): array
    {
        $sql = "SELECT jl.account_code, ROUND(SUM(jl.debit) * 100) d, ROUND(SUM(jl.credit) * 100) c FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                 WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date <= ?" . ($from ? ' AND je.entry_date >= ?' : '') . ($withYearEnd ? '' : " AND COALESCE(je.module,'') <> 'year_end'") . "
              GROUP BY jl.account_code";
        $st = $db->prepare($sql);
        $st->execute($from ? [$companyId, $to, $from] : [$companyId, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['account_code']] = ['d' => (int)$r['d'], 'c' => (int)$r['c']];
        return $out;
    }

    private static function accounts(PDO $db, int $companyId, array $types): array
    {
        $st = $db->prepare("SELECT account_id, account_code, account_name, account_type, COALESCE(account_subtype, '') account_subtype, is_active FROM gl_accounts
                             WHERE company_id = ? AND account_type IN (" . implode(',', array_fill(0, count($types), '?')) . ") ORDER BY account_code");
        $st->execute(array_merge([$companyId], $types));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function r(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private static function line(string $label, array $cells, array $extra = []): array
    {
        return ['label' => $label, 'cells' => $cells] + $extra;
    }

    // ----------------------------------------------------- trial balance

    public static function trialBalance(PDO $db, int $companyId, string $asOf): array
    {
        $mv = self::movements($db, $companyId, null, $asOf);
        $rows = []; $td = 0; $tc = 0;
        foreach (self::accounts($db, $companyId, ['asset', 'liability', 'equity', 'revenue', 'expense']) as $a) {
            $m = $mv[$a['account_code']] ?? null;
            if (!$m) continue;
            $bal = $m['d'] - $m['c'];
            if ($bal === 0 && !$m['d']) continue;
            $dr = max(0, $bal); $cr = max(0, -$bal);
            $td += $dr; $tc += $cr;
            $rows[] = self::line($a['account_code'] . ' · ' . $a['account_name'], ['type' => ucfirst($a['account_type']), 'debit' => $dr ? self::r($dr) : null, 'credit' => $cr ? self::r($cr) : null],
                                 ['link' => '#ledger?account=' . rawurlencode($a['account_code']) . '&to=' . $asOf]);
        }
        $rows[] = self::line('Total', ['type' => '', 'debit' => self::r($td), 'credit' => self::r($tc)], ['kind' => 'grand']);
        return ['title' => 'Trial balance', 'subtitle' => 'As at ' . $asOf,
                'columns' => [self::col('type', 'Type'), self::col('debit', 'Debit', true), self::col('credit', 'Credit', true)], 'rows' => $rows,
                'checks' => [['label' => 'Debits equal credits', 'ok' => $td === $tc, 'detail' => $td === $tc ? 'In balance' : 'Out by ' . Fin::money(abs($td - $tc) / 100)]]];
    }

    // --------------------------------------------------- profit and loss

    private const OTHER_INCOME = ['other_income', 'interest_income', 'dividend_income', 'forex_gain'];

    /** P&L sections for a window (cents, natural sign), year-end closing left out. */
    private static function plSections(PDO $db, int $companyId, string $from, string $to): array
    {
        $mv = self::movements($db, $companyId, $from, $to, false);
        $s = ['revenue' => [], 'cost_of_sales' => [], 'operating' => [], 'other_income' => [], 'finance' => [], 'tax' => []];
        foreach (self::accounts($db, $companyId, ['revenue', 'expense']) as $a) {
            $m = $mv[$a['account_code']] ?? null;
            if (!$m) continue;
            $v = $a['account_type'] === 'revenue' ? $m['c'] - $m['d'] : $m['d'] - $m['c'];
            if ($v === 0) continue;
            $sub = $a['account_subtype'];
            $k = $a['account_type'] === 'revenue' ? (in_array($sub, self::OTHER_INCOME, true) ? 'other_income' : 'revenue')
                : ($sub === 'cost_of_sales' ? 'cost_of_sales' : ($sub === 'finance_cost' ? 'finance' : ($sub === 'tax_expense' ? 'tax' : 'operating')));
            $s[$k][$a['account_code']] = ['name' => $a['account_name'], 'v' => $v];
        }
        return $s;
    }

    public static function incomeStatement(PDO $db, int $companyId, string $from, string $to, string $compare): array
    {
        $cur = self::plSections($db, $companyId, $from, $to);
        $cmp = null; $cmpLabel = '';
        if (in_array($compare, ['prior_period', 'prior_year'], true)) {
            $f = new DateTimeImmutable($from); $t = new DateTimeImmutable($to);
            if ($compare === 'prior_year') { $cf = $f->modify('-1 year'); $ct = $t->modify('-1 year'); }
            else { $days = (int)$f->diff($t)->days + 1; $ct = $f->modify('-1 day'); $cf = $ct->modify('-' . ($days - 1) . ' days'); }
            $cmp = self::plSections($db, $companyId, $cf->format('Y-m-d'), $ct->format('Y-m-d'));
            $cmpLabel = $cf->format('j M Y') . ' – ' . $ct->format('j M Y');
        }
        $sum = fn(?array $s, string $k) => $s ? array_sum(array_column($s[$k], 'v')) : 0;
        $rows = [];
        $cells = function (int $v, ?int $c) use ($cmp) {
            $o = ['amount' => self::r($v)];
            if ($cmp) { $o['compare'] = self::r((int)$c); $o['change'] = $c ? round(($v - $c) / abs($c) * 100, 1) : null; }
            return $o;
        };
        $section = function (string $title, string $k, string $totalLabel) use (&$rows, $cur, $cmp, $cells, $sum, $from, $to) {
            if (!$cur[$k] && (!$cmp || !$cmp[$k])) return;
            $rows[] = self::line($title, [], ['kind' => 'group']);
            $codes = array_unique(array_merge(array_keys($cur[$k]), $cmp ? array_keys($cmp[$k]) : []));
            sort($codes);
            foreach ($codes as $code) {
                $name = $cur[$k][$code]['name'] ?? $cmp[$k][$code]['name'];
                $rows[] = self::line($code . ' · ' . $name, $cells((int)($cur[$k][$code]['v'] ?? 0), $cmp ? (int)($cmp[$k][$code]['v'] ?? 0) : null),
                                     ['level' => 1, 'link' => '#ledger?account=' . rawurlencode($code) . '&from=' . $from . '&to=' . $to]);
            }
            $rows[] = self::line($totalLabel, $cells($sum($cur, $k), $cmp ? $sum($cmp, $k) : null), ['kind' => 'total']);
        };
        $calc = function (?array $s) use ($sum) {
            if (!$s) return null;
            $gp = $sum($s, 'revenue') - $sum($s, 'cost_of_sales');
            $op = $gp - $sum($s, 'operating');
            $pbt = $op + $sum($s, 'other_income') - $sum($s, 'finance');
            return ['gp' => $gp, 'op' => $op, 'pbt' => $pbt, 'net' => $pbt - $sum($s, 'tax')];
        };
        $a = $calc($cur); $b = $calc($cmp);
        $section('Revenue', 'revenue', 'Total revenue');
        $section('Cost of sales', 'cost_of_sales', 'Total cost of sales');
        $rows[] = self::line('Gross profit', $cells($a['gp'], $b['gp'] ?? null), ['kind' => 'total']);
        $section('Operating expenses', 'operating', 'Total operating expenses');
        $rows[] = self::line('Operating profit', $cells($a['op'], $b['op'] ?? null), ['kind' => 'total']);
        $section('Other income', 'other_income', 'Total other income');
        $section('Finance costs', 'finance', 'Total finance costs');
        $rows[] = self::line('Profit before tax', $cells($a['pbt'], $b['pbt'] ?? null), ['kind' => 'total']);
        $section('Income tax', 'tax', 'Total tax');
        $rows[] = self::line($a['net'] < 0 ? 'Net loss' : 'Net profit', $cells($a['net'], $b['net'] ?? null), ['kind' => 'grand']);
        $cols = [self::col('amount', $from . ' – ' . $to, true)];
        if ($cmp) { $cols[] = self::col('compare', $cmpLabel, true); $cols[] = ['key' => 'change', 'label' => 'Change %', 'money' => false, 'align' => 'r', 'pct' => true]; }
        $rev = $sum($cur, 'revenue');
        return ['title' => 'Profit and loss', 'subtitle' => $from . ' to ' . $to, 'columns' => $cols, 'rows' => $rows,
                'summary' => [['label' => 'Revenue', 'value' => self::r($rev)], ['label' => 'Gross margin', 'value' => $rev ? round($a['gp'] / $rev * 100, 1) : null, 'pct' => true],
                              ['label' => $a['net'] < 0 ? 'Net loss' : 'Net profit', 'value' => self::r($a['net'])]]];
    }

    // ------------------------------------------------------ balance sheet

    private const NONCURRENT_ASSET = ['fixed_asset', 'accumulated_depreciation', 'intangible_asset', 'investment', 'non_current_asset'];
    private const NONCURRENT_LIAB = ['loan', 'non_current_liability'];

    public static function balanceSheet(PDO $db, int $companyId, string $asOf): array
    {
        $mv = self::movements($db, $companyId, null, $asOf);
        $sec = ['ca' => [], 'nca' => [], 'cl' => [], 'ncl' => [], 'eq' => []];
        foreach (self::accounts($db, $companyId, ['asset', 'liability', 'equity']) as $a) {
            $m = $mv[$a['account_code']] ?? null;
            if (!$m) continue;
            $v = $a['account_type'] === 'asset' ? $m['d'] - $m['c'] : $m['c'] - $m['d'];
            if ($v === 0) continue;
            $k = $a['account_type'] === 'asset' ? (in_array($a['account_subtype'], self::NONCURRENT_ASSET, true) ? 'nca' : 'ca')
                : ($a['account_type'] === 'liability' ? (in_array($a['account_subtype'], self::NONCURRENT_LIAB, true) ? 'ncl' : 'cl') : 'eq');
            $sec[$k][] = ['code' => $a['account_code'], 'name' => $a['account_name'], 'v' => $v];
        }
        // P&L not yet closed into retained earnings: all of it to date, split into this financial year and before.
        // This year's figure includes this year's closing journal (if posted), so a closed year shows no stray line.
        $fy = Fin::fyStart($db, $companyId, $asOf);
        $pl = function (?string $from) use ($db, $companyId, $asOf) {
            $st = $db->prepare("SELECT ROUND(SUM(jl.credit - jl.debit) * 100)
                                  FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                                 WHERE je.company_id = ? AND je.status = 'posted' AND ga.account_type IN ('revenue','expense') AND je.entry_date <= ?" . ($from ? ' AND je.entry_date >= ?' : ''));
            $st->execute($from ? [$companyId, $asOf, $from] : [$companyId, $asOf]);
            return (int)$st->fetchColumn();
        };
        $all = $pl(null);
        $year = $pl($fy);
        $prior = $all - $year;
        if ($prior !== 0) $sec['eq'][] = ['code' => '', 'name' => 'Retained earnings (earlier years not closed)', 'v' => $prior];
        if ($year !== 0) $sec['eq'][] = ['code' => '', 'name' => 'Profit for this financial year', 'v' => $year];
        $rows = [];
        $t = [];
        $put = function (string $title, string $k) use (&$rows, &$t, $sec, $asOf) {
            $t[$k] = array_sum(array_column($sec[$k], 'v'));
            if (!$sec[$k]) return;
            $rows[] = self::line($title, [], ['kind' => 'group']);
            foreach ($sec[$k] as $l) $rows[] = self::line(($l['code'] ? $l['code'] . ' · ' : '') . $l['name'], ['amount' => self::r($l['v'])], ['level' => 1] + ($l['code'] ? ['link' => '#ledger?account=' . rawurlencode($l['code']) . '&to=' . $asOf] : []));
            $rows[] = self::line('Total ' . strtolower($title), ['amount' => self::r($t[$k])], ['kind' => 'total']);
        };
        $put('Current assets', 'ca');
        $put('Non-current assets', 'nca');
        $assets = $t['ca'] + $t['nca'];
        $rows[] = self::line('Total assets', ['amount' => self::r($assets)], ['kind' => 'grand']);
        $put('Current liabilities', 'cl');
        $put('Non-current liabilities', 'ncl');
        $put('Equity', 'eq');
        $le = $t['cl'] + $t['ncl'] + $t['eq'];
        $rows[] = self::line('Total liabilities and equity', ['amount' => self::r($le)], ['kind' => 'grand']);
        return ['title' => 'Balance sheet', 'subtitle' => 'As at ' . $asOf, 'columns' => [self::col('amount', $asOf, true)], 'rows' => $rows,
                'summary' => [['label' => 'Total assets', 'value' => self::r($assets)], ['label' => 'Liabilities', 'value' => self::r($t['cl'] + $t['ncl'])], ['label' => 'Equity', 'value' => self::r($t['eq'])],
                              ['label' => 'Current ratio', 'value' => $t['cl'] ? round($t['ca'] / $t['cl'], 2) : null, 'plain' => true]],
                'checks' => [['label' => 'Assets equal liabilities plus equity', 'ok' => $assets === $le, 'detail' => $assets === $le ? 'Balanced' : 'Out by ' . Fin::money(abs($assets - $le) / 100)]]];
    }

    // ---------------------------------------------------------- cash flow

    private const WC_ASSET = ['accounts_receivable', 'inventory', 'prepayment', 'current_asset'];
    private const OP_LIAB = ['current_liability', 'vat_control', 'paye_liability', 'uif_liability', 'sdl_liability', 'dividends_tax_payable', 'provisional_tax', 'accruals', 'accounts_payable', 'income_tax_payable'];

    public static function cashflow(PDO $db, int $companyId, string $from, string $to): array
    {
        $map = new AccountsMap($db, $companyId);
        $role = [];
        foreach (['finance_ar_account_id' => 'wc', 'finance_inventory_account_id' => 'wc', 'finance_ap_account_id' => 'op_liab', 'finance_bank_account_id' => 'cash'] as $k => $r) {
            try { if ($c = $map->code($k)) $role[$c] = $r; } catch (Throwable $e) { /* not mapped */ }
        }
        $st = $db->prepare("SELECT ga.account_code FROM gl_bank_accounts ba JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id WHERE ba.company_id = ?");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $role[$c] = 'cash';
        $before = self::movements($db, $companyId, null, (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'), false);
        $during = self::movements($db, $companyId, $from, $to, false);
        $net = 0;
        $sec = ['noncash' => [], 'wc' => [], 'investing' => [], 'financing' => []];
        $cash0 = 0; $cash1 = 0;
        foreach (self::accounts($db, $companyId, ['asset', 'liability', 'equity', 'revenue', 'expense']) as $a) {
            $code = $a['account_code'];
            $d = $during[$code] ?? ['d' => 0, 'c' => 0];
            $mvDr = $d['d'] - $d['c'];   // debit-positive movement in the window
            $t = $a['account_type'];
            if ($t === 'revenue' || $t === 'expense') { $net += -$mvDr; continue; }
            $sub = $a['account_subtype'];
            $r = $role[$code] ?? null;
            if ($r === 'cash' || ($t === 'asset' && in_array($sub, ['bank', 'cash'], true))) {
                $b = $before[$code] ?? ['d' => 0, 'c' => 0];
                $cash0 += $b['d'] - $b['c'];
                $cash1 += $b['d'] - $b['c'] + $mvDr;
                continue;
            }
            if ($mvDr === 0) continue;
            $cashEffect = -$mvDr;  // a debit (asset up / liability down) uses cash
            if ($t === 'asset') {
                if ($sub === 'accumulated_depreciation' || preg_match('/accum.*dep/i', $a['account_name'])) $k = 'noncash';
                elseif ($r === 'wc' || in_array($sub, self::WC_ASSET, true)) $k = 'wc';
                else $k = 'investing';
            } elseif ($t === 'liability') {
                $k = ($r === 'op_liab' || in_array($sub, self::OP_LIAB, true)) ? 'wc' : 'financing';
            } else {
                $k = 'financing';
            }
            $sec[$k][] = ['code' => $code, 'name' => $a['account_name'], 'v' => $cashEffect];
        }
        $sum = fn($k) => array_sum(array_column($sec[$k], 'v'));
        $operating = $net + $sum('noncash') + $sum('wc');
        $investing = $sum('investing');
        $financing = $sum('financing');
        $change = $operating + $investing + $financing;
        $rows = [];
        $lines = function (string $k, string $label) use (&$rows, $sec, $from, $to) {
            foreach ($sec[$k] as $l) $rows[] = self::line($label . $l['code'] . ' · ' . $l['name'], ['amount' => self::r($l['v'])], ['level' => 1, 'link' => '#ledger?account=' . rawurlencode($l['code']) . '&from=' . $from . '&to=' . $to]);
        };
        $rows[] = self::line('Operating activities', [], ['kind' => 'group']);
        $rows[] = self::line('Net profit for the period', ['amount' => self::r($net)], ['level' => 1]);
        $lines('noncash', 'Depreciation · ');
        $lines('wc', '');
        $rows[] = self::line('Cash from operating activities', ['amount' => self::r($operating)], ['kind' => 'total']);
        $rows[] = self::line('Investing activities', [], ['kind' => 'group']);
        $lines('investing', '');
        $rows[] = self::line('Cash from investing activities', ['amount' => self::r($investing)], ['kind' => 'total']);
        $rows[] = self::line('Financing activities', [], ['kind' => 'group']);
        $lines('financing', '');
        $rows[] = self::line('Cash from financing activities', ['amount' => self::r($financing)], ['kind' => 'total']);
        $rows[] = self::line('Net change in cash', ['amount' => self::r($change)], ['kind' => 'grand']);
        $rows[] = self::line('Cash at the start', ['amount' => self::r($cash0)], ['level' => 1]);
        $rows[] = self::line('Cash at the end', ['amount' => self::r($cash1)], ['level' => 1]);
        $ok = $change === $cash1 - $cash0;
        return ['title' => 'Cash flow statement', 'subtitle' => $from . ' to ' . $to . ' · indirect method', 'columns' => [self::col('amount', 'Amount', true)], 'rows' => $rows,
                'summary' => [['label' => 'Operating', 'value' => self::r($operating)], ['label' => 'Investing', 'value' => self::r($investing)], ['label' => 'Financing', 'value' => self::r($financing)], ['label' => 'Change in cash', 'value' => self::r($change)]],
                'checks' => [['label' => 'Agrees with the bank and cash accounts', 'ok' => $ok, 'detail' => $ok ? 'Cash moved ' . Fin::money(($cash1 - $cash0) / 100) : 'Differs by ' . Fin::money(abs($change - ($cash1 - $cash0)) / 100)]]];
    }

    // ------------------------------------------------------------- aging

    public static function aging(PDO $db, int $companyId, string $kind, string $asOf): array
    {
        if ($kind === 'ar') { require_once __DIR__ . '/FinReceivables.php'; $a = FinReceivables::aging($db, $companyId, $asOf); }
        else { require_once __DIR__ . '/FinPayables.php'; $a = FinPayables::aging($db, $companyId, $asOf); }
        $cols = [self::col('docs', $kind === 'ar' ? 'Invoices' : 'Bills', false, 'r'), self::col('current', 'Current', true), self::col('d30', '1–30', true), self::col('d60', '31–60', true), self::col('d90', '61–90', true), self::col('d90p', '90+', true), self::col('total', 'Total', true)];
        $rows = array_map(fn($r) => self::line($r['name'], ['docs' => $r['docs'], 'current' => $r['current'], 'd30' => $r['d30'], 'd60' => $r['d60'], 'd90' => $r['d90'], 'd90p' => $r['d90p'], 'total' => $r['total']],
                                               ['link' => '#' . ($kind === 'ar' ? 'receivables?view=statement&customer=' : 'payables?view=statement&supplier=') . $r['id'] . '&to=' . $asOf]), $a['rows']);
        $t = $a['totals'];
        $rows[] = self::line('Total', ['docs' => '', 'current' => $t['current'], 'd30' => $t['d30'], 'd60' => $t['d60'], 'd90' => $t['d90'], 'd90p' => $t['d90p'], 'total' => $t['total']], ['kind' => 'grand']);
        $late = $t['total'] - $t['current'];
        return ['title' => $kind === 'ar' ? 'Aged receivables' : 'Aged payables', 'subtitle' => 'As at ' . $asOf, 'columns' => $cols, 'rows' => $rows,
                'summary' => [['label' => 'Outstanding', 'value' => $t['total']], ['label' => 'Late', 'value' => round($late, 2)], ['label' => 'Late share', 'value' => $t['total'] ? round($late / $t['total'] * 100, 1) : null, 'pct' => true]]];
    }

    // ----------------------------------------------------------- tie-outs

    public static function tieouts(PDO $db, int $companyId, string $asOf): array
    {
        require_once __DIR__ . '/Tieout.php';
        $t = new Tieout($db, $companyId);
        $rows = []; $checks = [];
        $add = function (string $label, float $gl, float $sub, string $what, string $link) use (&$rows, &$checks) {
            $diff = round($gl - $sub, 2);
            $rows[] = self::line($label, ['ledger' => round($gl, 2), 'detail' => round($sub, 2), 'difference' => $diff, 'what' => $what], ['link' => $link, 'flag' => abs($diff) >= 0.005]);
            $checks[] = ['label' => $label, 'ok' => abs($diff) < 0.005, 'detail' => abs($diff) < 0.005 ? 'Ties out' : 'Differs by ' . Fin::money(abs($diff))];
        };
        $add('Receivables', $t->glBalance('AR', $asOf), $t->arSubledger($asOf), 'open invoices less receipts and credit notes', '#receivables?view=aging&as_of=' . $asOf);
        $add('Payables', $t->glBalance('AP', $asOf), $t->apSubledger($asOf), 'posted bills less payments and supplier credits', '#payables?view=aging&as_of=' . $asOf);
        // bank: each bank account's books against its last statement balance
        $st = $db->prepare("SELECT ba.id, ba.name, ba.current_balance_cents, ga.account_code FROM gl_bank_accounts ba JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                             WHERE ba.company_id = ? AND ba.is_active = 1 ORDER BY ba.name");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $q = $db->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date <= ?");
            $q->execute([$companyId, $b['account_code'], $asOf]);
            $books = round((float)$q->fetchColumn(), 2);
            $q = $db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND matched = 0 AND tx_date <= ?");
            try { $q->execute([$companyId, (int)$b['id'], $asOf]); $unmatched = (int)$q->fetchColumn() / 100; } catch (Throwable $e) { $unmatched = 0; }
            $rows[] = self::line('Bank · ' . $b['name'], ['ledger' => $books, 'detail' => round((int)$b['current_balance_cents'] / 100 - $unmatched, 2), 'difference' => round($books - ((int)$b['current_balance_cents'] / 100 - $unmatched), 2),
                                 'what' => 'statement balance less lines still to match (today)'], ['link' => '#bank?account=' . $b['id']]);
        }
        $cols = [self::col('ledger', 'Ledger', true), self::col('detail', 'Detail', true), self::col('difference', 'Difference', true), self::col('what', 'Detail is')];
        return ['title' => 'Control account tie-outs', 'subtitle' => 'As at ' . $asOf, 'columns' => $cols, 'rows' => $rows, 'checks' => $checks,
                'notes' => ['A bank difference usually means postings in the books that are not on a statement yet (or the reverse); the reconciliation in Bank shows them line by line.']];
    }

    // ---------------------------------------------------------------- VAT

    public static function vatSummary(PDO $db, int $companyId, string $from, string $to): array
    {
        require_once __DIR__ . '/FinVat.php';
        $basis = VatCalculator::companyBasis($db, $companyId);
        $rows = []; $T = ['out' => 0, 'in' => 0, 'net' => 0, 'base' => 0];
        $m = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new DateTimeImmutable($to);
        for ($i = 0; $i < 36 && $m <= $end; $i++, $m = $m->modify('+1 month')) {
            $s = max($from, $m->format('Y-m-d'));
            $e = min($to, $m->modify('last day of this month')->format('Y-m-d'));
            $b = FinVat::boxes($db, $companyId, $s, $e, $basis);
            $T['out'] += $b['box5_total_output_cents']; $T['in'] += $b['box9_total_input_cents']; $T['net'] += $b['box10_net_cents']; $T['base'] += $b['output_standard_base_cents'];
            $rows[] = self::line($m->format('M Y'), ['base' => self::r($b['output_standard_base_cents']), 'output' => self::r($b['box5_total_output_cents']), 'input' => self::r($b['box9_total_input_cents']), 'net' => self::r($b['box10_net_cents'])]);
        }
        $rows[] = self::line('Total', ['base' => self::r($T['base']), 'output' => self::r($T['out']), 'input' => self::r($T['in']), 'net' => self::r($T['net'])], ['kind' => 'grand']);
        return ['title' => 'VAT by month', 'subtitle' => $from . ' to ' . $to . ' · ' . $basis . ' basis',
                'columns' => [self::col('base', 'Standard-rated sales', true), self::col('output', 'Output tax', true), self::col('input', 'Input tax', true), self::col('net', 'Net', true)], 'rows' => $rows];
    }

    public static function vatRecon(PDO $db, int $companyId): array
    {
        require_once __DIR__ . '/FinVat.php';
        [$out, $in, $ctl] = FinVat::codes($db, $companyId);
        $st = $db->prepare("SELECT * FROM gl_vat_periods WHERE company_id = ? ORDER BY period_end DESC LIMIT 36");
        $st->execute([$companyId]);
        $rows = []; $checks = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $basis = VatCalculator::periodBasis($db, $companyId, $p);
            $b = VatCalculator::vat201Boxes($db, $companyId, $p['period_start'], $p['period_end'], $out, $in, $basis);
            $q = $db->prepare("SELECT ROUND(SUM(CASE WHEN jl.account_code = ? THEN jl.credit - jl.debit ELSE 0 END) * 100), ROUND(SUM(CASE WHEN jl.account_code = ? THEN jl.debit - jl.credit ELSE 0 END) * 100)
                                 FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                                WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ? AND COALESCE(je.module,'') NOT IN ('vat_settle','bad_debt') AND jl.account_code IN (?, ?)");
            $q->execute([$out, $in, $companyId, $p['period_start'], $p['period_end'], $out, $in]);
            [$glOut, $glIn] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
            $filed = in_array($p['status'], ['filed', 'paid'], true);
            $label = $p['period_start'] . ' – ' . $p['period_end'];
            $vsFiled = $filed ? $b['box10_net_cents'] - (int)$p['net_vat_cents'] : 0;
            $rows[] = self::line($label, ['status' => $p['status'], 'gl_out' => self::r($glOut), 'gl_in' => self::r($glIn), 'box5' => self::r($b['box5_total_output_cents']), 'box9' => self::r($b['box9_total_input_cents']),
                                          'box10' => self::r($b['box10_net_cents']), 'filed' => $filed ? self::r((int)$p['net_vat_cents']) : null],
                                 ['link' => '#vat?id=' . (int)$p['id'], 'flag' => $filed && $vsFiled !== 0]);
            if ($filed && $vsFiled !== 0) $checks[] = ['label' => $label, 'ok' => false, 'detail' => 'Changed by ' . Fin::money(abs($vsFiled) / 100) . ' since it was filed'];
        }
        // VAT Control should hold what is filed and not yet paid
        $q = $db->prepare("SELECT COALESCE(SUM(jl.credit - jl.debit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?");
        $q->execute([$companyId, $ctl]);
        $ctlBal = (int)round((float)$q->fetchColumn() * 100);
        $q = $db->prepare("SELECT COALESCE(SUM(net_vat_cents), 0) FROM gl_vat_periods WHERE company_id = ? AND status = 'filed'");
        $q->execute([$companyId]);
        $unpaid = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT ROUND(COALESCE(SUM(jl.debit + jl.credit) / 2, 0) * 100) FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id JOIN gl_vat_periods p ON p.id = je.ref_id AND p.company_id = je.company_id
                            WHERE je.company_id = ? AND je.module = 'vat_pay' AND je.ref_type = 'vat_period' AND je.status = 'posted' AND je.reversed_by_journal_id IS NULL AND p.status = 'filed'");
        $q->execute([$companyId]);
        $partPaid = (int)$q->fetchColumn();
        $checks[] = ['label' => 'VAT Control against filed, unpaid returns', 'ok' => $ctlBal === $unpaid - $partPaid,
                     'detail' => 'VAT Control ' . Fin::money($ctlBal / 100) . ' · filed and unpaid ' . Fin::money(($unpaid - $partPaid) / 100) . ($ctlBal === $unpaid - $partPaid ? '' : ' (older adjustments to VAT Control, payments categorised from the bank, or periods paid before Finance recorded payments)')];
        return ['title' => 'VAT reconciliation', 'subtitle' => 'Each return against the ledger',
                'columns' => [self::col('status', 'Status'), self::col('gl_out', 'Output account', true), self::col('gl_in', 'Input account', true), self::col('box5', 'Box 5', true), self::col('box9', 'Box 9', true), self::col('box10', 'Box 10 now', true), self::col('filed', 'Filed', true)],
                'rows' => $rows, 'checks' => $checks,
                'notes' => ['Output and input account columns are the movement on those accounts in the period (settlements left out). On the invoice basis they equal Box 5 and Box 9 (bad-debt relief is in Box 9 too); on the payments basis they differ by what is not yet paid.']];
    }

    public static function capitalGoodsVat(PDO $db, int $companyId, string $from, string $to): array
    {
        $map = new AccountsMap($db, $companyId);
        $in = $map->code('finance_vat_input_account_id');
        $st = $db->prepare("SELECT je.id, je.entry_date, je.reference, COALESCE(jl.description, je.description) d, jl.debit - jl.credit vat,
                                   (SELECT CONCAT(ga2.account_code, ' · ', ga2.account_name) FROM journal_lines jl2 JOIN gl_accounts ga2 ON ga2.account_code = jl2.account_code AND ga2.company_id = je.company_id
                                     WHERE jl2.journal_id = je.id AND jl2.id <> jl.id AND ga2.account_subtype = 'fixed_asset' LIMIT 1) asset,
                                   (SELECT s.name FROM crm_accounts s WHERE s.id = jl.supplier_id AND s.company_id = je.company_id LIMIT 1) supplier
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ? AND jl.account_code = ? AND jl.is_capital_goods = 1
                               AND COALESCE(je.module,'') <> 'vat_settle' ORDER BY je.entry_date, je.id");
        $st->execute([$companyId, $from, $to, $in]);
        $rows = []; $tot = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)round((float)$r['vat'] * 100);
            $tot += $c;
            $rows[] = self::line($r['entry_date'] . ' · ' . ($r['reference'] ?: '#' . $r['id']), ['what' => (string)$r['d'], 'supplier' => (string)($r['supplier'] ?? ''), 'asset' => (string)($r['asset'] ?? ''), 'vat' => self::r($c)],
                                 ['link' => '#journals?id=' . (int)$r['id']]);
        }
        $rows[] = self::line('Total (Box 7)', ['what' => '', 'supplier' => '', 'asset' => '', 'vat' => self::r($tot)], ['kind' => 'grand']);
        return ['title' => 'Capital goods VAT', 'subtitle' => $from . ' to ' . $to,
                'columns' => [self::col('what', 'Description'), self::col('supplier', 'Supplier'), self::col('asset', 'Asset account'), self::col('vat', 'Input VAT', true)], 'rows' => $rows];
    }

    /** SARS allowance per asset for the current financial year (the old register's sums). */
    public static function wearAndTear(PDO $db, int $companyId): array
    {
        require_once __DIR__ . '/CoaSchema.php';
        $fy = CoaSchema::fiscalYearStartDate($db, $companyId);
        $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE company_id = ? AND tax_method <> 'none' ORDER BY category, asset_name");
        $st->execute([$companyId]);
        $rows = []; $T = ['cost' => 0, 'cur' => 0, 'acc' => 0, 'book' => 0, 'diff' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $cost = (int)$a['purchase_cost_cents'];
            $years = $a['tax_method'] === 's12c' ? 4 : (int)$a['tax_writeoff_years'];
            $cur = 0; $acc = 0; $idx = 0;
            if ($cost > 0 && $years > 0) {
                $pfy = CoaSchema::fiscalYearStartDate($db, $companyId, $a['purchase_date']);
                $d = (new DateTime($pfy))->diff(new DateTime($fy));
                $idx = ($d->invert ? -(int)$d->y : (int)$d->y) + 1;
                if ($idx >= 1) {
                    $cap = $idx;
                    if ($a['status'] !== 'active' && !empty($a['disposal_date'])) {
                        $dfy = CoaSchema::fiscalYearStartDate($db, $companyId, $a['disposal_date']);
                        $dd = (new DateTime($pfy))->diff(new DateTime($dfy));
                        $di = ($dd->invert ? -(int)$dd->y : (int)$dd->y) + 1;
                        if ($di <= $idx) $cap = $di - 1;
                    }
                    if ($a['tax_method'] === 's12c') {
                        $sch = [(int)round($cost * 0.40), (int)round($cost * 0.20), (int)round($cost * 0.20)];
                        $sch[] = $cost - array_sum($sch);
                    } else {
                        $annual = intdiv($cost, $years);
                        $sch = array_fill(0, $years - 1, $annual);
                        $sch[] = $cost - $annual * ($years - 1);
                    }
                    $cur = ($cap >= $idx && $idx <= count($sch)) ? $sch[$idx - 1] : 0;
                    $acc = array_sum(array_slice($sch, 0, max(0, min($cap, count($sch)))));
                }
            }
            $book = (int)$a['accumulated_depreciation_cents'];
            $T['cost'] += $cost; $T['cur'] += $cur; $T['acc'] += $acc; $T['book'] += $book; $T['diff'] += $acc - $book;
            $rows[] = self::line($a['asset_name'], ['method' => $a['tax_method'] === 's12c' ? 's12C 40/20/20/20' : 's11(e) ' . $years . ' yr', 'year' => $idx, 'cost' => self::r($cost),
                                                   'current' => self::r($cur), 'accumulated' => self::r($acc), 'book' => self::r($book), 'diff' => self::r($acc - $book)], ['link' => '#assets?id=' . (int)$a['asset_id']]);
        }
        $rows[] = self::line('Total', ['method' => '', 'year' => '', 'cost' => self::r($T['cost']), 'current' => self::r($T['cur']), 'accumulated' => self::r($T['acc']), 'book' => self::r($T['book']), 'diff' => self::r($T['diff'])], ['kind' => 'grand']);
        return ['title' => 'Wear-and-tear register', 'subtitle' => 'Financial year from ' . $fy,
                'columns' => [self::col('method', 'Allowance'), self::col('year', 'Tax year', false, 'r'), self::col('cost', 'Cost', true), self::col('current', 'This year', true), self::col('accumulated', 'Allowed to date', true),
                              self::col('book', 'Book depreciation', true), self::col('diff', 'Temporary difference', true)], 'rows' => $rows,
                'notes' => ['The temporary difference (tax allowance less book depreciation) is the base for deferred tax.']];
    }

    // ------------------------------------------------------------- assets

    public static function faSchedule(PDO $db, int $companyId, string $asOf): array
    {
        $st = $db->prepare("SELECT a.*, COALESCE((SELECT SUM(l.amount_cents) FROM fa_depreciation_lines l JOIN fa_depreciation_runs r ON r.id = l.run_id
                                                   WHERE l.asset_id = a.asset_id AND r.company_id = a.company_id AND r.status = 'posted' AND r.run_month <= ?), 0) acc_at
                              FROM gl_fixed_assets a WHERE a.company_id = ? AND a.purchase_date <= ? ORDER BY a.category, a.asset_name");
        $st->execute([$asOf, $companyId, $asOf]);
        $rows = []; $T = ['cost' => 0, 'acc' => 0, 'nbv' => 0]; $cat = null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $gone = $a['status'] !== 'active' && $a['disposal_date'] && $a['disposal_date'] <= $asOf;
            $c = (string)($a['category'] ?: 'Uncategorised');
            if ($c !== $cat) { $rows[] = self::line($c, [], ['kind' => 'group']); $cat = $c; }
            $cost = $gone ? 0 : (int)$a['purchase_cost_cents'];
            $acc = $gone ? 0 : (int)$a['acc_at'];
            $T['cost'] += $cost; $T['acc'] += $acc; $T['nbv'] += $cost - $acc;
            $rows[] = self::line($a['asset_name'], ['bought' => $a['purchase_date'], 'cost' => self::r((int)$a['purchase_cost_cents']), 'acc' => self::r((int)$a['acc_at']),
                                                   'nbv' => $gone ? 0.0 : self::r((int)$a['purchase_cost_cents'] - (int)$a['acc_at']), 'status' => $gone ? 'disposed ' . $a['disposal_date'] : 'in use'],
                                 ['level' => 1, 'link' => '#assets?id=' . (int)$a['asset_id'], 'muted' => $gone]);
        }
        $rows[] = self::line('Total in use', ['bought' => '', 'cost' => self::r($T['cost']), 'acc' => self::r($T['acc']), 'nbv' => self::r($T['nbv']), 'status' => ''], ['kind' => 'grand']);
        return ['title' => 'Fixed asset schedule', 'subtitle' => 'As at ' . $asOf,
                'columns' => [self::col('bought', 'Bought'), self::col('cost', 'Cost', true), self::col('acc', 'Depreciation to date', true), self::col('nbv', 'Book value', true), self::col('status', 'Status')], 'rows' => $rows];
    }

    public static function budgetVsActual(PDO $db, int $companyId, int $fy): array
    {
        require_once __DIR__ . '/FinBudgets.php';
        $fy = $fy ?: (int)substr(Fin::fyStart($db, $companyId), 0, 4);
        $g = FinBudgets::grid($db, $companyId, $fy);
        $upto = $g['today_index'] ?? 11;
        $rows = []; $T = ['revenue' => [0, 0, 0], 'expense' => [0, 0, 0]];
        foreach (['revenue' => 'Income', 'expense' => 'Expenses'] as $type => $label) {
            $mine = array_filter($g['rows'], fn($r) => $r['type'] === $type && (array_filter($r['budget'], fn($v) => $v !== null) || array_sum($r['actual']) != 0));
            if (!$mine) continue;
            $rows[] = self::line($label, [], ['kind' => 'group']);
            foreach ($mine as $r) {
                $b = (int)round(array_sum(array_map('floatval', array_slice($r['budget'], 0, $upto + 1))) * 100);
                $a = (int)round(array_sum(array_slice($r['actual'], 0, $upto + 1)) * 100);
                $yb = (int)round(array_sum(array_map('floatval', $r['budget'])) * 100);
                $T[$type][0] += $b; $T[$type][1] += $a; $T[$type][2] += $yb;
                $var = $type === 'revenue' ? $a - $b : $b - $a;
                $rows[] = self::line($r['code'] . ' · ' . $r['name'], ['budget' => self::r($b), 'actual' => self::r($a), 'variance' => self::r($var), 'pct' => $b ? round($a / $b * 100, 1) : null, 'year' => self::r($yb)],
                                     ['level' => 1, 'flag' => $var < 0 && $b > 0]);
            }
            [$b, $a, $yb] = $T[$type];
            $rows[] = self::line('Total ' . strtolower($label), ['budget' => self::r($b), 'actual' => self::r($a), 'variance' => self::r($type === 'revenue' ? $a - $b : $b - $a), 'pct' => $b ? round($a / $b * 100, 1) : null, 'year' => self::r($yb)], ['kind' => 'total']);
        }
        $nb = $T['revenue'][0] - $T['expense'][0]; $na = $T['revenue'][1] - $T['expense'][1];
        $rows[] = self::line('Net', ['budget' => self::r($nb), 'actual' => self::r($na), 'variance' => self::r($na - $nb), 'pct' => null, 'year' => self::r($T['revenue'][2] - $T['expense'][2])], ['kind' => 'grand']);
        $through = $g['months'][$upto]['label'] ?? '';
        return ['title' => 'Budget against actual', 'subtitle' => 'FY ' . $fy . '/' . substr((string)($fy + 1), 2) . ' · year to date through ' . $through,
                'columns' => [self::col('budget', 'Budget to date', true), self::col('actual', 'Actual to date', true), self::col('variance', 'Better / (worse)', true), ['key' => 'pct', 'label' => '% of budget', 'money' => false, 'align' => 'r', 'pct' => true], self::col('year', 'Full-year budget', true)],
                'rows' => $rows];
    }

    // --------------------------------------------------------- compliance

    public static function sequenceGaps(PDO $db, int $companyId, int $year): array
    {
        $sources = [['Invoices', 'invoices', 'invoice_number', 'issue_date', 'AND deleted_at IS NULL'], ['Credit notes', 'credit_notes', 'credit_note_number', 'issue_date', '']];
        $rows = []; $checks = [];
        foreach ($sources as [$label, $table, $col, $dateCol, $extra]) {
            try {
                $q = $db->prepare("SELECT id, `$col` num FROM `$table` WHERE company_id = ? AND YEAR(`$dateCol`) = ? $extra");
                $q->execute([$companyId, $year]);
                $all = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { $all = []; }
            $groups = [];
            foreach ($all as $r) if (preg_match('/^(.*?)(\d+)$/', trim((string)$r['num']), $m)) $groups[$m[1]][(int)$m[2]][] = (int)$r['id'];
            ksort($groups);
            if (!$groups) { $rows[] = self::line($label, ['range' => 'none in ' . $year, 'count' => 0, 'gaps' => '', 'dupes' => ''], []); continue; }
            foreach ($groups as $prefix => $nums) {
                ksort($nums, SORT_NUMERIC);
                $keys = array_keys($nums); $min = min($keys); $max = max($keys);
                $gaps = [];
                for ($i = $min; $i <= $max && count($gaps) < 200; $i++) if (!isset($nums[$i])) $gaps[] = $i;
                $dupes = array_keys(array_filter($nums, fn($ids) => count($ids) > 1));
                $rows[] = self::line($label . ' · ' . ($prefix !== '' ? $prefix : '(no prefix)'), ['range' => $prefix . $min . ' – ' . $prefix . $max, 'count' => count($keys),
                                     'gaps' => $gaps ? implode(', ', array_slice($gaps, 0, 30)) . (count($gaps) > 30 ? ' …' : '') : '', 'dupes' => $dupes ? implode(', ', array_slice($dupes, 0, 30)) : ''],
                                     ['flag' => (bool)($gaps || $dupes)]);
                $checks[] = ['label' => $label . ' ' . $prefix, 'ok' => !$gaps && !$dupes, 'detail' => !$gaps && !$dupes ? 'No gaps' : count($gaps) . ' missing, ' . count($dupes) . ' repeated'];
            }
        }
        return ['title' => 'Numbering gaps', 'subtitle' => (string)$year, 'columns' => [self::col('range', 'Numbers'), self::col('count', 'Documents', false, 'r'), self::col('gaps', 'Missing'), self::col('dupes', 'Repeated')],
                'rows' => $rows, 'checks' => $checks, 'notes' => ['SARS expects tax invoices and credit notes to be numbered in sequence. A missing number needs an explanation (e.g. a deleted draft).']];
    }

    public static function retention(PDO $db, int $companyId): array
    {
        $tables = ['journals' => ['journal_entries', 'entry_date'], 'invoices' => ['invoices', 'issue_date'], 'bills' => ['ap_bills', 'issue_date'], 'receipts' => ['payments', 'payment_date'], 'bank' => ['gl_bank_transactions', 'tx_date']];
        $years = [];
        foreach ($tables as $k => [$t, $c]) {
            try {
                $q = $db->prepare("SELECT YEAR(`$c`) y, COUNT(*) n FROM `$t` WHERE company_id = ? AND `$c` IS NOT NULL GROUP BY y");
                $q->execute([$companyId]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $years[(int)$r['y']][$k] = (int)$r['n'];
            } catch (PDOException $e) { /* table missing */ }
        }
        krsort($years);
        $now = (int)date('Y');
        $rows = [];
        foreach ($years as $y => $n) {
            $keep = $y + 5;
            $rows[] = self::line((string)$y, array_merge(array_fill_keys(array_keys($tables), 0), $n) + ['keep' => 'until at least the end of ' . $keep . ($keep <= $now ? ' (may be archived, never altered)' : '')],
                                 ['muted' => $keep < $now]);
        }
        $cols = [];
        foreach (array_keys($tables) as $k) $cols[] = self::col($k, ucfirst($k), false, 'r');
        $cols[] = self::col('keep', 'Keep');
        return ['title' => 'Record retention', 'subtitle' => 'Tax Administration Act s29: five years from the return', 'columns' => $cols, 'rows' => $rows,
                'notes' => ['Records are kept for at least five years after the return they support was submitted (longer while an audit or objection is open). Flowwork never deletes posted records.']];
    }

    // ---------------------------------------------------------------- CSV

    public static function csv(array $r): void
    {
        require_once __DIR__ . '/FinExport.php';
        $head = array_merge([''], array_map(fn($c) => $c['label'], $r['columns']));
        $rows = [[$r['title'] . ' · ' . $r['subtitle'] . ' · ' . $r['company']]];
        foreach ($r['rows'] as $row) {
            $line = [str_repeat('  ', (int)($row['level'] ?? 0)) . $row['label']];
            foreach ($r['columns'] as $c) {
                $v = $row['cells'][$c['key']] ?? '';
                $line[] = $c['money'] && is_numeric($v) ? number_format((float)$v, 2, '.', '') : (is_scalar($v) ? (string)$v : '');
            }
            $rows[] = $line;
        }
        FinExport::csv(preg_replace('/[^a-z0-9]+/', '-', strtolower($r['title'])) . '-' . date('Y-m-d') . '.csv', $head, $rows);
    }
}
