<?php
/**
 * Finance rebuild — the bank (finances/lib/FinBank.php).
 * Import without duplicates, categorise (VAT leg chosen by the VAT code, never
 * a control account), match a payment already in the books without posting
 * again, transfers between the company's own accounts, undo, rules (dry run
 * first, never to a control account), and reconciliation (closes only at a
 * nil difference; a reconciled line or date can no longer change).
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinBank.php';

fin_setup($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;
$A = fin_bank($DB, '1020', 'Cheque');
$B = fin_bank($DB, '1030', 'Savings');
expect_error(fn() => fin_bank($DB, '1020', 'Twin'), 'two bank accounts cannot share a ledger account', 'already posts');
expect_error(fn() => FinBank::saveAccount($DB, $C, $U, 'admin', ['name' => 'Bad', 'gl_account_id' => fin_pick_account($DB, 'expense')['id']]), 'a bank account must post to a bank or cash ledger account', 'bank or cash');
$exp = fin_pick_account($DB, 'expense');
$inc = fin_pick_account($DB, 'revenue');
$ar = fin_code($DB, 'finance_ar_account_id');
$d = fn($n) => date('Y-m-d', strtotime("-$n days"));
$rows = [
    ['date' => $d(10), 'description' => 'DEPOSIT ACME', 'amount_cents' => 115000, 'reference' => 'INV1'],
    ['date' => $d(9), 'description' => 'TELKOM DEBIT ORDER', 'amount_cents' => -23000],
    ['date' => $d(8), 'description' => 'TRANSFER TO SAVINGS', 'amount_cents' => -500000],
    ['date' => $d(7), 'description' => 'CREDIT INTEREST', 'amount_cents' => 1234],
    ['date' => $d(7), 'description' => 'CREDIT INTEREST', 'amount_cents' => 1234],   // a genuine second line on the same day
];
$r = FinBank::import($DB, $C, $U, 'bookkeeper', $A, $rows, 'oct.csv');
assert_eq(5, $r['imported'], 'five lines imported (two identical ones on the same day are both kept)');
$r2 = FinBank::import($DB, $C, $U, 'bookkeeper', $A, $rows, 'oct-again.csv');
assert_eq(0, $r2['imported'], 'importing the same statement again adds nothing');
assert_eq(5, $r2['duplicates'], 'and reports the duplicates');
FinBank::import($DB, $C, $U, 'bookkeeper', $B, [['date' => $d(8), 'description' => 'TRANSFER FROM CHEQUE', 'amount_cents' => 500000]], 'sav.csv');
$tx = fn($desc, $acc) => (int)$DB->query("SELECT bank_tx_id FROM gl_bank_transactions WHERE bank_account_id = $acc AND description = " . $DB->quote($desc) . " AND matched = 0 ORDER BY bank_tx_id LIMIT 1")->fetchColumn();

// categorise with input VAT: the VAT leg follows the VAT code (input), not the direction of the money
$inputTax = tax_code_id_by_code($DB, 'INPUT');
expect_error(fn() => FinBank::categorise($DB, $C, $U, 'bookkeeper', $tx('TELKOM DEBIT ORDER', $A), [['account_code' => $ar, 'amount' => '230']]), 'a control account is refused', 'control account');
expect_error(fn() => FinBank::categorise($DB, $C, $U, 'bookkeeper', $tx('TELKOM DEBIT ORDER', $A), [['account_code' => $exp['code'], 'amount' => '100']]), 'the parts must add up to the line', 'add up');
$telkom = $tx('TELKOM DEBIT ORDER', $A);
$jid = FinBank::categorise($DB, $C, $U, 'bookkeeper', $telkom, [['account_code' => $exp['code'], 'amount' => '230.00', 'tax_code_id' => $inputTax]]);
assert_balanced_journal($DB, $jid, 'categorised line');
$vat = $DB->query("SELECT jl.debit FROM journal_lines jl WHERE jl.journal_id = $jid AND jl.account_code = " . $DB->quote(fin_code($DB, 'finance_vat_input_account_id')))->fetchColumn();
assert_cents(30.00, (float)$vat, 'R30 input VAT extracted from R230 (15/115)');
assert_eq(-23000, gl_balance_cents($DB, '1020'), 'bank credited R230');

// transfer to savings, matching the mirror line there too; undo reverses it and frees both lines
$out = $tx('TRANSFER TO SAVINGS', $A); $in = $tx('TRANSFER FROM CHEQUE', $B);
$tj = FinBank::transfer($DB, $C, $U, 'bookkeeper', $out, $B, $in);
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM gl_bank_transactions WHERE journal_id = $tj")->fetchColumn(), 'one journal matches both lines');
assert_eq(500000, gl_balance_cents($DB, '1030'), 'savings debited');
$u = FinBank::unmatch($DB, $C, $U, 'bookkeeper', $out);
assert_true($u['reversal_id'] > 0, 'undoing a transfer reverses its journal');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM gl_bank_transactions WHERE bank_tx_id IN ($out, $in) AND matched = 1")->fetchColumn(), 'both lines are free again');
assert_eq(0, gl_balance_cents($DB, '1030'), 'and the savings ledger is back to nil');
FinBank::transfer($DB, $C, $U, 'bookkeeper', $out, $B, $in);

// a receipt already in the books: matched, nothing posted again; undo just releases it
$receipt = fin_post($DB, $d(10), [['1020', 1150, 0], [$ar, 0, 1150]]);
$dep = $tx('DEPOSIT ACME', $A);
$cands = FinBank::line($DB, $C, $dep)['existing'];
assert_true(in_array($receipt, array_column($cands, 'journal_id'), true), 'the receipt is offered under “In the books”');
$jBefore = (int)$DB->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
FinBank::matchExisting($DB, $C, $U, 'bookkeeper', $dep, $receipt);
assert_eq($jBefore, (int)$DB->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(), 'matching an existing posting posts nothing');
$rel = FinBank::unmatch($DB, $C, $U, 'bookkeeper', $dep);
assert_true($rel['released'] && !$rel['reversal_id'], 'undo releases the posting without reversing it');
FinBank::matchExisting($DB, $C, $U, 'bookkeeper', $dep, $receipt);

// rules: never a control account; dry run first, then post
expect_error(fn() => FinBank::saveRule($DB, $C, $U, 'bookkeeper', ['value' => 'INTEREST', 'account_id' => fin_account_id($DB, $ar)]), 'a rule cannot post to a control account', 'control account');
FinBank::saveRule($DB, $C, $U, 'bookkeeper', ['value' => 'INTEREST', 'operator' => 'contains', 'account_id' => $inc['id']]);
$plan = FinBank::applyRules($DB, $C, $U, 'bookkeeper', $A, true);
assert_eq(2, count($plan['plan']), 'the dry run lists both interest lines');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM gl_bank_transactions WHERE description = 'CREDIT INTEREST' AND matched = 1")->fetchColumn(), 'the dry run posts nothing');
$done = FinBank::applyRules($DB, $C, $U, 'bookkeeper', $A);
assert_eq(2, $done['matched'], 'the rule posts both');

// reconcile: everything matched, the books agree, close at the imported balance
$imported = (int)$DB->query("SELECT SUM(amount_cents) FROM gl_bank_transactions WHERE bank_account_id = $A")->fetchColumn();
$s = FinBank::reconcileSummary($DB, $C, $A, date('Y-m-d'), $imported);
assert_cents(0, $s['difference'], 'nil difference');
assert_true($s['can_close'], 'ready to close');
expect_error(fn() => FinBank::closeReconciliation($DB, $C, $U, 'bookkeeper', $A, date('Y-m-d'), $imported + 1), 'a statement balance that differs from the lines is refused', 'differs');
FinBank::closeReconciliation($DB, $C, $U, 'bookkeeper', $A, date('Y-m-d'), $imported);
assert_eq(date('Y-m-d'), $DB->query("SELECT last_reconciled_date FROM gl_bank_accounts WHERE id = $A")->fetchColumn(), 'reconciled through today');
expect_error(fn() => FinBank::unmatch($DB, $C, $U, 'bookkeeper', $telkom), 'a reconciled line cannot be undone', 'reconciliation');
$late = FinBank::import($DB, $C, $U, 'bookkeeper', $A, [['date' => $d(3), 'description' => 'LATE LINE', 'amount_cents' => -100]], 'late.csv');
assert_eq(1, $late['reconciled'], 'a line dated in the reconciled period is not imported');
expect_error(fn() => FinBank::reopen($DB, $C, $U, 'bookkeeper', $A, 'mistake'), 'only an administrator reopens a reconciliation');
FinBank::reopen($DB, $C, $U, 'admin', $A, 'Statement corrected by the bank');
assert_true(!$DB->query("SELECT last_reconciled_date FROM gl_bank_accounts WHERE id = $A")->fetchColumn(), 'reopened');

test_done('fin_bank');
