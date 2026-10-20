<?php
// /admin/api/company.php — the company profile (the same columns Quotes &
// Invoices prints on documents and Finance reports to SARS).
//   POST {area:'company', values: {name, business_type, reg_number, vat_number, tax_reference, tax_number,
//                                   phone, email, website, address_line1, address_line2, city, region, postal, country}}
//        → {changed: [keys], values}
// Only the keys sent are changed; each is checked first (422 + errors on a bad one).
// The logo and the bank details have their own guarded paths in Quotes & Invoices.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/business_types.php';
require_once __DIR__ . '/../../includes/company_validation.php';

admin_require_post();
admin_rate_limit('admin-company', 60, 600);

const COMPANY_FIELDS = [
    'name' => ['Business name', 255], 'business_type' => ['Business type', 30], 'reg_number' => ['Registration number', 50],
    'vat_number' => ['VAT number', 50], 'tax_reference' => ['Income tax reference', 50], 'tax_number' => ['Tax number', 50],
    'phone' => ['Phone', 20], 'email' => ['Email', 255], 'website' => ['Website', 255],
    'address_line1' => ['Street address', 255], 'address_line2' => ['Address line 2', 255], 'city' => ['City or town', 100],
    'region' => ['Province', 100], 'postal' => ['Postal code', 20], 'country' => ['Country', 100],
];

function company_values(PDO $db, int $companyId): array
{
    $st = $db->prepare("SELECT " . implode(', ', array_map(fn($k) => "`$k`", array_keys(COMPANY_FIELDS))) . " FROM companies WHERE id = ?");
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_map(fn($v) => (string)($v ?? ''), $row);
}

$in = admin_input();
$values = is_array($in['values'] ?? null) ? $in['values'] : [];
$current = company_values($DB, $ADMIN_COMPANY_ID);
$clean = [];
$errors = [];
foreach ($values as $k => $v) {
    if (!isset(COMPANY_FIELDS[$k]) || (!is_scalar($v) && $v !== null)) continue;
    [$label, $max] = COMPANY_FIELDS[$k];
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if (in_array($k, ['vat_number', 'tax_reference', 'phone'], true)) $v = $k === 'phone' ? preg_replace('/[^\d+() -]/', '', $v) : preg_replace('/\s+/', '', $v);
    if ($k === 'website' && $v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    if ($k === 'email') $v = mb_strtolower($v);
    if (mb_strlen($v) > $max) { $errors[$k] = $label . ' is too long (' . $max . ' characters at most).'; continue; }
    if ($k === 'name' && $v === '') $errors[$k] = 'Enter the business name.';
    if ($k === 'business_type' && !in_array($v, fw_business_type_keys(), true)) $errors[$k] = 'Choose a business type.';
    if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$k] = 'That email address does not look right.';
    if ($k === 'website' && $v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) $errors[$k] = 'That web address does not look right.';
    $clean[$k] = $v;
}
foreach (validate_company_profile(['vat_number' => $clean['vat_number'] ?? '', 'tax_reference' => $clean['tax_reference'] ?? '']) as $msg) {
    $errors[str_contains($msg, 'VAT') ? 'vat_number' : 'tax_reference'] = $msg . '.';
}
if ($errors) throw new AdminInvalid($errors);

$changed = array_filter($clean, fn($v, $k) => $v !== ($current[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
if ($changed) {
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($changed)));
    $params = array_map(fn($v, $k) => $v === '' && $k !== 'name' && $k !== 'business_type' ? null : $v, $changed, array_keys($changed));
    $params[] = $ADMIN_COMPANY_ID;
    $DB->prepare("UPDATE companies SET $sets, updated_at = NOW() WHERE id = ?")->execute(array_values($params));
    $log = [];
    foreach ($changed as $k => $v) $log[COMPANY_FIELDS[$k][0]] = ($current[$k] !== '' ? $current[$k] : '—') . ' → ' . ($v !== '' ? $v : '—');
    AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'company_updated', $log, 'company', $ADMIN_COMPANY_ID);
}
admin_json(['changed' => array_keys($changed), 'values' => company_values($DB, $ADMIN_COMPANY_ID)]);
