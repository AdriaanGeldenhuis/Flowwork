<?php
// /admin/suppliers.php — Suppliers: how Suppliers AI searches (ai_settings,
// read by SaKit::settings in suppliers_ai/lib — searches and requests for quotes) and the
// compliance-document rules the CRM enforces (crm_block_expired_suppliers,
// crm_reminder_days via CrmCompliance::policy).
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'suppliers');
$suppliers = 0;
$expired = 0;
$expiring = 0;
try {
    $st = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
    $st->execute([$ADMIN_COMPANY_ID]);
    $suppliers = (int)$st->fetchColumn();
    $window = max(array_map('intval', explode(',', $values['crm_reminder_days'])) ?: [30]);
    $st = $DB->prepare("SELECT SUM(d.expiry_date < CURDATE()), SUM(d.expiry_date >= CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY))
                          FROM crm_compliance_docs d JOIN crm_accounts a ON a.id = d.account_id AND a.deleted_at IS NULL
                         WHERE d.company_id = ? AND d.expiry_date IS NOT NULL");
    $st->execute([$window, $ADMIN_COMPANY_ID]);
    [$expired, $expiring] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
} catch (Throwable $ex) {
}

$page = 'suppliers';
$pageTitle = 'Suppliers';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Modules <i>◆</i> Suppliers</p>
        <h1>Suppliers</h1>
        <p><?= $suppliers ?> supplier<?= $suppliers === 1 ? '' : 's' ?> in the CRM<?= $expired ? ' · <b>' . $expired . ' compliance document' . ($expired === 1 ? ' has' : 's have') . ' expired</b>' : '' ?><?= $expiring ? ' · ' . $expiring . ' expiring soon' : '' ?>.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/crm/#compliance"><svg class="i"><use href="#i-shield"/></svg>Compliance in the CRM</a>
        <a class="btn" href="/suppliers_ai/"><svg class="i"><use href="#i-sparkle"/></svg>Open Suppliers AI</a>
    </div>
</header>

<form data-settings="suppliers" novalidate>
    <?= AdminSettings::render('suppliers', $values) ?>
</form>

<div class="fwa-banner" style="margin-top:16px"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">Which documents each supplier needs (BEE, tax clearance, insurance…) and how long they last are set under <a href="/crm/settings.php#compliance">CRM › Make it yours › Compliance</a>. Search words, synonyms and categories are in <a href="/suppliers_ai/settings.php">Suppliers AI settings</a>.</div></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
