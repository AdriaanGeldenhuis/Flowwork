<?php
/**
 * Finance rebuild — payables (finances/lib/FinPayables.php).
 * Bills are kept exactly as the engine posts them (VAT rounded per line), a
 * Receipts bill with a header that differs from its lines is aligned before
 * posting, payments equal what is allocated and never exceed what is owed
 * (one key = one payment), supplier credits are posted once and then applied
 * as often as needed without posting again, and the payables control account
 * ties out with the open bills throughout.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinPayables.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';

fin_setup($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;
$ap = fin_code($DB, 'finance_ap_account_id');
$exp = fin_pick_account($DB, 'expense');
$sup = make_supplier($DB);
$other = make_supplier($DB, 'Other Supplier');
$tie = fn() => round((new Tieout($DB, $C))->glBalance('AP', date('Y-m-d')) - (new Tieout($DB, $C))->apSubledger(date('Y-m-d')), 2);

// per-line rounding: 3 × 99.99 = 299.97 (VAT 44.9955 → 45.00) and 150.33 (VAT 22.5495 → 22.55)
$in = ['supplier_id' => $sup, 'number' => 'INV-A1', 'issued' => date('Y-m-d', strtotime('-40 days')), 'due' => date('Y-m-d', strtotime('-10 days')),
       'lines' => [['description' => 'Cement', 'qty' => 3, 'unit_price' => '99.99', 'tax_rate' => 15, 'account_id' => $exp['id']], ['description' => 'Delivery', 'qty' => 1, 'unit_price' => '150.33', 'tax_rate' => 15, 'account_id' => $exp['id']]]];
$bill = FinPayables::saveBill($DB, $C, $U, 'bookkeeper', $in);
$b = $DB->query("SELECT subtotal, tax, total, status FROM ap_bills WHERE id = $bill")->fetch(PDO::FETCH_ASSOC);
assert_cents(450.30, (float)$b['subtotal'], 'net is the sum of the lines');
assert_cents(67.55, (float)$b['tax'], 'VAT rounded per line');
$tiny = FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'INV-TINY', 'lines' => array_fill(0, 3, ['description' => 'Washer', 'qty' => 1, 'unit_price' => '0.10', 'tax_rate' => 15])] + $in);
assert_cents(0.06, (float)$DB->query("SELECT tax FROM ap_bills WHERE id = $tiny")->fetchColumn(), 'three R0.10 lines carry R0.06 VAT, as the engine posts it (one rounding over the bill gives R0.05)');
FinPayables::deleteBill($DB, $C, $U, 'bookkeeper', $tiny);
assert_cents(517.85, (float)$b['total'], 'total');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'INV-A1'] + $in), "the supplier's invoice number is captured once", 'already captured');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'INV-NEG', 'lines' => [['description' => 'x', 'qty' => 1, 'unit_price' => '-10']]] + $in), 'a negative price is refused', 'credit note');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'INV-VAT', 'vendor_vat' => '123'] + $in), 'a malformed VAT number is refused', 'VAT number');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'INV-CTL', 'lines' => [['description' => 'x', 'qty' => 1, 'unit_price' => '10', 'account_id' => fin_account_id($DB, $ap)]]] + $in), 'a bill line cannot post to a control account', 'control');

FinPayables::postBill($DB, $C, $U, 'bookkeeper', $bill);
$jid = (int)$DB->query("SELECT journal_id FROM ap_bills WHERE id = $bill")->fetchColumn();
assert_balanced_journal($DB, $jid, 'bill');
assert_eq(-51785, gl_balance_cents($DB, $ap), 'payables control credited with the bill total');
assert_cents(0, $tie(), 'payables tie out');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['id' => $bill] + $in), 'a posted bill cannot change', 'credit note');
expect_error(fn() => FinPayables::deleteBill($DB, $C, $U, 'bookkeeper', $bill), 'a posted bill cannot be deleted');
expect_error(fn() => FinPayables::postBill($DB, $C, $U, 'bookkeeper', $bill), 'a bill is posted once', 'already');

// a Receipts bill whose header disagrees with its lines is aligned on posting
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, vendor_invoice_number, issue_date, due_date, currency, subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, 'RCPT-9', CURDATE(), CURDATE(), 'ZAR', 100.00, 14.99, 114.99, 'review', ?, NOW())")->execute([$C, $sup, $U]);
$rb = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, sort_order, gl_account_id) VALUES (?, 'Fuel', 1, 'ea', 100.00, 0, 15, 100.00, 0, ?)")->execute([$rb, $exp['id']]);
FinPayables::postBill($DB, $C, $U, 'bookkeeper', $rb);
assert_cents(115.00, (float)$DB->query("SELECT total FROM ap_bills WHERE id = $rb")->fetchColumn(), 'header aligned to what the engine posted');
assert_cents(0, $tie(), 'still ties out');

// pay: amount = allocations, never more than owed, one key once, all bills of the supplier
$open = FinPayables::openBills($DB, $C, $sup);
assert_eq(2, count($open), 'both posted bills are open for payment');
expect_error(fn() => FinPayables::pay($DB, $C, $U, 'bookkeeper', ['supplier_id' => $sup, 'date' => date('Y-m-d'), 'allocations' => [['bill_id' => $bill, 'amount' => '517.86']]]), 'paying more than owed is refused', 'still owing');
expect_error(fn() => FinPayables::pay($DB, $C, $U, 'bookkeeper', ['supplier_id' => $other, 'date' => date('Y-m-d'), 'allocations' => [['bill_id' => $bill, 'amount' => '1']]]), "another supplier's bill is refused", 'another supplier');
expect_error(fn() => FinPayables::pay($DB, $C, $U, 'bookkeeper', ['supplier_id' => $sup, 'date' => date('Y-m-d', strtotime('+5 days')), 'allocations' => [['bill_id' => $bill, 'amount' => '1']]]), 'a future payment is refused', 'future');
$r1 = FinPayables::pay($DB, $C, $U, 'bookkeeper', ['supplier_id' => $sup, 'date' => date('Y-m-d'), 'key' => 'pay-1', 'allocations' => [['bill_id' => $bill, 'amount' => '200.00'], ['bill_id' => $rb, 'amount' => '115.00']]]);
$r2 = FinPayables::pay($DB, $C, $U, 'bookkeeper', ['supplier_id' => $sup, 'date' => date('Y-m-d'), 'key' => 'pay-1', 'allocations' => [['bill_id' => $bill, 'amount' => '200.00'], ['bill_id' => $rb, 'amount' => '115.00']]]);
assert_eq($r1['payment_id'], $r2['payment_id'], 'the same key does not pay twice');
assert_true($r2['duplicate'], 'and says so');
assert_cents(315.00, (float)$DB->query("SELECT amount FROM ap_payments WHERE id = {$r1['payment_id']}")->fetchColumn(), 'payment amount = sum of allocations');
assert_eq(-31785, gl_balance_cents($DB, $ap), 'payables reduced by R315');
assert_eq('paid', $DB->query("SELECT status FROM ap_bills WHERE id = $rb")->fetchColumn(), 'fully paid bill reads paid');
assert_cents(0, $tie(), 'ties out after paying');

// supplier credit: posted once, applied twice, never re-posted
$cr = FinPayables::saveCredit($DB, $C, $U, 'bookkeeper', ['supplier_id' => $sup, 'number' => 'CN-1', 'date' => date('Y-m-d'), 'reason' => 'correction',
                                                          'lines' => [['description' => 'Overcharge', 'qty' => 1, 'unit_price' => '100.00', 'tax_rate' => 15, 'account_id' => $exp['id']]]]);
expect_error(fn() => FinPayables::allocateCredit($DB, $C, $U, 'bookkeeper', $cr, [['bill_id' => $bill, 'amount' => '10']]), 'a draft credit cannot be applied', 'Post the credit');
FinPayables::postCredit($DB, $C, $U, 'bookkeeper', $cr);
$cj = (int)$DB->query("SELECT journal_id FROM vendor_credits WHERE id = $cr")->fetchColumn();
assert_balanced_journal($DB, $cj, 'supplier credit');
assert_eq('approved', $DB->query("SELECT status FROM vendor_credits WHERE id = $cr")->fetchColumn(), 'posted, nothing applied yet: open');
assert_eq(-20285, gl_balance_cents($DB, $ap), 'the credit reduced payables by R115 at once');
FinPayables::allocateCredit($DB, $C, $U, 'bookkeeper', $cr, [['bill_id' => $bill, 'amount' => '60.00']]);
FinPayables::allocateCredit($DB, $C, $U, 'bookkeeper', $cr, [['bill_id' => $bill, 'amount' => '55.00']]);
assert_eq($cj, (int)$DB->query("SELECT journal_id FROM vendor_credits WHERE id = $cr")->fetchColumn(), 'applying did not post again');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE company_id = $C AND ref_type = 'vendor_credit' AND ref_id = $cr")->fetchColumn(), 'one credit journal');
assert_eq('applied', $DB->query("SELECT status FROM vendor_credits WHERE id = $cr")->fetchColumn(), 'fully applied');
expect_error(fn() => FinPayables::allocateCredit($DB, $C, $U, 'bookkeeper', $cr, [['bill_id' => $bill, 'amount' => '0.01']]), 'nothing left to apply', 'left');
expect_error(fn() => FinPayables::postCredit($DB, $C, $U, 'bookkeeper', $cr), 'a credit is posted once', 'already');
assert_eq(-20285, gl_balance_cents($DB, $ap), 'applying did not move the ledger');
assert_cents(0, $tie(), 'ties out after the credit');
$bal = FinPayables::bill($DB, $C, $bill)['bill']['balance'];
assert_cents(202.85, $bal, 'bill balance = 517.85 − 200 paid − 115 credited');

// aging and statement
$ag = FinPayables::aging($DB, $C, date('Y-m-d'));
assert_cents(202.85, $ag['totals']['total'], 'aging total');
assert_cents(202.85, $ag['totals']['d30'], 'ten days late: 1–30');
$st = FinPayables::statement($DB, $C, $sup, date('Y-m-d', strtotime('-60 days')), date('Y-m-d'));
assert_cents(202.85, $st['closing'], 'statement closing = what is owed');

// drafts can go
$d = FinPayables::saveBill($DB, $C, $U, 'bookkeeper', ['number' => 'DRAFT-1'] + $in);
FinPayables::deleteBill($DB, $C, $U, 'bookkeeper', $d);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM ap_bills WHERE id = $d")->fetchColumn(), 'a draft bill is deleted');
expect_error(fn() => FinPayables::saveBill($DB, $C, $U, 'viewer', ['number' => 'V-1'] + $in), 'a viewer cannot capture bills');

test_done('fin_payables');
