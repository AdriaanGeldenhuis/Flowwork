<?php
// /admin/finance.php — Finance: the engine settings the books run on
// (financial year start, VAT basis, two-person journals), saved through
// FinSettings::saveEngine so Finance's own safeguards apply. Everything else
// Finance reads lives in the module itself and is linked from here, with its
// current value, so nothing is set in two places.
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'finance');
$vatRate = null;
try {
    $st = $DB->prepare("SELECT rate_percent FROM gl_tax_codes WHERE company_id = ? AND is_active = 1 AND UPPER(code) IN ('STD', 'STANDARD') ORDER BY code = 'STD' DESC LIMIT 1");
    $st->execute([$ADMIN_COMPANY_ID]);
    $r = $st->fetchColumn();
    if ($r !== false) $vatRate = (float)$r;
} catch (Throwable $ex) {
}
$qi = [];
try {
    $st = $DB->prepare("SELECT default_payment_terms, default_currency, quote_validity_days FROM qi_settings WHERE company_id = ?");
    $st->execute([$ADMIN_COMPANY_ID]);
    $qi = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ex) {
}
$terms = (int)($qi['default_payment_terms'] ?? 30);
$vatRegistered = trim((string)($ADMIN_COMPANY['vat_number'] ?? '')) !== '';
$fyMonth = array_search($values['fiscal_year_start'], AdminSettings::MONTHS, true);
$fyEnd = AdminSettings::MONTHS[($fyMonth + 11) % 12];

$links = [
    ['if-vat', 'VAT and tax codes', $vatRate !== null ? 'Standard rate ' . rtrim(rtrim(number_format($vatRate, 2), '0'), '.') . '%' : 'Rates for every VAT code', '/finances/settings.php#books'],
    ['if-map', 'Account mappings', 'Which accounts sales, VAT, wages and the bank post to', '/finances/settings.php#map'],
    ['if-hourglass', 'Approval thresholds', 'Amounts that need a second look', '/finances/settings.php#limits'],
    ['if-fx', 'Exchange rates', 'For documents in other currencies', '/finances/settings.php#fx'],
    ['i-lock', 'Locked periods', 'Close months so nothing changes after you report', '/finances/period_locks.php'],
    ['if-close', 'Year-end', 'Close the financial year', '/finances/year_end.php'],
];

$page = 'finance';
$pageTitle = 'Finance';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Modules <i>◆</i> Finance</p>
        <h1>Finance</h1>
        <p>Your financial year runs <b><?= $e($values['fiscal_year_start']) ?> to <?= $e($fyEnd) ?></b>, VAT on the <b><?= $values['vat_basis'] === 'payments' ? 'payments' : 'invoice' ?> basis</b><?= $vatRegistered ? '' : ' (no VAT number on your <a href="/admin/company.php#card-tax">profile</a> yet)' ?>.</p>
    </div>
    <div class="fwa-head__actions"><a class="btn" href="/finances/settings.php"><svg class="i"><use href="#if-sliders"/></svg>All Finance settings</a></div>
</header>

<form data-settings="finance" novalidate>
    <?= AdminSettings::render('finance', $values) ?>
</form>

<div class="fwa-section"><h2>More in Finance</h2><span class="fwa-section__rule"></span></div>
<div class="fwa-areas">
    <?php foreach ($links as $i => [$icon, $title, $hint, $href]): ?>
        <a class="fwa-area" href="<?= $e($href) ?>" style="--c:#2f7457; --i:<?= $i ?>"><span class="fwa-area__icon"><svg class="i"><use href="#<?= $icon ?>"/></svg></span>
            <span class="fwa-area__text"><b><?= $e($title) ?></b><small><?= $e($hint) ?></small></span></a>
    <?php endforeach; ?>
</div>

<div class="fwa-section"><h2>Invoice defaults</h2><span class="fwa-section__rule"></span><small>Set in Quotes &amp; Invoices</small></div>
<section class="fwa-card">
    <div class="fwa-card__body" style="padding-top:18px">
        <dl class="fwa-kv">
            <dt>Payment terms</dt><dd><?= $terms === 0 ? 'Due on receipt' : $terms . ' days' ?></dd>
            <dt>Quotes are valid for</dt><dd><?= (int)($qi['quote_validity_days'] ?? 14) ?> days</dd>
            <dt>Currency</dt><dd><?= $e($qi['default_currency'] ?? 'ZAR') ?> — the books are kept in rand</dd>
        </dl>
    </div>
    <footer class="fwa-card__foot"><a class="btn btn--sm" href="/qi/settings.php#defaults"><svg class="i"><use href="#i-pen"/></svg>Change invoice defaults</a></footer>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
