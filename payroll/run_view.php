<?php
// /payroll/run_view.php?id= — one pay run: its stage, the money, everyone in
// it and the actions for the stage it is in. Drawn by js/run.js from
// /payroll/api/runs.php; each person's payslip opens in a dialog where
// hours, overtime, bonuses and once-off deductions are added.
require_once __DIR__ . '/lib/PayBoot.php';

$id = (int)($_GET['id'] ?? 0);
try {
    $run = PayRuns::row($DB, $PAY_COMPANY_ID, $id);
} catch (PayProblem $ex) {
    header('Location: /payroll/runs.php');
    exit;
}
$items = array_values(array_filter(PayItems::list($DB, $PAY_COMPANY_ID)['rows'], fn($i) => $i['active'] && !$i['system']));

$page = 'runs';
$pageTitle = $run['name'];
$pageScripts = ['run'];
$pageBoot = [
    'runId' => $id,
    'freq' => PayKit::FREQ,
    'items' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'type' => $i['type'], 'irregular' => $i['irregular']], $items),
    'autoPost' => $PAY_SETTINGS['auto_post_to_finance'],
    'bankReady' => (bool)$PAY_SETTINGS['bank_account_id'],
];
include __DIR__ . '/partials/head.php';
?>
<div id="runApp" aria-live="polite">
    <div class="fwp-loading" style="min-height:50vh"><span class="fwp-spin"></span>Opening the pay run…</div>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
