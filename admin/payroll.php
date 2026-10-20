<?php
// /admin/payroll.php — Payroll: the pay schedule the run wizard starts with
// and whether finished runs post to Finance by themselves. Saved to the
// payroll_settings table Payroll reads (run_new.php, run_update_status.php);
// tax tables, pay items and overtime stay in Payroll's own settings.
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'payroll');
$employees = 0;
$lastRun = null;
try {
    $st = $DB->prepare("SELECT COUNT(*) FROM employees WHERE company_id = ? AND (termination_date IS NULL OR termination_date > CURDATE())");
    $st->execute([$ADMIN_COMPANY_ID]);
    $employees = (int)$st->fetchColumn();
} catch (Throwable $ex) {
    try {
        $st = $DB->prepare("SELECT COUNT(*) FROM employees WHERE company_id = ?");
        $st->execute([$ADMIN_COMPANY_ID]);
        $employees = (int)$st->fetchColumn();
    } catch (Throwable $ex2) {
    }
}
try {
    $st = $DB->prepare("SELECT name, pay_date, status FROM pay_runs WHERE company_id = ? ORDER BY pay_date DESC, id DESC LIMIT 1");
    $st->execute([$ADMIN_COMPANY_ID]);
    $lastRun = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $ex) {
}
$day = (int)$values['monthly_anchor_day'];
$next = new DateTimeImmutable('first day of next month');
$nextPay = $next->setDate((int)$next->format('Y'), (int)$next->format('n'), min($day, (int)$next->format('t')));
$thisMonth = (new DateTimeImmutable('today'));
$thisPay = $thisMonth->setDate((int)$thisMonth->format('Y'), (int)$thisMonth->format('n'), min($day, (int)$thisMonth->format('t')));
if ($thisPay >= $thisMonth) $nextPay = $thisPay;

$page = 'payroll';
$pageTitle = 'Payroll';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Modules <i>◆</i> Payroll</p>
        <h1>Payroll</h1>
        <p><?= $employees ?> employee<?= $employees === 1 ? '' : 's' ?> on the payroll<?= $values['default_frequency'] === 'monthly' ? ' · the next monthly pay day is <b>' . $e($nextPay->format('l j F')) . '</b>' : '' ?><?= $lastRun ? ' · last run: ' . $e($lastRun['name'] ?: 'pay run') . ' (' . $e(AdminKit::date($lastRun['pay_date'])) . ', ' . $e($lastRun['status']) . ')' : '' ?>.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/payroll/settings.php"><svg class="i"><use href="#if-sliders"/></svg>Payroll settings</a>
        <a class="btn" href="/payroll/"><svg class="i"><use href="#i-wallet"/></svg>Open Payroll</a>
    </div>
</header>

<form data-settings="payroll" novalidate>
    <?= AdminSettings::render('payroll', $values) ?>
</form>

<div class="fwa-banner" style="margin-top:16px"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">Tax tables, pay items, overtime rules and bank export formats are in <a href="/payroll/settings.php">Payroll settings</a>. Payslips are emailed from the company’s first connected mailbox — see <a href="/admin/mail.php">Mail</a>.</div></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
