<?php
/**
 * DUE-DILIGENCE-2026-07 B17: output VAT on a fixed-asset disposal (SARS s8(13))
 * must appear on the PAYMENTS-basis VAT201 and the audit file. The disposal is
 * cash-dated at disposal, but calculatePaymentsBasis previously scanned only
 * bank_tx journals, so a payments-basis vendor selling an asset omitted the
 * output tax.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatCalculator.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatAuditFile.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map    = new AccountsMap($DB, TEST_COMPANY_ID);
$vatOut = $map->code('finance_vat_output_account_id');
$vatIn  = $map->code('finance_vat_input_account_id');
$bank   = $map->code('finance_bank_account_id');
$sales  = $map->code('finance_sales_account_id');
$std    = tax_code_id_by_code($DB, 'STD');
$start  = date('Y-m-01');
$end    = date('Y-m-t');

$before = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');

// A fixed-asset disposal for R1150 incl. (1000 proceeds + 150 output VAT).
$DB->prepare("INSERT INTO journal_entries
        (company_id, entry_date, reference, description, module, ref_type, ref_id,
         source_type, source_id, created_by, created_at, status, posted_by, posted_at)
     VALUES (?, CURDATE(), 'FADISP-T', 'Disposal of asset', 'fin', 'fa_disposal', 0, 'fa_disposal', 0, ?, NOW(), 'posted', ?, NOW())")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID, TEST_USER_ID]);
$jid = (int)$DB->lastInsertId();
$ins = $DB->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, tax_code_id, reference) VALUES (?,?,?,?,?,?,?)");
$ins->execute([$jid, $bank,   'Proceeds',      1150.00, 0,       null, 'FADISP-T']); // Dr Bank gross
$ins->execute([$jid, $sales,  'Disposal net',  0,       1000.00, null, 'FADISP-T']); // Cr proceeds (non-VAT leg)
$ins->execute([$jid, $vatOut, 'VAT on disposal', 0,     150.00,  $std, 'FADISP-T']); // Cr output VAT

$after = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($before['box5_total_output_cents'] + 15000, $after['box5_total_output_cents'],
    'fixed-asset disposal output VAT is included in the payments-basis VAT201');

// The audit file must still foot to Box 5 with the disposal included.
$rows   = VatAuditFile::rows($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
$totals = VatAuditFile::totals($rows);
assert_eq($after['box5_total_output_cents'], $totals['output_vat_cents'],
    'audit file output rows foot to Box 5 with the disposal included');

test_done('fa_disposal_vat');
