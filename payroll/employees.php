<?php
// /payroll/employees.php — everyone on the payroll: search, filter, add and
// edit (details, pay, tax and bank, regular items, pay history), end and
// restore employment. The table and dialog are drawn by js/employees.js
// from /payroll/api/employees.php.
require_once __DIR__ . '/lib/PayBoot.php';

$C = $PAY_COMPANY_ID;
$items = array_values(array_filter(PayItems::list($DB, $C)['rows'], fn($i) => $i['active'] && $i['code'] !== PayEngine::BASIC));
$projects = [];
try {
    $st = $DB->prepare("SELECT project_id, project_number, name FROM projects WHERE company_id = ? AND (archived = 0 OR archived IS NULL) ORDER BY name LIMIT 500");
    $st->execute([$C]);
    $projects = array_map(fn($p) => ['id' => (int)$p['project_id'], 'name' => trim(($p['project_number'] ? $p['project_number'] . ' · ' : '') . $p['name'])], $st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $ex) {
}
$st = $DB->prepare("SELECT COALESCE(SUM(base_salary_cents),0) FROM employees WHERE company_id = ? AND (termination_date IS NULL OR termination_date >= CURDATE())");
$st->execute([$C]);
$monthly = (int)$st->fetchColumn();
$counts = PayEmployees::list($DB, $C, ['status' => 'all'])['counts'];

$page = 'employees';
$pageTitle = 'Employees';
$pageScripts = ['employees'];
$pageBoot = [
    'items' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'type' => $i['type']], $items),
    'projects' => $projects,
    'banks' => PayKit::BANKS,
    'accountTypes' => PayKit::ACCOUNT_TYPES,
    'types' => PayKit::TYPES,
    'freq' => PayKit::FREQ,
    'freqShort' => PayKit::FREQ_SHORT,
    'leaveReasons' => PayEmployees::LEAVE_REASONS,
    'defaultFrequency' => $PAY_SETTINGS['default_frequency'],
    'hasOwnItems' => (bool)array_filter($items, fn($i) => !$i['system']),
];
include __DIR__ . '/partials/head.php';
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> People</p>
        <h1>Employees</h1>
        <p><?= $counts['active'] ?> on the payroll<?= $monthly ? ' · monthly salaries add up to <b>' . $e(PayKit::money($monthly)) . '</b>' : '' ?>. Changes to someone’s pay update any run that is still being prepared.</p>
    </div>
    <div class="fwp-head__actions">
        <a class="btn" href="/payroll/api/reports.php?op=summary&amp;format=csv&amp;from=<?= $e(PayTax::taxYearStart(date('Y-m-d'))) ?>&amp;to=<?= date('Y-m-d') ?>"><svg class="i"><use href="#i-download"/></svg>Pay this tax year</a>
        <button class="btn btn--primary" type="button" data-add-employee><svg class="i"><use href="#i-plus"/></svg>Add employee</button>
    </div>
</header>

<section class="fwp-card fwp-card--flush">
    <div class="fwp-tools">
        <label class="fwp-search"><svg class="i"><use href="#i-search"/></svg><span class="sr-only">Search employees</span>
            <input type="search" id="empSearch" placeholder="Search by name, number or job" autocomplete="off"></label>
        <div class="fwp-chips" role="group" aria-label="Show">
            <button class="fwp-chip is-on" type="button" data-status="active">On the payroll <small id="cActive"><?= $counts['active'] ?></small></button>
            <button class="fwp-chip" type="button" data-status="left">Left <small id="cLeft"><?= $counts['left'] ?></small></button>
            <button class="fwp-chip" type="button" data-status="all">Everyone <small id="cAll"><?= $counts['all'] ?></small></button>
            <button class="fwp-chip" type="button" data-missing><svg class="i"><use href="#i-alert"/></svg>Missing details</button>
        </div>
        <span class="fwp-grow"></span>
        <select class="select" id="empFreq" style="width:auto;height:34px" aria-label="Pay frequency">
            <option value="">Every frequency</option>
            <?php foreach (PayKit::FREQ as $k => $v): ?><option value="<?= $e($k) ?>"><?= $e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="fwp-table-wrap">
        <table class="fwp-table">
            <thead><tr><th>Employee</th><th class="hide-narrow">Number</th><th class="t-num">Pay</th><th class="hide-narrow">Paid</th><th class="hide-narrow">Started</th><th>Needs</th><th></th></tr></thead>
            <tbody id="empRows"><tr><td colspan="7"><div class="fwp-loading"><span class="fwp-spin"></span>Loading people…</div></td></tr></tbody>
        </table>
    </div>
    <div class="fwp-table-foot" id="empFoot"></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
