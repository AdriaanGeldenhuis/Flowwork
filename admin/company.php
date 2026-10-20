<?php
// /admin/company.php — Company profile: the business details every module
// prints and reports (quotes, invoices, statements, payslips, VAT returns).
// Saved through /admin/api/company.php with the shared unsaved-changes bar;
// the logo goes through the document designer's upload (resized to WebP);
// bank details are shown here and changed in Quotes & Invoices, where any
// change alerts the other admins.
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/../includes/business_types.php';

$c = $ADMIN_COMPANY;
$holder = '';
try {
    $st = $DB->prepare("SELECT bank_account_holder FROM qi_settings WHERE company_id = ?");
    $st->execute([$ADMIN_COMPANY_ID]);
    $holder = (string)($st->fetchColumn() ?: '');
} catch (Throwable $ex) {
}
$val = fn(string $k) => $e((string)($c[$k] ?? ''));
$provinces = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];
$countries = ['South Africa', 'Namibia', 'Botswana', 'Zimbabwe', 'Mozambique', 'Eswatini', 'Lesotho', 'Zambia', 'Malawi', 'Kenya', 'Nigeria', 'United Kingdom'];

$field = function (string $key, string $label, array $o = []) use ($e, $c): string {
    $value = (string)($c[$key] ?? ($o['default'] ?? ''));
    $attrs = '';
    foreach (['maxlength', 'placeholder', 'inputmode', 'autocomplete', 'list', 'type', 'pattern'] as $a) if (isset($o[$a])) $attrs .= ' ' . $a . '="' . $e($o[$a]) . '"';
    if (!isset($o['type'])) $attrs .= ' type="text"';
    return '<div class="field' . (!empty($o['full']) ? ' is-full' : '') . '"><label class="label" for="f-' . $key . '">' . $e($label) . (!empty($o['req']) ? '<span class="req">*</span>' : '') . '</label>'
         . '<input class="input" id="f-' . $key . '" name="' . $key . '" value="' . $e($value) . '"' . $attrs . (!empty($o['req']) ? ' required' : '') . '>'
         . (isset($o['hint']) ? '<span class="hint">' . $e($o['hint']) . '</span>' : '') . '</div>';
};

$page = 'company';
$pageTitle = 'Company profile';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Company <i>◆</i> Profile</p>
        <h1>Company <em>profile</em></h1>
        <p>The details on your quotes, invoices, statements and payslips, and what Finance reports to SARS. Change something and save it once — every module uses it from then on.</p>
    </div>
</header>

<form data-settings="company" data-endpoint="company.php" novalidate>
    <section class="fwa-card" id="card-identity">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-building"/></svg></span>
            <div class="fwa-card__title"><h3>The business</h3><p>Your trading name exactly as customers should see it.</p></div></header>
        <div class="fwa-card__body"><div class="fwa-form">
            <?= $field('name', 'Business name', ['maxlength' => 255, 'req' => true, 'autocomplete' => 'organization']) ?>
            <div class="field"><label class="label" for="f-business_type">Kind of business</label>
                <select class="select" id="f-business_type" name="business_type">
                    <?php foreach (fw_business_types() as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($c['business_type'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
                </select></div>
        </div></div>
    </section>

    <section class="fwa-card" id="card-tax">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#if-vat"/></svg></span>
            <div class="fwa-card__title"><h3>Registration &amp; tax</h3><p>Printed on documents and used on VAT returns. Leave the VAT number empty if you are not VAT registered.</p></div></header>
        <div class="fwa-card__body"><div class="fwa-form">
            <?= $field('reg_number', 'Company registration (CIPC)', ['maxlength' => 50, 'placeholder' => '2020/123456/07']) ?>
            <?= $field('vat_number', 'VAT number', ['maxlength' => 12, 'inputmode' => 'numeric', 'placeholder' => '4123456789', 'hint' => '10 digits, starting with 4.']) ?>
            <?= $field('tax_reference', 'SARS income tax reference', ['maxlength' => 12, 'inputmode' => 'numeric', 'placeholder' => '9123456789', 'hint' => '10 digits.']) ?>
            <?= $field('tax_number', 'Other tax number', ['maxlength' => 50, 'hint' => 'Printed on documents when set, e.g. a customs code.']) ?>
        </div></div>
    </section>

    <section class="fwa-card" id="card-contact">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-mail"/></svg></span>
            <div class="fwa-card__title"><h3>Contact &amp; address</h3><p>How customers and suppliers reach you.</p></div></header>
        <div class="fwa-card__body"><div class="fwa-form">
            <?= $field('phone', 'Phone', ['type' => 'tel', 'maxlength' => 20, 'autocomplete' => 'tel', 'placeholder' => '021 555 0123']) ?>
            <?= $field('email', 'Email', ['type' => 'email', 'maxlength' => 255, 'autocomplete' => 'email', 'placeholder' => 'accounts@yourcompany.co.za']) ?>
            <?= $field('website', 'Website', ['maxlength' => 255, 'placeholder' => 'www.yourcompany.co.za', 'full' => true, 'inputmode' => 'url']) ?>
            <?= $field('address_line1', 'Street address', ['maxlength' => 255, 'autocomplete' => 'address-line1', 'full' => true]) ?>
            <?= $field('address_line2', 'Address line 2', ['maxlength' => 255, 'autocomplete' => 'address-line2', 'full' => true, 'placeholder' => 'Suite, building or suburb']) ?>
            <?= $field('city', 'City or town', ['maxlength' => 100, 'autocomplete' => 'address-level2']) ?>
            <?= $field('region', 'Province', ['maxlength' => 100, 'list' => 'provinces', 'autocomplete' => 'address-level1']) ?>
            <?= $field('postal', 'Postal code', ['maxlength' => 20, 'inputmode' => 'numeric', 'autocomplete' => 'postal-code']) ?>
            <?= $field('country', 'Country', ['maxlength' => 100, 'list' => 'countries', 'autocomplete' => 'country-name', 'default' => 'South Africa']) ?>
        </div></div>
        <datalist id="provinces"><?php foreach ($provinces as $p): ?><option value="<?= $e($p) ?>"><?php endforeach; ?></datalist>
        <datalist id="countries"><?php foreach ($countries as $p): ?><option value="<?= $e($p) ?>"><?php endforeach; ?></datalist>
    </section>
</form>

<div class="fwa-grid" style="margin-top:16px">
    <section class="fwa-card" id="card-logo">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-image"/></svg></span>
            <div class="fwa-card__title"><h3>Logo</h3><p>PNG, JPG or WebP up to 3 MB. Stored as a crisp WebP.</p></div></header>
        <div class="fwa-card__body">
            <div class="fwa-logo" id="logoBox" data-url="<?= $val('logo_url') ?>">
                <?php if (!empty($c['logo_url'])): ?><img src="<?= $val('logo_url') ?>" alt="<?= $e($companyName) ?> logo"><?php else: ?><span class="fwa-logo__empty">No logo yet</span><?php endif; ?>
            </div>
        </div>
        <footer class="fwa-card__foot">
            <button class="btn btn--sm" type="button" id="logoPick"><svg class="i"><use href="#i-upload"/></svg><?= empty($c['logo_url']) ? 'Upload a logo' : 'Replace' ?></button>
            <button class="btn btn--sm btn--ghost btn--danger" type="button" id="logoRemove"<?= empty($c['logo_url']) ? ' hidden' : '' ?>>Remove</button>
            <span class="fwa-grow"></span><a class="fwa-link" href="/qi/designer.php">Document designer</a>
            <input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp" hidden>
        </footer>
    </section>

    <section class="fwa-card" id="card-banking">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#if-bank"/></svg></span>
            <div class="fwa-card__title"><h3>Banking details</h3><p>Printed on invoices so customers know where to pay.</p></div></header>
        <div class="fwa-card__body">
            <?php if (empty($c['bank_account_number'])): ?>
                <p class="fwa-muted" style="margin:0">No bank account on your invoices yet.</p>
            <?php else: ?>
                <dl class="fwa-kv">
                    <?php if ($holder !== ''): ?><dt>Account holder</dt><dd><?= $e($holder) ?></dd><?php endif; ?>
                    <dt>Bank</dt><dd><?= $val('bank_name') ?: '—' ?></dd>
                    <dt>Account</dt><dd class="fwa-mono">•••• <?= $e(substr((string)$c['bank_account_number'], -4)) ?></dd>
                    <dt>Branch code</dt><dd class="fwa-mono"><?= $val('bank_branch_code') ?: '—' ?></dd>
                </dl>
            <?php endif; ?>
        </div>
        <footer class="fwa-card__foot">
            <a class="btn btn--sm" href="/qi/settings.php#banking"><svg class="i"><use href="#i-pen"/></svg><?= empty($c['bank_account_number']) ? 'Add bank details' : 'Change bank details' ?></a>
            <span class="fwa-muted" style="font-size:12px">Changes alert your other admins — a guard against fraud.</span>
        </footer>
    </section>
</div>
<?php
$pageScripts = ['company'];
include __DIR__ . '/partials/foot.php';
