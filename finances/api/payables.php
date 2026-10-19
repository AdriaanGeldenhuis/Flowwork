<?php
// /finances/api/payables.php — what the company owes suppliers.
//   GET  ?op=bills&status=&supplier=&q=&from=&to=&sort=&dir=&page=[&format=csv]
//   GET  ?op=bill&id=                    one bill with lines, payments, credits, files
//   GET  ?op=open&supplier=              every open bill of a supplier (pay / credit dialogs)
//   GET  ?op=payments&supplier=&q=&from=&to=&page=
//   GET  ?op=credits&supplier=&q=        supplier credit notes
//   GET  ?op=credit&id=
//   GET  ?op=suppliers&q=                suppliers with something open
//   GET  ?op=supplier_list&q=            every supplier (pickers)
//   GET  ?op=aging&as_of=[&format=csv]
//   GET  ?op=statement&supplier=&from=&to=[&format=csv]
//   GET  ?op=match&supplier=             order / delivery / bill lines with what is left to match
//   POST {op: bill_save, …, then?: post} · {op: bill_post, id} · {op: bill_delete, id}
//        {op: pay, supplier_id, date, bank_account_id?, method, reference?, allocations[], key}
//        {op: credit_save, …, then?: post} · {op: credit_post, id, allocations?} · {op: credit_allocate, id, allocations[]} · {op: credit_delete, id}
//        {op: match_apply, supplier_id, matches[]}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinPayables.php';

fin_require_module('payables');
$C = $FIN_COMPANY_ID;
$soon = (int)($FIN_CONFIG['thresholds']['due_soon_days'] ?? 7);

if (!fin_is_post()) {
    $csv = ($_GET['format'] ?? '') === 'csv';
    if ($csv) require_once __DIR__ . '/../lib/FinExport.php';
    $n = fn($x) => number_format((float)$x, 2, '.', '');
    switch ($_GET['op'] ?? 'bills') {
        case 'bills':
            if ($csv) {
                $f = $_GET; $rows = [];
                for ($p = 1; $p <= 100; $p++) {
                    $f['page'] = $p;
                    $d = FinPayables::bills($DB, $C, $f, $soon);
                    foreach ($d['rows'] as $r) $rows[] = [$r['number'], $r['supplier'], $r['issued'], $r['due'], $r['status'], $n($r['tax']), $n($r['total']), $n($r['balance']), $r['days_late']];
                    if ($p * $d['per'] >= $d['total']) break;
                }
                FinExport::csv('bills-' . date('Y-m-d') . '.csv', ['Supplier invoice', 'Supplier', 'Date', 'Due', 'Status', 'VAT', 'Total', 'Balance', 'Days late'], $rows);
            }
            fin_json(FinPayables::bills($DB, $C, $_GET, $soon));
        case 'bill':
            fin_json(FinPayables::bill($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'open':
            fin_json(['rows' => FinPayables::openBills($DB, $C, fin_int($_GET['supplier'] ?? 0))]);
        case 'payments':
            fin_json(FinPayables::payments($DB, $C, $_GET));
        case 'credits':
            fin_json(FinPayables::credits($DB, $C, $_GET));
        case 'credit':
            fin_json(FinPayables::credit($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'suppliers':
            fin_json(['rows' => FinPayables::suppliers($DB, $C, fin_str($_GET['q'] ?? '', 100))]);
        case 'supplier_list':
            $q = fin_str($_GET['q'] ?? '', 100);
            $st = $DB->prepare("SELECT id, name, email FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL" . ($q !== '' ? ' AND name LIKE ?' : '') . " ORDER BY name LIMIT 500");
            $st->execute($q !== '' ? [$C, '%' . Fin::likeEscape($q) . '%'] : [$C]);
            fin_json(['rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => (string)($r['email'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC))]);
        case 'aging':
            $asOf = Fin::ymd($_GET['as_of'] ?? '') ?: date('Y-m-d');
            $a = FinPayables::aging($DB, $C, $asOf);
            if ($csv) {
                $rows = array_map(fn($r) => [$r['name'], $r['docs'], $n($r['current']), $n($r['d30']), $n($r['d60']), $n($r['d90']), $n($r['d90p']), $n($r['total'])], $a['rows']);
                $t = $a['totals'];
                $rows[] = ['Total', '', $n($t['current']), $n($t['d30']), $n($t['d60']), $n($t['d90']), $n($t['d90p']), $n($t['total'])];
                FinExport::csv('creditors-aging-' . $asOf . '.csv', ['Supplier', 'Bills', 'Current', '1-30 days', '31-60 days', '61-90 days', '90+ days', 'Total'], $rows);
            }
            fin_json($a);
        case 'statement':
            $from = Fin::ymd($_GET['from'] ?? '') ?: date('Y-m-01', strtotime('-2 months'));
            $to = Fin::ymd($_GET['to'] ?? '') ?: date('Y-m-d');
            if ($from > $to) fin_error('The start date is after the end date.');
            $s = FinPayables::statement($DB, $C, fin_int($_GET['supplier'] ?? 0), $from, $to);
            if ($csv) {
                $rows = [[$from, 'Opening balance', '', '', '', $n($s['opening'])]];
                foreach ($s['lines'] as $l) $rows[] = [$l['date'], $l['description'], $l['reference'], $l['debit'] ? $n($l['debit']) : '', $l['credit'] ? $n($l['credit']) : '', $n($l['balance'])];
                FinExport::csv('supplier-statement-' . preg_replace('/[^a-z0-9]+/i', '-', $s['supplier']['name']) . '-' . $to . '.csv', ['Date', 'Description', 'Reference', 'Paid / credited', 'Billed', 'Owing'], $rows);
            }
            fin_json($s);
        case 'match':
            fin_json(FinPayables::matchLines($DB, $C, fin_int($_GET['supplier'] ?? 0)));
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
$id = fin_int($in['id'] ?? 0);
switch (fin_op()) {
    case 'bill_save':
        $id = FinPayables::saveBill($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        if (($in['then'] ?? '') === 'post') fin_then($id, fn() => FinPayables::postBill($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id));
        fin_json(['id' => $id] + FinPayables::bill($DB, $C, $id));
    case 'bill_post':
        FinPayables::postBill($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json(FinPayables::bill($DB, $C, $id));
    case 'bill_delete':
        FinPayables::deleteBill($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json(['deleted' => $id]);
    case 'pay':
        fin_rate_limit('ap_pay', 30, 60);
        fin_json(FinPayables::pay($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in));
    case 'credit_save':
        $id = FinPayables::saveCredit($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        if (($in['then'] ?? '') === 'post') fin_then($id, fn() => FinPayables::postCredit($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (array)($in['allocations'] ?? [])));
        fin_json(['id' => $id] + FinPayables::credit($DB, $C, $id));
    case 'credit_post':
        FinPayables::postCredit($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (array)($in['allocations'] ?? []));
        fin_json(FinPayables::credit($DB, $C, $id));
    case 'credit_allocate':
        FinPayables::allocateCredit($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id, (array)($in['allocations'] ?? []));
        fin_json(FinPayables::credit($DB, $C, $id));
    case 'credit_delete':
        FinPayables::deleteCredit($DB, $C, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json(['deleted' => $id]);
    case 'match_apply':
        $sup = fin_int($in['supplier_id'] ?? 0);
        $count = FinPayables::applyMatches($DB, $C, $FIN_USER_ID, $FIN_ROLE, $sup, (array)($in['matches'] ?? []));
        fin_json(['matched' => $count] + FinPayables::matchLines($DB, $C, $sup));
}
fin_error('Unknown action');
