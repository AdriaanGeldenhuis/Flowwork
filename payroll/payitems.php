<?php
// /payroll/payitems.php — the things that can appear on a payslip, grouped by
// kind, with how each is taxed, its SARS source code and the Finance account
// it posts to. Drawn by js/items.js from /payroll/api/items.php.
require_once __DIR__ . '/lib/PayBoot.php';

$page = 'payitems';
$pageTitle = 'Pay items';
$pageScripts = ['items'];
$pageBoot = ['types' => PayKit::ITEM_TYPES];
include __DIR__ . '/partials/head.php';
?>
<header class="fwp-head">
    <div class="fwp-head__text">
        <p class="fwp-eyebrow">Payroll <i>◆</i> Pay items</p>
        <h1>Pay items</h1>
        <p>Everything besides the basic salary that can go on a payslip — allowances, bonuses, deductions and benefits. Each one knows how SARS taxes it and where it goes in the books.</p>
    </div>
    <div class="fwp-head__actions">
        <button class="btn btn--primary" type="button" data-new-item><svg class="i"><use href="#i-plus"/></svg>New pay item</button>
    </div>
</header>
<div id="itemsApp"><div class="fwp-loading"><span class="fwp-spin"></span>Loading pay items…</div></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
