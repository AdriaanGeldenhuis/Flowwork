<?php
/**
 * DUE-DILIGENCE-2026-07 A5: bank-rule auto-matches must be visible to the
 * payments-basis VAT201 and the audit file. VatCalculator / VatAuditFile select
 * bank VAT legs by je.ref_type = 'bank_tx' only, but finances/ajax/
 * bank_apply_rules.php used to post rule matches with ref_type='bank_rule', so
 * their output/input VAT was silently omitted from the return. The fix posts
 * rule journals with ref_type='bank_tx' (source_type stays 'bank_rule').
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatCalculator.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map      = new AccountsMap($DB, TEST_COMPANY_ID);
$vatOut   = $map->code('finance_vat_output_account_id');
$vatIn    = $map->code('finance_vat_input_account_id');
$bankCode = $map->code('finance_bank_account_id');
$salesCode = $map->code('finance_sales_account_id');
$std = tax_code_id_by_code($DB, 'STD');
$start = date('Y-m-01');
$end   = date('Y-m-t');

// Post a money-in bank journal the way bank_apply_rules.php builds it (VAT-
// inclusive R115 = 100 net + 15 output VAT), with a given ref_type.
$postBankRuleJournal = static function (PDO $db, string $refType) use ($bankCode, $salesCode, $vatOut, $std) {
    $db->prepare("INSERT INTO journal_entries
        (company_id, entry_date, reference, description, module, ref_type, ref_id,
         source_type, source_id, created_by, created_at, status, posted_by, posted_at)
        VALUES (?, CURDATE(), 'BANKTX-T', 'Card sales', 'fin', ?, 0, 'bank_rule', 0, ?, NOW(), 'posted', ?, NOW())")
       ->execute([TEST_COMPANY_ID, $refType, TEST_USER_ID, TEST_USER_ID]);
    $jid = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO journal_lines
        (journal_id, account_code, description, debit, credit, tax_code_id, reference)
        VALUES (?,?,?,?,?,?,?)");
    $ins->execute([$jid, $bankCode,  'Card sales',        115.00, 0,      null, 'BANKTX-T']); // Dr Bank gross
    $ins->execute([$jid, $salesCode, 'Card sales',        0,      100.00, $std, 'BANKTX-T']); // Cr Revenue net
    $ins->execute([$jid, $vatOut,    'VAT on card sales', 0,      15.00,  $std, 'BANKTX-T']); // Cr VAT output
    return $jid;
};

$before = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');

// (1) Post-fix: a rule match tagged ref_type='bank_tx' IS seen by the VAT201.
$postBankRuleJournal($DB, 'bank_tx');
$after1 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($before['box5_total_output_cents'] + 1500, $after1['box5_total_output_cents'],
    "a bank-rule match (ref_type='bank_tx') is included in the payments-basis output VAT");

// (2) Regression guard: a journal still mis-tagged ref_type='bank_rule' (the old
// bug) stays invisible — proving the fix genuinely depends on the ref_type.
$postBankRuleJournal($DB, 'bank_rule');
$after2 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($after1['box5_total_output_cents'], $after2['box5_total_output_cents'],
    "a ref_type='bank_rule' journal is NOT counted (why the fix must use 'bank_tx')");

// The audit file must foot to the same Box 5 (rule matches included).
require_once FLOWWORK_ROOT . '/finances/lib/VatAuditFile.php';
$rows   = VatAuditFile::rows($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
$totals = VatAuditFile::totals($rows);
assert_eq($after2['box5_total_output_cents'], $totals['output_vat_cents'],
    'audit file output rows foot to Box 5 with the bank-rule match included');

test_done('bank_rule_vat');
