<?php
// GET /finances/api/ledger.php?account=CODE&from=&to=[&project=] — one account's ledger with a running balance.
//   &format=csv downloads the same lines.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinLedger.php';

[$f, $t] = Fin::periodRange($DB, $FIN_COMPANY_ID, 'ytd');
$from = Fin::ymd($_GET['from'] ?? '') ?: $f;
$to = Fin::ymd($_GET['to'] ?? '') ?: date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$code = fin_str($_GET['account'] ?? '', 20);
if ($code === '') throw new FinUserError('Choose an account.');
$csv = ($_GET['format'] ?? '') === 'csv';
$d = FinLedger::detail($DB, $FIN_COMPANY_ID, $code, $from, $to, fin_int($_GET['project'] ?? 0), $csv ? 50000 : FinLedger::MAX_ROWS);
if ($csv) {
    require_once __DIR__ . '/../lib/FinExport.php';
    FinExport::csv('ledger-' . $code . '-' . $from . '-to-' . $to . '.csv',
        ['Date', 'Journal', 'Reference', 'Description', 'Other accounts', 'Debit', 'Credit', 'Balance'],
        array_merge([[$from, '', '', 'Opening balance', '', '', '', number_format($d['opening'], 2, '.', '')]],
            array_map(fn($r) => [$r['date'], $r['journal_id'], $r['reference'], trim($r['memo'] . ($r['description'] && $r['description'] !== $r['memo'] ? ' — ' . $r['description'] : '')),
                                 implode(' ', $r['others']), number_format($r['debit'], 2, '.', ''), number_format($r['credit'], 2, '.', ''), number_format($r['balance'], 2, '.', '')], $d['rows'])));
}
fin_json($d);
