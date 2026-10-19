<?php
// /finances/api/receivables.php — what customers owe.
//   GET  ?op=invoices&status=&customer=&q=&from=&to=&sort=&dir=&page=[&format=csv]
//   GET  ?op=invoice&id=                 one invoice's money side
//   GET  ?op=customers&q=                customers with something open
//   GET  ?op=open&customer=              every open invoice of a customer (receive dialog)
//   GET  ?op=aging&as_of=[&format=csv]   aging per customer as at a date
//   GET  ?op=statement&customer=&from=&to=[&format=csv]
//   GET  ?op=reminder&id=                draft reminder email (subject, body, to)
//   POST {op: receive, customer_id, date, method, reference?, bank_account_id?, allocations[{invoice_id, amount}], key}
//        {op: remind, id, to?, subject?, body?}
// Invoices and credit notes themselves are written in Quotes & Invoices.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinReceivables.php';

fin_require_module('receivables');
$C = $FIN_COMPANY_ID;
$soon = (int)($FIN_CONFIG['thresholds']['due_soon_days'] ?? 7);

if (!fin_is_post()) {
    $csv = ($_GET['format'] ?? '') === 'csv';
    if ($csv) require_once __DIR__ . '/../lib/FinExport.php';
    switch ($_GET['op'] ?? 'invoices') {
        case 'invoices':
            if ($csv) {
                $f = $_GET; $rows = [];
                for ($p = 1; $p <= 100; $p++) {
                    $f['page'] = $p;
                    $d = FinReceivables::invoices($DB, $C, $f, $soon);
                    foreach ($d['rows'] as $r) $rows[] = [$r['number'], $r['customer'], $r['issued'], $r['due'], $r['status'], $r['currency'],
                                                          number_format($r['total'], 2, '.', ''), number_format($r['balance'], 2, '.', ''), number_format($r['balance_zar'], 2, '.', ''), $r['days_late']];
                    if ($p * $d['per'] >= $d['total']) break;
                }
                FinExport::csv('invoices-' . date('Y-m-d') . '.csv', ['Invoice', 'Customer', 'Issued', 'Due', 'Status', 'Currency', 'Total', 'Balance', 'Balance (ZAR)', 'Days late'], $rows);
            }
            fin_json(FinReceivables::invoices($DB, $C, $_GET, $soon));
        case 'invoice':
            fin_json(FinReceivables::invoice($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'customers':
            fin_json(['rows' => FinReceivables::customers($DB, $C, fin_str($_GET['q'] ?? '', 100))]);
        case 'open':
            $cust = fin_int($_GET['customer'] ?? 0);
            if (!$cust) fin_error('Choose the customer.');
            $rows = [];
            for ($p = 1; $p <= 20; $p++) {
                $d = FinReceivables::invoices($DB, $C, ['status' => 'open', 'customer' => $cust, 'sort' => 'due', 'page' => $p], $soon);
                $rows = array_merge($rows, $d['rows']);
                if ($p * $d['per'] >= $d['total']) break;
            }
            fin_json(['rows' => $rows]);
        case 'aging':
            $asOf = Fin::ymd($_GET['as_of'] ?? '') ?: date('Y-m-d');
            $a = FinReceivables::aging($DB, $C, $asOf);
            if ($csv) {
                $n = fn($x) => number_format($x, 2, '.', '');
                $rows = array_map(fn($r) => [$r['name'], $r['docs'], $n($r['current']), $n($r['d30']), $n($r['d60']), $n($r['d90']), $n($r['d90p']), $n($r['total'])], $a['rows']);
                $t = $a['totals'];
                $rows[] = ['Total', '', $n($t['current']), $n($t['d30']), $n($t['d60']), $n($t['d90']), $n($t['d90p']), $n($t['total'])];
                FinExport::csv('debtors-aging-' . $asOf . '.csv', ['Customer', 'Invoices', 'Current', '1-30 days', '31-60 days', '61-90 days', '90+ days', 'Total'], $rows);
            }
            fin_json($a);
        case 'statement':
            $from = Fin::ymd($_GET['from'] ?? '') ?: date('Y-m-01', strtotime('-2 months'));
            $to = Fin::ymd($_GET['to'] ?? '') ?: date('Y-m-d');
            if ($from > $to) fin_error('The start date is after the end date.');
            $s = FinReceivables::statement($DB, $C, fin_int($_GET['customer'] ?? 0), $from, $to);
            if ($csv) {
                $n = fn($x) => number_format($x, 2, '.', '');
                $rows = [[$from, 'Opening balance', '', '', '', $n($s['opening'])]];
                foreach ($s['lines'] as $l) $rows[] = [$l['date'], $l['description'], $l['reference'], $l['debit'] ? $n($l['debit']) : '', $l['credit'] ? $n($l['credit']) : '', $n($l['balance'])];
                FinExport::csv('statement-' . preg_replace('/[^a-z0-9]+/i', '-', $s['customer']['name']) . '-' . $to . '.csv', ['Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance (' . $s['currency'] . ')'], $rows);
            }
            fin_json($s);
        case 'reminder':
            fin_json(['draft' => FinReceivables::reminderDraft($DB, $C, $FIN_USER_ID, fin_int($_GET['id'] ?? 0))]);
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'receive':
        fin_rate_limit('ar_receive', 30, 60);
        $done = FinReceivables::receive($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        fin_json(['done' => $done]);
    case 'remind':
        fin_rate_limit('ar_remind', 20, 300);
        $opts = [];
        foreach (['to', 'cc'] as $k) if (isset($in[$k])) $opts[$k] = is_array($in[$k]) ? array_map('strval', $in[$k]) : (string)$in[$k];
        if (isset($in['subject'])) $opts['subject'] = fin_str($in['subject'], 200);
        if (isset($in['body'])) $opts['body'] = mb_substr((string)$in['body'], 0, 20000);
        $r = FinReceivables::remind($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0), $opts);
        fin_json(['to' => $r['to'] ?? []] + FinReceivables::invoice($DB, $C, fin_int($in['id'] ?? 0)));
}
fin_error('Unknown action');
