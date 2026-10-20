<?php
// /payroll/reports.php — what is owed to SARS and what pay cost:
// EMP201 (monthly), the EMP501 reconciliation (interim and annual) with the
// per-employee tax certificate figures, a pay summary and wage cost by
// project. Every report downloads as a spreadsheet. Drawn by js/reports.js.
require_once __DIR__ . '/lib/PayBoot.php';

$st = $DB->prepare("SELECT MIN(pay_date) FROM pay_runs WHERE company_id = ?");
$st->execute([$PAY_COMPANY_ID]);
$first = $st->fetchColumn() ?: date('Y-m-d');
$years = [];
for ($y = (int)substr(PayTax::taxYearStart($first), 0, 4); $y <= (int)substr(PayTax::taxYearStart(date('Y-m-d')), 0, 4); $y++) $years[$y . '-03-01'] = PayTax::taxYearLabel($y . '-03-01');
krsort($years);

$page = 'reports';
$pageTitle = 'SARS & reports';
$pageScripts = ['reports'];
$pageBoot = [
    'years' => $years,
    'currentYear' => PayTax::taxYearStart(date('Y-m-d')),
    'month' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m', strtotime('first day of last month')),
    'payeRef' => $PAY_SETTINGS['paye_reference'],
];
include __DIR__ . '/partials/head.php';
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> SARS &amp; reports</p>
        <h1>SARS &amp; reports</h1>
        <p>Figures come from finalised runs only. Use them to fill in your returns on SARS eFiling and e@syFile<?= $PAY_SETTINGS['paye_reference'] ? ' for PAYE reference <b>' . $e($PAY_SETTINGS['paye_reference']) . '</b>' : '' ?>.</p>
    </div>
</header>
<div class="fwp-chips" role="tablist" aria-label="Reports" style="margin-bottom:16px">
    <button class="fwp-chip" type="button" role="tab" data-rep="emp201"><svg class="i"><use href="#ip-sars"/></svg>EMP201 · monthly</button>
    <button class="fwp-chip" type="button" role="tab" data-rep="recon"><svg class="i"><use href="#if-scale"/></svg>EMP501 · reconciliation</button>
    <button class="fwp-chip" type="button" role="tab" data-rep="summary"><svg class="i"><use href="#i-users"/></svg>Pay summary</button>
    <button class="fwp-chip" type="button" role="tab" data-rep="costs"><svg class="i"><use href="#i-briefcase"/></svg>Cost by project</button>
</div>
<div id="repApp"></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
