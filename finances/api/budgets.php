<?php
// /finances/api/budgets.php — budgets per account per month, by financial year, with actuals.
//   GET  ?op=grid&fy=YYYY (the year the financial year starts in)[&format=csv]
//   POST {op: save, cells[{account_id, y, m, amount}]} — only the cells sent change
//        {op: seed, fy, from: actual|budget, grow, overwrite}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinBudgets.php';

fin_require_module('budgets');
$C = $FIN_COMPANY_ID;
$fyNow = (int)substr(Fin::fyStart($DB, $C), 0, 4);

if (!fin_is_post()) {
    if (($_GET['op'] ?? 'grid') !== 'grid') fin_error('Unknown action');
    $fy = Fin::clampInt($_GET['fy'] ?? $fyNow, 2000, 2100);
    $g = FinBudgets::grid($DB, $C, $fy);
    if (($_GET['format'] ?? '') === 'csv') {
        require_once __DIR__ . '/../lib/FinExport.php';
        $head = ['Code', 'Account', 'Type'];
        foreach ($g['months'] as $m) { $head[] = $m['label'] . ' budget'; $head[] = $m['label'] . ' actual'; }
        $head[] = 'Year budget'; $head[] = 'Year actual';
        $rows = [];
        foreach ($g['rows'] as $r) {
            $line = [$r['code'], $r['name'], $r['type']];
            foreach ($r['budget'] as $i => $b) { $line[] = $b === null ? '' : number_format($b, 2, '.', ''); $line[] = number_format($r['actual'][$i], 2, '.', ''); }
            $line[] = number_format(array_sum(array_map('floatval', $r['budget'])), 2, '.', '');
            $line[] = number_format(array_sum($r['actual']), 2, '.', '');
            $rows[] = $line;
        }
        FinExport::csv('budget-fy' . $fy . '.csv', $head, $rows);
    }
    fin_json($g + ['fy_now' => $fyNow]);
}

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'save':
        fin_json(['saved' => FinBudgets::save($DB, $C, $FIN_USER_ID, $FIN_ROLE, (array)($in['cells'] ?? []))]);
    case 'seed':
        $fy = Fin::clampInt($in['fy'] ?? $fyNow, 2000, 2100);
        $n = FinBudgets::seed($DB, $C, $FIN_USER_ID, $FIN_ROLE, $fy, ($in['from'] ?? '') === 'budget' ? 'budget' : 'actual', (float)($in['grow'] ?? 0), !empty($in['overwrite']));
        fin_json(['saved' => $n] + FinBudgets::grid($DB, $C, $fy));
}
fin_error('Unknown action');
