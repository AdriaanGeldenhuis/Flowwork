<?php
// /payroll/settings.php — how payroll runs for this company: the pay
// schedule, overtime, SARS details, posting to Finance, the variance check
// and the SARS tax tables. Admins change them; bookkeepers can look.
// Saved through /payroll/api/settings.php (the same payroll_settings row
// Admin → Payroll edits).
require_once __DIR__ . '/lib/PayBoot.php';

$S = PaySettings::load($DB, $PAY_COMPANY_ID);
$tax = PaySettings::taxTables($DB, $PAY_COMPANY_ID);
$canEdit = PayKit::canConfigure($PAY_ROLE);
$dis = $canEdit ? '' : ' disabled';

$row = function (string $key, string $label, string $hint, string $control, string $cls = '') use ($e) {
    return '<div class="fwp-set ' . $cls . '" id="set-' . $e($key) . '"><div class="fwp-set__text"><label class="fwp-set__label" for="s-' . $e($key) . '">' . $e($label) . '</label>'
         . ($hint !== '' ? '<span class="fwp-set__hint">' . $hint . '</span>' : '') . '</div><div class="fwp-set__control">' . $control . '</div></div>';
};
$toggle = function (string $key, string $label, string $hint, bool $on) use ($e, $dis) {
    return '<div class="fwp-set fwp-set--toggle" id="set-' . $e($key) . '"><div class="fwp-set__text"><label class="fwp-set__label" for="s-' . $e($key) . '">' . $e($label) . '</label><span class="fwp-set__hint">' . $hint . '</span></div>'
         . '<div class="fwp-set__control"><label class="switch"><input type="checkbox" id="s-' . $e($key) . '" name="' . $e($key) . '"' . ($on ? ' checked' : '') . $dis . '><span class="switch__track"></span></label></div></div>';
};
$num = fn(string $key, $value, string $unit, string $attrs = '') => '<div class="fwp-affix"><input class="input" id="s-' . $e($key) . '" name="' . $e($key) . '" type="number" value="' . $e($value) . '" ' . $attrs . $dis . '><span>' . $e($unit) . '</span></div>';
$sel = function (string $key, array $opts, $value) use ($e, $dis) {
    $h = '<select class="select" id="s-' . $e($key) . '" name="' . $e($key) . '"' . $dis . '>';
    foreach ($opts as $k => $v) $h .= '<option value="' . $e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . $e($v) . '</option>';
    return $h . '</select>';
};
$fmtMult = fn(float $m) => rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');

$page = 'settings';
$pageTitle = 'Settings';
$pageScripts = ['settings'];
$pageBoot = ['tax' => $tax, 'canEdit' => $canEdit];
include __DIR__ . '/partials/head.php';
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> Settings</p>
        <h1>Settings</h1>
        <p>How payroll works for <?= $e($PAY_COMPANY['name']) ?>. Changes apply to runs started — or worked out again — after you save.</p>
    </div>
</header>
<?php if (!$canEdit): ?>
<div class="fwp-banner"><svg class="i"><use href="#i-lock"/></svg><div class="fwp-banner__body">Only an admin can change these. You can see how payroll is set up.</div></div>
<?php endif; ?>

<form data-settings="payroll" novalidate>
    <section class="fwp-card fwp-card--flush">
        <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#i-calendar"/></svg></span>
            <div class="fwp-card__title"><h3>Pay schedule</h3><p>What a new pay run starts with. You can change the dates on any run.</p></div></div>
        <?= $row('default_frequency', 'Usual pay frequency', 'The frequency a new run starts on.', $sel('default_frequency', PayKit::FREQ, $S['default_frequency'])) ?>
        <?= $row('monthly_anchor_day', 'Monthly pay day', 'Short months use their last day; a weekend moves to the Friday before.', $num('monthly_anchor_day', $S['monthly_anchor_day'], 'of the month', 'min="1" max="31" step="1"')) ?>
        <?= $row('weekly_anchor_day', 'Weekly and two-weekly pay day', 'Weekly and two-weekly periods end on this day, and are paid on it.', $sel('weekly_anchor_day', PayKit::WEEKDAYS, $S['weekly_anchor_day'])) ?>
    </section>

    <section class="fwp-card fwp-card--flush">
        <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#i-clock"/></svg></span>
            <div class="fwp-card__title"><h3>Hours &amp; overtime</h3><p>The Basic Conditions of Employment Act sets 1.5× for overtime and, for most people, 2× for Sundays and public holidays.</p></div></div>
        <?= $row('hours_per_week', 'Normal hours a week', 'Turns a salary into an hourly rate for overtime: salary ÷ (hours × 52 ÷ 12). Someone with their own hourly rate uses that.', $num('hours_per_week', $S['hours_per_week'], 'hours', 'min="1" max="80" step="0.5"')) ?>
        <?= $row('ot_weekday', 'Overtime', '', $num('ot_weekday', $fmtMult($S['overtime']['weekday']), '× the hourly rate', 'min="1" max="3" step="0.05"')) ?>
        <?= $row('ot_sunday', 'Sunday work', '', $num('ot_sunday', $fmtMult($S['overtime']['sunday']), '× the hourly rate', 'min="1" max="3" step="0.05"')) ?>
        <?= $row('ot_holiday', 'Public holiday work', '', $num('ot_holiday', $fmtMult($S['overtime']['holiday']), '× the hourly rate', 'min="1" max="3" step="0.05"')) ?>
    </section>

    <section class="fwp-card fwp-card--flush">
        <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#ip-sars"/></svg></span>
            <div class="fwp-card__title"><h3>SARS</h3><p>Your registration as an employer.</p></div></div>
        <?= $row('paye_reference', 'PAYE reference number', 'Starts with 7 and has 10 digits. Printed on payslips and used for EMP201 and EMP501.', '<input class="input fwp-mono" id="s-paye_reference" name="paye_reference" value="' . $e($S['paye_reference']) . '" maxlength="12" inputmode="numeric" placeholder="7…"' . $dis . '>') ?>
        <?= $toggle('sdl_liable', 'We pay the Skills Development Levy', 'Employers whose total pay is more than R500 000 a year pay 1% SDL. Switch off if you are below that.', $S['sdl_liable']) ?>
    </section>

    <section class="fwp-card fwp-card--flush" id="finance">
        <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#if-ledger"/></svg></span>
            <div class="fwp-card__title"><h3>Finance</h3><p>How a finalised run goes into the books.</p></div></div>
        <?php
        $bankOpts = ['' => $S['bank_accounts'] ? 'Choose an account' : 'No bank accounts in Finance yet'];
        foreach ($S['bank_accounts'] as $b) $bankOpts[$b['id']] = $b['name'] . ' (' . $b['code'] . ')';
        echo $row('bank_account_id', 'Salaries are paid from', $S['bank_accounts'] ? 'Net pay is credited to this bank account in the books.' : 'Add your bank account under <a href="/finances/#bank">Finance → Bank</a> first.', $sel('bank_account_id', $bankOpts, $S['bank_account_id'] ?? ''));
        echo $toggle('auto_post_to_finance', 'Put runs in the books when they are finalised', 'Saves a step. Off: post each run yourself from the run.', $S['auto_post_to_finance']);
        ?>
        <div class="fwp-set fwp-set--wide"><div class="fwp-set__text"><span class="fwp-set__label">Posts to</span>
            <span class="fwp-set__hint">Set in <a href="/finances/settings.php">Finance settings</a>. Deductions go to the account on each pay item, else Salaries &amp; Wages Payable.</span></div>
            <div class="fwp-set__control"><dl class="fwp-kv" style="width:100%">
                <?php foreach ($S['posts_to'] as $p): ?><dt><?= $e($p['label']) ?></dt><dd><span class="fwp-mono"><?= $e($p['code']) ?></span> <?= $e($p['name']) ?></dd><?php endforeach; ?>
            </dl></div></div>
    </section>

    <section class="fwp-card fwp-card--flush">
        <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#if-scale"/></svg></span>
            <div class="fwp-card__title"><h3>Checks</h3><p>Help with spotting mistakes before anyone is paid.</p></div></div>
        <?= $row('variance_pct', 'Flag a change in net pay of more than', 'Compared with each person’s last finalised pay. Shown on the run so you can check it.', $num('variance_pct', rtrim(rtrim(number_format($S['variance_pct'], 2, '.', ''), '0'), '.'), '%', 'min="0" max="100" step="1"')) ?>
    </section>
</form>

<section class="fwp-card" id="tax" style="margin-top:16px">
    <div class="fwp-card__head"><span class="fwp-card__icon"><svg class="i"><use href="#ip-calc"/></svg></span>
        <div class="fwp-card__title"><h3>Tax tables</h3><p>The SARS brackets, rebates, medical credits and UIF ceiling PAYE is worked out with. Flowwork keeps them up to date from sars.gov.za.</p></div>
        <?php if ($canEdit): ?><button class="fwp-mini" type="button" data-tax-new<?= $tax['has_next'] ? ' hidden' : '' ?>><svg class="i"><use href="#i-plus"/></svg>Add <?= $e($tax['next_label']) ?></button><?php endif; ?></div>
    <div class="fwp-card__body" id="taxBox"></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
