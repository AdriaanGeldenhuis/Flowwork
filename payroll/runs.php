<?php
// /payroll/runs.php — every pay run, and starting a new one. The new-run
// dialog fills in the next period and pay day from the settings (the period
// after the last run of that frequency) and says who will be paid.
require_once __DIR__ . '/lib/PayBoot.php';

$C = $PAY_COMPANY_ID;
$st = $DB->prepare("SELECT pay_frequency, COUNT(*) FROM employees WHERE company_id = ? AND (termination_date IS NULL OR termination_date >= CURDATE()) GROUP BY pay_frequency");
$st->execute([$C]);
$byFreq = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
$suggest = [];
foreach (array_keys(PayKit::FREQ) as $f) $suggest[$f] = PayKit::suggest($DB, $C, $f, $PAY_SETTINGS);

$page = 'runs';
$pageTitle = 'Pay runs';
$pageScripts = ['runs'];
$pageBoot = ['freq' => PayKit::FREQ, 'byFreq' => $byFreq, 'suggest' => $suggest, 'defaultFrequency' => $PAY_SETTINGS['default_frequency']];
include __DIR__ . '/partials/head.php';
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> Pay runs</p>
        <h1>Pay runs</h1>
        <p>A run pays everyone on one frequency for one period. Prepare it, approve it, finalise it for payslips and the bank file, then put it in the books.</p>
    </div>
    <div class="fwp-head__actions">
        <button class="btn btn--primary" type="button" data-new-run><svg class="i"><use href="#i-plus"/></svg>New pay run</button>
    </div>
</header>

<section class="fwp-card fwp-card--flush">
    <div class="fwp-tools">
        <div class="fwp-chips" role="group" aria-label="Show">
            <button class="fwp-chip" type="button" data-stage="open">Open <small id="cOpen">·</small></button>
            <button class="fwp-chip" type="button" data-stage="final">Finalised <small id="cFinal">·</small></button>
            <button class="fwp-chip" type="button" data-stage="booked">In the books <small id="cBooked">·</small></button>
            <button class="fwp-chip is-on" type="button" data-stage="all">All <small id="cAll">·</small></button>
        </div>
        <span class="fwp-grow"></span>
        <select class="select" id="runFreq" style="width:auto;height:34px" aria-label="Pay frequency">
            <option value="">Every frequency</option>
            <?php foreach (PayKit::FREQ as $k => $v): ?><option value="<?= $e($k) ?>"><?= $e($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="fwp-table-wrap">
        <table class="fwp-table">
            <thead><tr><th>Run</th><th class="hide-narrow">Period</th><th>Pay day</th><th class="t-num hide-narrow">People</th><th class="t-num">Net pay</th><th class="t-num hide-narrow">Cost to company</th><th>Stage</th></tr></thead>
            <tbody id="runRows"><tr><td colspan="7"><div class="fwp-loading"><span class="fwp-spin"></span>Loading pay runs…</div></td></tr></tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
