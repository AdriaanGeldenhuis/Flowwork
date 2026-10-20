<?php
// /admin/billing.php — Subscription: the plan, what is used of it (paid
// seats in this company, companies on your account), moving to another plan
// when everything fits, and cancelling or resuming. Changes go to
// /admin/api/billing.php.
require_once __DIR__ . '/lib/AdminBoot.php';
@include_once __DIR__ . '/../shared/email_config.php';

$c = $ADMIN_COMPANY;
$plans = $DB->query("SELECT * FROM plans ORDER BY price_monthly_cents, id")->fetchAll(PDO::FETCH_ASSOC);
$sub = null;
try {
    $st = $DB->prepare("SELECT * FROM subscriptions WHERE company_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $st->execute([$ADMIN_COMPANY_ID]);
    $sub = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $ex) {
}
$seats = AdminKit::seats($DB, $ADMIN_COMPANY_ID);
$companies = fw_user_company_count($ADMIN_USER_ID);
$maxCompanies = (int)($c['plan_max_companies'] ?? $c['max_companies'] ?? 1);
$periodEnd = $sub && $sub['current_period_end'] ? strtotime($sub['current_period_end']) : null;
$support = defined('EMAIL_REPLY_TO') ? EMAIL_REPLY_TO : 'support@flowwork.co.za';
$status = $sub['status'] ?? 'active';
$statusPill = ['active' => ['success', 'Active'], 'pending' => ['gold', 'Starting'], 'past_due' => ['danger', 'Payment overdue'], 'canceled' => ['danger', 'Cancelled']][$status] ?? ['muted', ucfirst($status)];
$features = ['Every module: Projects, CRM, Quotes & Invoices, Finance, Payroll, Mail, Calendar and Drive', 'The Flowwork phone app'];

$page = 'billing';
$pageTitle = 'Subscription';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Account <i>◆</i> Subscription</p>
        <h1>Your <em>subscription</em></h1>
        <p>Every plan includes every module — plans differ only in how many people and companies they hold.</p>
    </div>
</header>

<?php if ($status === 'canceled'): ?>
    <div class="fwa-banner fwa-banner--danger"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b>Your subscription is cancelled.</b> <?= $periodEnd && $periodEnd > time() ? 'You keep full access until ' . $e(date('j F Y', $periodEnd)) . '.' : '' ?> Changed your mind? Resume it below.</div></div>
<?php elseif ($status === 'past_due'): ?>
    <div class="fwa-banner fwa-banner--danger"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b>A payment is overdue.</b> Please settle your latest Flowwork invoice, or write to <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a> if something is wrong.</div></div>
<?php endif; ?>

<div class="fwa-stats">
    <div class="fwa-stat" style="--i:0"><span class="fwa-stat__label">Plan</span><span class="fwa-stat__value"><?= $e($c['plan_name'] ?? '—') ?></span>
        <span class="fwa-stat__sub"><span class="fwa-pill" data-tone="<?= $statusPill[0] ?>"><?= $e($statusPill[1]) ?></span></span></div>
    <div class="fwa-stat" style="--i:1"><span class="fwa-stat__label">Monthly</span><span class="fwa-stat__value"><?= $e(AdminKit::money((int)($c['price_monthly_cents'] ?? 0))) ?></span>
        <span class="fwa-stat__sub"><?= $periodEnd ? ($periodEnd > time() ? 'Current period ends ' . $e(date('j M Y', $periodEnd)) : 'Billed monthly') : 'Billed monthly' ?></span></div>
    <div class="fwa-stat" style="--i:2"><span class="fwa-stat__label">Paid seats</span><span class="fwa-stat__value"><?= (int)$seats['used'] ?><small>/ <?= (int)$seats['max'] ?></small></span>
        <span class="fwa-meter<?= $seats['used'] >= $seats['max'] ? ' is-full' : '' ?>"><i style="--p:<?= min(100, round($seats['used'] / $seats['max'] * 100)) ?>%"></i></span><span class="fwa-stat__sub">in <?= $e($c['name']) ?></span></div>
    <div class="fwa-stat" style="--i:3"><span class="fwa-stat__label">Companies</span><span class="fwa-stat__value"><?= $companies ?><small>/ <?= $maxCompanies ?></small></span>
        <span class="fwa-meter<?= $companies >= $maxCompanies ? ' is-full' : '' ?>"><i style="--p:<?= min(100, round($companies / max(1, $maxCompanies) * 100)) ?>%"></i></span><span class="fwa-stat__sub">on your account</span></div>
</div>

<div class="fwa-section" id="card-plans"><h2>Plans</h2><span class="fwa-section__rule"></span><small>Prices per month</small></div>
<div class="fwa-plans">
    <?php foreach ($plans as $p): $current = (int)$p['id'] === (int)($c['plan_id'] ?? 0);
        $fitsSeats = $seats['used'] <= (int)$p['max_users']; $fitsCos = $companies <= (int)$p['max_companies'];
        $up = (int)$p['price_monthly_cents'] > (int)($c['price_monthly_cents'] ?? 0); ?>
        <article class="fwa-plan<?= $current ? ' is-current' : '' ?>">
            <?php if ($current): ?><span class="fwa-plan__badge">Your plan</span><?php endif; ?>
            <h3><?= $e($p['name']) ?></h3>
            <div class="fwa-plan__price"><?= $e(AdminKit::money((int)$p['price_monthly_cents'])) ?> <small>a month</small></div>
            <ul>
                <li><svg class="i"><use href="#i-users"/></svg><?= (int)$p['max_users'] ?> paid seat<?= (int)$p['max_users'] === 1 ? '' : 's' ?> <span class="fwa-muted">+ free bookkeepers</span></li>
                <li><svg class="i"><use href="#i-building"/></svg><?= (int)$p['max_companies'] ?> compan<?= (int)$p['max_companies'] === 1 ? 'y' : 'ies' ?></li>
                <?php foreach ($features as $f): ?><li><svg class="i"><use href="#i-check"/></svg><?= $e($f) ?></li><?php endforeach; ?>
            </ul>
            <?php if ($current): ?>
                <button class="btn btn--block" type="button" disabled>Current plan</button>
            <?php elseif (!$fitsSeats || !$fitsCos): ?>
                <button class="btn btn--block" type="button" disabled title="Too small for what you use now">Too small for you now</button>
                <small class="fwa-muted"><?= !$fitsSeats ? $seats['used'] . ' paid seats in use' : $companies . ' companies on your account' ?></small>
            <?php else: ?>
                <button class="btn btn--block<?= $up ? ' btn--primary' : '' ?>" type="button" data-plan="<?= (int)$p['id'] ?>" data-name="<?= $e($p['name']) ?>" data-price="<?= $e(AdminKit::money((int)$p['price_monthly_cents'])) ?>" data-up="<?= $up ? '1' : '0' ?>"><?= $up ? 'Upgrade to ' : 'Move to ' ?><?= $e($p['name']) ?></button>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>

<section class="fwa-card" style="margin-top:22px">
    <div class="fwa-actions">
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#i-receipt"/></svg></span>
            <span class="fwa-action__text"><b>Invoices and payment</b><small>Questions about a bill, or need a copy of a Flowwork invoice? Write to us.</small></span>
            <a class="fwa-mini" href="mailto:<?= $e($support) ?>?subject=<?= rawurlencode('Billing — ' . ($c['name'] ?? '')) ?>"><svg class="i"><use href="#i-mail"/></svg>Email billing</a></div>
        <?php if ($sub): ?>
        <div class="fwa-action"><span class="fwa-action__icon"<?= $status === 'canceled' ? '' : ' style="color:var(--danger);background:var(--danger-soft)"' ?>><svg class="i"><use href="#<?= $status === 'canceled' ? 'i-play' : 'ia-pause' ?>"/></svg></span>
            <span class="fwa-action__text"><b><?= $status === 'canceled' ? 'Resume the subscription' : 'Cancel the subscription' ?></b><small><?= $status === 'canceled' ? 'Carry on as before — nothing was deleted.' : 'Flowwork stops renewing it. Your data stays, and you can export everything under Data & privacy.' ?></small></span>
            <button class="btn btn--sm<?= $status === 'canceled' ? ' btn--primary' : ' btn--danger' ?>" type="button" id="subToggle" data-action="<?= $status === 'canceled' ? 'resume' : 'cancel' ?>"><?= $status === 'canceled' ? 'Resume' : 'Cancel subscription' ?></button></div>
        <?php endif; ?>
    </div>
</section>
<?php
$pageScripts = ['billing'];
$pageBoot = ['periodEnd' => $periodEnd ? date('j F Y', $periodEnd) : null];
include __DIR__ . '/partials/foot.php';
