<?php
// /finances/api/assets.php — the fixed asset register.
//   GET  ?op=list&status=&q=&category=[&format=csv] · ?op=get&id= · ?op=runs · ?op=preview&month=YYYY-MM · ?op=defaults
//   POST {op: save, …} · {op: run, month} · {op: dispose, id, date, proceeds, vat, bank_account_id?, notes?}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinAssets.php';

fin_require_module('assets');
$C = $FIN_COMPANY_ID;

if (!fin_is_post()) {
    switch ($_GET['op'] ?? 'list') {
        case 'list':
            $d = FinAssets::list($DB, $C, $_GET);
            if (($_GET['format'] ?? '') === 'csv') {
                require_once __DIR__ . '/../lib/FinExport.php';
                $n = fn($v) => number_format((float)$v, 2, '.', '');
                FinExport::csv('fixed-assets-' . date('Y-m-d') . '.csv', ['Asset', 'Category', 'Purchased', 'Cost', 'Residual', 'Life (months)', 'Method', 'Accumulated', 'Book value', 'Monthly', 'Status', 'Disposed', 'Proceeds'],
                    array_map(fn($r) => [$r['name'], $r['category'], $r['purchased'], $n($r['cost']), $n($r['salvage']), $r['life'], FinAssets::METHODS[$r['method']] ?? $r['method'],
                                          $n($r['accumulated']), $n($r['book']), $n($r['monthly']), $r['status'], $r['disposed'], $r['proceeds'] !== null ? $n($r['proceeds']) : ''], $d['rows']));
            }
            fin_json($d);
        case 'get': fin_json(FinAssets::get($DB, $C, fin_int($_GET['id'] ?? 0)));
        case 'runs': fin_json(['runs' => FinAssets::runs($DB, $C, 120), 'next_month' => FinAssets::nextMonth($DB, $C)]);
        case 'preview': fin_json(FinAssets::preview($DB, $C, fin_str($_GET['month'] ?? '', 10)));
        case 'defaults': fin_json(['defaults' => FinAssets::defaults($DB, $C), 'methods' => FinAssets::METHODS, 'tax_methods' => FinAssets::TAX_METHODS]);
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'save':
        $id = FinAssets::save($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        fin_json(['id' => $id] + FinAssets::get($DB, $C, $id));
    case 'run':
        fin_rate_limit('fa_run', 20, 300);
        fin_json(FinAssets::run($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['month'] ?? '', 10)));
    case 'dispose':
        $jid = FinAssets::dispose($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0), $in);
        fin_json(['journal_id' => $jid] + FinAssets::get($DB, $C, fin_int($in['id'] ?? 0)));
}
fin_error('Unknown action');
