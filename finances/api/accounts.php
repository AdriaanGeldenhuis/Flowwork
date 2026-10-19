<?php
// /finances/api/accounts.php — the chart of accounts.
//   GET  ?from=&to=[&format=csv]                     every account with balances and movement in the period
//   POST {op: save, id?, code, name, type, subtype?, normal?, description?, parent_id?, tax_code_id?, active?, manual?}
//   POST {op: toggle, id} · {op: delete, id} (admin)
//   POST {op: import, csv, dry_run} (admin; csv as text or a file in "file")
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinAccounts.php';
require_once __DIR__ . '/../lib/FinBoot.php';

if (!fin_is_post()) {
    [$f, $t] = Fin::periodRange($DB, $FIN_COMPANY_ID, 'ytd');
    $from = Fin::ymd($_GET['from'] ?? '') ?: $f;
    $to = Fin::ymd($_GET['to'] ?? '') ?: date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    $d = FinAccounts::list($DB, $FIN_COMPANY_ID, $from, $to);
    if (($_GET['format'] ?? '') === 'csv') {
        require_once __DIR__ . '/../lib/FinExport.php';
        $byId = array_column($d['rows'], 'code', 'id');
        FinExport::csv('chart-of-accounts-' . $to . '.csv', ['code', 'name', 'type', 'subtype', 'normal_balance', 'parent_code', 'active', 'balance', 'debits', 'credits'],
            array_map(fn($a) => [$a['code'], $a['name'], $a['type'], $a['subtype'], $a['normal'], $a['parent'] ? ($byId[$a['parent']] ?? '') : '', $a['active'] ? 'yes' : 'no',
                                 number_format($a['balance'], 2, '.', ''), number_format($a['debit'], 2, '.', ''), number_format($a['credit'], 2, '.', '')], $d['rows']));
    }
    fin_json($d);
}

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'save':
        $id = FinAccounts::save($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $in);
        fin_json(['id' => $id, 'accounts' => FinBoot::accounts($DB, $FIN_COMPANY_ID)]);
    case 'toggle':
        $on = FinAccounts::toggle($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0));
        fin_json(['active' => $on, 'accounts' => FinBoot::accounts($DB, $FIN_COMPANY_ID)]);
    case 'delete':
        FinAccounts::delete($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0));
        fin_json(['accounts' => FinBoot::accounts($DB, $FIN_COMPANY_ID)]);
    case 'import':
        $csv = (string)($in['csv'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ($_FILES['file']['size'] > 2 * 1024 * 1024) throw new FinUserError('That file is too large (2 MB at most).');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['file']['tmp_name']);
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) throw new FinUserError('Upload a CSV file.');
            $csv = (string)file_get_contents($_FILES['file']['tmp_name']);
        }
        $r = FinAccounts::import($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $csv, !empty($in['dry_run']));
        fin_json($r + ['accounts' => FinBoot::accounts($DB, $FIN_COMPANY_ID)]);
    default:
        fin_error('Unknown action');
}
