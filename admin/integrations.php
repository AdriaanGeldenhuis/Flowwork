<?php
// /admin/integrations.php — Integrations: Yoco card payments for invoices.
// The secret key (QiYoco::checkout) and the webhook signing secret
// (webhooks/yoco.php) are saved through the settings registry; they are never
// sent back to the browser — the page only says whether each is set.
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'payments');
$keySet = $values['yoco_sec_key'] === '__set__';
$hookSet = $values['yoco_webhook_secret'] === '__set__';
$live = ($ADMIN_COMPANY['yoco_mode'] ?? 'test') === 'live';
$webhookUrl = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : '', '/') . '/webhooks/yoco.php';
$paid = null;
try {
    $st = $DB->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0), MAX(payment_date) FROM payments WHERE company_id = ? AND method = 'yoco' AND refunded_at IS NULL");
    $st->execute([$ADMIN_COMPANY_ID]);
    $paid = $st->fetch(PDO::FETCH_NUM);
} catch (Throwable $ex) {
}

$page = 'integrations';
$pageTitle = 'Integrations';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Workspace <i>◆</i> Integrations</p>
        <h1>Integrations</h1>
        <p>Let customers pay invoices by card. Yoco handles the card details on its own secure page; Flowwork records the payment on the invoice by itself.</p>
    </div>
</header>

<?php if ($keySet && $hookSet): ?>
    <div class="fwa-banner fwa-banner--ok"><svg class="i"><use href="#i-check"/></svg><div class="fwa-banner__body"><b>Card payments are on<?= $live ? '' : ' in test mode' ?>.</b> Every invoice you send carries a “Pay now” button<?= $live ? '' : ' — test keys take test cards only, no real money moves' ?>.</div></div>
<?php elseif ($keySet): ?>
    <div class="fwa-banner fwa-banner--warn"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b>Almost there.</b> Customers can pay, but without the webhook signing secret Flowwork cannot confirm the payment, so invoices are not marked paid by themselves.</div></div>
<?php else: ?>
    <div class="fwa-banner"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">Not set up yet. You need a Yoco account (<a href="https://www.yoco.com/za/" target="_blank" rel="noopener">yoco.com</a>) — then follow the three steps below.</div></div>
<?php endif; ?>

<section class="fwa-card" id="card-yoco-steps">
    <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-list-num"/></svg></span>
        <div class="fwa-card__title"><h3>Set-up in three steps</h3><p>About five minutes, in the Yoco portal and here.</p></div></header>
    <div class="fwa-steps">
        <div class="fwa-step<?= $keySet ? ' is-done' : '' ?>"><span class="fwa-step__dot"><?= $keySet ? '<svg class="i"><use href="#i-check"/></svg>' : '1' ?></span><span class="fwa-step__text"><b>Copy your secret key</b><small>In your Yoco portal, open the API keys section and copy the secret key. Paste it below.</small></span></div>
        <div class="fwa-step<?= $hookSet ? ' is-done' : '' ?>"><span class="fwa-step__dot"><?= $hookSet ? '<svg class="i"><use href="#i-check"/></svg>' : '2' ?></span><span class="fwa-step__text"><b>Register Flowwork’s webhook address</b><small>In the same place, add a webhook with the address below. Yoco then shows a signing secret once.</small></span></div>
        <div class="fwa-step<?= $hookSet ? ' is-done' : '' ?>"><span class="fwa-step__dot"><?= $hookSet ? '<svg class="i"><use href="#i-check"/></svg>' : '3' ?></span><span class="fwa-step__text"><b>Paste the signing secret below and save</b><small>Payments are then matched to invoices automatically.</small></span></div>
    </div>
    <div class="fwa-card__body">
        <span class="label">Webhook address</span>
        <div class="fwa-secret" style="margin-top:7px"><code id="hookUrl"><?= $e($webhookUrl) ?></code><button class="btn btn--sm" type="button" data-copy-text="<?= $e($webhookUrl) ?>"><svg class="i i--sm"><use href="#i-copy"/></svg>Copy</button></div>
    </div>
</section>

<form data-settings="payments" novalidate style="margin-top:16px">
    <?= AdminSettings::render('payments', $values) ?>
</form>

<?php if ($keySet || $hookSet): ?>
<section class="fwa-card" style="margin-top:16px">
    <div class="fwa-actions">
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#ia-card"/></svg></span>
            <span class="fwa-action__text"><b><?= $live ? 'Live mode' : 'Test mode' ?></b><small><?= $live ? 'Real cards, real money.' : 'Switch to live by pasting your sk_live_ key.' ?><?php if ($paid && (int)$paid[0]): ?> · <?= (int)$paid[0] ?> card payment<?= (int)$paid[0] === 1 ? '' : 's' ?> recorded, last on <?= $e(AdminKit::date($paid[2])) ?><?php endif; ?></small></span></div>
        <div class="fwa-action"><span class="fwa-action__icon" style="color:var(--danger);background:var(--danger-soft)"><svg class="i"><use href="#ia-key"/></svg></span>
            <span class="fwa-action__text"><b>Disconnect Yoco</b><small>Removes both keys. Invoices stop offering card payment; payments already made are not affected.</small></span>
            <button class="btn btn--sm btn--danger" type="button" id="yocoDisconnect">Disconnect</button></div>
    </div>
</section>
<?php endif; ?>
<?php
$pageScripts = ['integrations'];
include __DIR__ . '/partials/foot.php';
