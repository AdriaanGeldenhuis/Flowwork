<?php
// /admin/data.php — Data & privacy: what the company holds, CSV exports
// (/admin/api/export.php) and how long the audit trail is kept (applied
// hourly by includes/audit_retention.php from the background runner).
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/lib/AdminAudit.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'data');
$counts = AdminAudit::counts($DB, $ADMIN_COMPANY_ID);
$oldest = null;
try {
    $st = $DB->prepare("SELECT MIN(COALESCE(timestamp, created_at)) FROM audit_log WHERE company_id = ?");
    $st->execute([$ADMIN_COMPANY_ID]);
    $oldest = $st->fetchColumn() ?: null;
} catch (Throwable $ex) {
}
$exports = [
    ['people', 'i-users', 'People', 'Names, emails, roles, seats and last sign-in'],
    ['customers', 'i-building', 'Customers', 'From the CRM, with registration and VAT numbers'],
    ['suppliers', 'i-truck', 'Suppliers', 'From the CRM, with registration and VAT numbers'],
    ['projects', 'i-kanban', 'Projects', 'Every project with status, client, dates and budget'],
    ['audit', 'i-history', 'Audit trail', 'Everything that changed (up to 20 000 entries)'],
    ['summary', 'ia-database', 'Summary', 'How many of each record the company holds'],
];

$page = 'data';
$pageTitle = 'Data & privacy';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Workspace <i>◆</i> Data &amp; privacy</p>
        <h1>Data &amp; <em>privacy</em></h1>
        <p>Your data is yours. Take a copy whenever you like, and decide how long the record of who-did-what is kept (POPIA asks you not to keep personal information longer than you need).</p>
    </div>
</header>

<section class="fwa-card" id="card-export">
    <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-download"/></svg></span>
        <div class="fwa-card__title"><h3>Export</h3><p>CSV files that open in Excel or Google Sheets. Each download is noted in the audit trail.</p></div></header>
    <div class="fwa-actions">
        <?php foreach ($exports as [$type, $icon, $title, $hint]): ?>
            <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#<?= $icon ?>"/></svg></span>
                <span class="fwa-action__text"><b><?= $e($title) ?></b><small><?= $e($hint) ?></small></span>
                <a class="fwa-mini" href="/admin/api/export.php?type=<?= $type ?>" download><svg class="i"><use href="#i-download"/></svg>Download</a></div>
        <?php endforeach; ?>
    </div>
    <div class="fwa-table-foot"><svg class="i i--sm"><use href="#i-info"/></svg>Invoices, quotes and the books have their own exports, with every line: in <a href="/qi/">Quotes &amp; Invoices</a> and <a href="/finances/reports.php">Finance › Reports</a>. Files live in <a href="/drive/">Drive</a>.</div>
</section>

<form data-settings="data" novalidate style="margin-top:16px">
    <?= AdminSettings::render('data', $values) ?>
</form>
<?php if ($oldest): ?><p class="hint" style="margin:8px 4px 0">The oldest entry in your audit trail is from <?= $e(AdminKit::date($oldest)) ?>.</p><?php endif; ?>

<div class="fwa-section"><h2>What you hold</h2><span class="fwa-section__rule"></span></div>
<div class="fwa-stats">
    <?php $i = 0; foreach ($counts as $label => $n): if ($n === null) continue; ?>
        <div class="fwa-stat" style="--i:<?= $i++ ?>"><span class="fwa-stat__label"><?= $e($label) ?></span><span class="fwa-stat__value"><?= number_format($n, 0, '.', ' ') ?></span></div>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
