<?php
// /payroll/api/reports.php — SARS returns and payroll reports.
//   GET ?op=emp201&month=YYYY-MM
//   GET ?op=recon&year=YYYY-03-01&kind=interim|annual
//   GET ?op=summary&from=&to=
//   GET ?op=costs&from=&to=
// Add &format=csv for a spreadsheet (UTF-8, opens in Excel). Every download
// is written to the audit trail.
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
$op = pay_op();
$csv = ($_GET['format'] ?? '') === 'csv';
$r = fn(int $c) => number_format($c / 100, 2, '.', '');
$company = preg_replace('/[^A-Za-z0-9]+/', '-', (string)($DB->query("SELECT name FROM companies WHERE id = " . (int)$C)->fetchColumn() ?: 'company'));
if ($csv) {
    pay_rate_limit('pay-export', 60, 600);
    PayKit::audit($DB, $C, $PAY_USER_ID, 'data_exported', ['export' => 'Payroll ' . $op]);
}

switch ($op) {
    case 'emp201':
        $d = PayReports::emp201($DB, $C, (string)($_GET['month'] ?? date('Y-m')));
        if (!$csv) pay_json(['report' => $d]);
        $rows = array_map(fn($x) => [$x['number'], $x['name'], $x['pay_date'], $x['people'], $r($x['gross']), $r($x['paye']), $r($x['uif']), $r($x['sdl']), $r($x['paye'] + $x['uif'] + $x['sdl'])], $d['runs']);
        $rows[] = ['Total', '', '', $d['totals']['people'], $r($d['totals']['gross']), $r($d['totals']['paye']), $r($d['totals']['uif']), $r($d['totals']['sdl']), $r($d['totals']['total'])];
        $rows[] = ['Due to SARS by', $d['due']];
        PayKit::csv("$company-EMP201-{$d['month']}.csv", ['Run', 'Name', 'Pay day', 'People', 'Gross pay (R)', 'PAYE (R)', 'UIF (R)', 'SDL (R)', 'Total (R)'], $rows);
    case 'recon':
        $d = PayReports::recon($DB, $C, (string)($_GET['year'] ?? PayTax::taxYearStart(date('Y-m-d'))), (string)($_GET['kind'] ?? 'annual'));
        if (!$csv) pay_json(['report' => $d]);
        $head = array_merge(['Employee no', 'Name', 'ID / passport', 'Tax number', 'Employed'], array_map(fn($c) => 'Code ' . $c . ' (R)', $d['codes']),
                            ['Gross pay (R)', 'Taxable income (R)', '4102 PAYE (R)', '4141 UIF (R)', '4142 SDL (R)', '4149 Total (R)']);
        $rows = array_map(fn($p) => array_merge([$p['no'], $p['name'], $p['id_number'], $p['tax_number'], $p['period']], array_map(fn($c) => $r($p['codes'][$c] ?? 0), $d['codes']),
                                                [$r($p['gross']), $r($p['taxable']), $r($p['paye']), $r($p['uif']), $r($p['sdl']), $r($p['paye'] + $p['uif'] + $p['sdl'])]), $d['people']);
        PayKit::csv("$company-EMP501-" . ($d['kind'] === 'interim' ? 'interim' : 'annual') . '-' . PayTax::taxYearLabel($d['from']) . '.csv', $head, $rows);
    case 'summary':
        $d = PayReports::summary($DB, $C, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
        if (!$csv) pay_json(['report' => $d]);
        $rows = array_map(fn($x) => [$x['no'], $x['name'], $x['runs'], $r($x['gross']), $r($x['paye']), $r($x['uif']), $r($x['deductions']), $r($x['reimb']), $r($x['net']), $r($x['cost'])], $d['rows']);
        $t = $d['totals'];
        $rows[] = ['', 'Total', '', $r($t['gross']), $r($t['paye']), $r($t['uif']), $r($t['deductions']), $r($t['reimb']), $r($t['net']), $r($t['cost'])];
        PayKit::csv("$company-payroll-{$d['from']}-to-{$d['to']}.csv", ['Employee no', 'Name', 'Runs', 'Gross pay (R)', 'PAYE (R)', 'UIF (R)', 'Other deductions (R)', 'Reimbursed (R)', 'Net pay (R)', 'Cost to company (R)'], $rows);
    case 'costs':
        $d = PayReports::costs($DB, $C, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
        if (!$csv) pay_json(['report' => $d]);
        $rows = array_map(fn($x) => [$x['name'], $r($x['wages']), $r($x['levies']), $r($x['total'])], $d['rows']);
        $rows[] = ['Total', $r($d['totals']['wages']), $r($d['totals']['levies']), $r($d['totals']['total'])];
        PayKit::csv("$company-wage-cost-by-project-{$d['from']}-to-{$d['to']}.csv", ['Project', 'Wages (R)', 'Employer UIF + SDL (R)', 'Total (R)'], $rows);
}
pay_error('Unknown report');
