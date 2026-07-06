<?php
/**
 * WS5: SARS s20 tax-invoice compliance — validator rules (VAT number format,
 * supplier address, abridged vs full invoice thresholds, per-line VAT rate)
 * and enforcement at issue time (InvoiceLifecycle::issueInvoice blocks on
 * errors, never on warnings).
 *
 * Company profile fields are master data (tests/db/reset.php leaves the
 * companies table alone), so every scenario sets the fields it needs
 * explicitly and the original values are restored at the end.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/TaxInvoiceValidator.php';
require_once FLOWWORK_ROOT . '/qi/lib/InvoiceLifecycle.php';

// --- snapshot company profile so this test leaves no trace -------------------
$origCompany = $DB->query(
    "SELECT vat_number, address_line1, city FROM companies WHERE id = " . TEST_COMPANY_ID
)->fetch(PDO::FETCH_ASSOC);

function set_company_profile(PDO $db, ?string $vat, ?string $addr1, ?string $city): void
{
    $db->prepare("UPDATE companies SET vat_number = ?, address_line1 = ?, city = ? WHERE id = ?")
       ->execute([$vat, $addr1, $city, TEST_COMPANY_ID]);
}

/** Fields of all issues at a given severity. */
function issue_fields(array $issues, string $severity): array
{
    $out = [];
    foreach ($issues as $i) {
        if ($i['severity'] === $severity) {
            $out[] = $i['field'];
        }
    }
    return $out;
}

$validator = new TaxInvoiceValidator($DB, TEST_COMPANY_ID);
$cust      = make_customer($DB);                                // vat_no 4123456789
$custNoVat = make_customer($DB, 'No VAT Customer', ['vat_no' => '']);

// --- (a) malformed company VAT number → format error -------------------------
// '1234124' is the profile value the test DB originally shipped with: neither
// 10 digits nor starting with 4.
set_company_profile($DB, '1234124', '180 Mullersteine', 'Vandebijlpark');
$inv = make_invoice($DB, $cust, [[1, 100.00, 15]]);
$issues = $validator->validate($inv);
assert_true(in_array('company_vat_number', issue_fields($issues, 'error'), true),
    'malformed company VAT number (1234124) reports a format error');
assert_true(!$validator->isCompliant($inv), 'format error breaks compliance');

// --- (b) valid profile, abridged invoice (<= R5000), customer without VAT ----
set_company_profile($DB, '4123456789', '180 Mullersteine', 'Vandebijlpark');
$invSmall = make_invoice($DB, $custNoVat, [[1, 1000.00, 15]]); // total R1,150
$issues = $validator->validate($invSmall);
assert_eq([], issue_fields($issues, 'error'), 'abridged invoice with valid profile has no errors');
assert_true(!in_array('customer_vat', array_column($issues, 'field'), true),
    'abridged (<= R5000) invoice does not nag about missing customer VAT');
assert_true($validator->isCompliant($invSmall), 'abridged invoice is compliant');

// --- (c) > R5000 with missing customer VAT → warning, not error --------------
$invBig = make_invoice($DB, $custNoVat, [[1, 10000.00, 15]]); // total R11,500
$issues = $validator->validate($invBig);
assert_true(!in_array('customer_vat', issue_fields($issues, 'error'), true),
    'missing customer VAT above R5000 is not an error');
assert_true(in_array('customer_vat', issue_fields($issues, 'warning'), true),
    'missing customer VAT above R5000 is a warning');
assert_true(in_array('customer_address', issue_fields($issues, 'warning'), true),
    'missing customer address above R5000 is a warning (customer has no crm_addresses row)');
assert_true($validator->isCompliant($invBig), 'warnings alone keep the invoice compliant');

// Warnings must NOT block issue.
$posted = InvoiceLifecycle::issueInvoice($DB, TEST_COMPANY_ID, TEST_USER_ID, $invBig);
assert_true($posted, 'issueInvoice succeeds (and posts) despite warnings');

// --- per-line NULL tax_rate → error ------------------------------------------
$invNullRate = make_invoice($DB, $cust, [[1, 100.00, 15]]);
$DB->exec("UPDATE invoice_lines SET tax_rate = NULL, tax_code_id = NULL WHERE invoice_id = " . (int)$invNullRate);
$issues = $validator->validate($invNullRate);
assert_true(in_array('line_1_tax_rate', issue_fields($issues, 'error'), true),
    'NULL per-line tax_rate is an error');

// --- not VAT-registered → single info note, rules not applied -----------------
set_company_profile($DB, '', '180 Mullersteine', 'Vandebijlpark');
$issues = $validator->validate($invNullRate); // would have errors if rules applied
assert_eq(1, count($issues), 'non-registered company yields exactly one note');
assert_eq('info', $issues[0]['severity'], 'the note is info-level');
assert_true($validator->isCompliant($invNullRate), 'non-registered company is always compliant');

// --- (d) broken company profile blocks issue ----------------------------------
set_company_profile($DB, '4123456789', '', ''); // VAT-registered but no address
$invDraft = make_invoice($DB, $cust, [[1, 200.00, 15]]);
$threw = false;
$msg = '';
try {
    InvoiceLifecycle::issueInvoice($DB, TEST_COMPANY_ID, TEST_USER_ID, $invDraft);
} catch (Exception $e) {
    $threw = true;
    $msg = $e->getMessage();
}
assert_true($threw, 'issueInvoice throws when the company profile is broken (blank address)');
assert_true(stripos($msg, 'not a valid SARS tax invoice') !== false,
    'error message says the document is not a valid SARS tax invoice');
$row = $DB->query("SELECT status, journal_id FROM invoices WHERE id = " . (int)$invDraft)->fetch();
assert_eq('draft', $row['status'], 'blocked invoice stays in draft');
assert_true(empty($row['journal_id']), 'blocked invoice is not posted to the GL');

// --- restore the company profile so other tests are unaffected ----------------
// If the stored original VAT number predates the s20 issue gate and is
// malformed, restore a valid one instead — otherwise every later test that
// issues an invoice for company 1 would be blocked by the gate.
$restoreVat = (string)($origCompany['vat_number'] ?? '');
if ($restoreVat !== '' && !preg_match('/^4\d{9}$/', $restoreVat)) {
    $restoreVat = '4123456789';
}
set_company_profile($DB, $restoreVat, $origCompany['address_line1'], $origCompany['city']);

test_done('tax_invoice_validator');
