<?php
/**
 * Finance rebuild — receivables (finances/lib/FinReceivables.php).
 * Money received in Finance goes through Quotes & Invoices'
 * QiActions::recordPayment, so it is the same receipt as one recorded on the
 * invoice: into the chosen bank account (payments.bank_account_id), one key
 * per invoice so a double click never receives twice, never more than owed.
 * "Late" is worked out from the due date; aging as at a past date ignores
 * later receipts; the receivables control account ties out throughout.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiActions.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinReceivables.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';

fin_setup($DB);
QiSchema::ensure($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;
$ar = fin_code($DB, 'finance_ar_account_id');
$cust = make_customer($DB, 'AR Customer');
$DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, city, postal_code, country, created_at) VALUES (?, ?, 'billing', '1 Main Road', 'Durban', '4001', 'ZA', NOW())")->execute([$C, $cust]);
$newInvoice = function (float $price, string $issued, string $due) use ($DB, $C, $U, $cust): int {
    $r = QiWriter::save($DB, $C, $U, 'invoice', ['customer_id' => $cust, 'issue_date' => $issued, 'due_date' => $due,
        'rows' => [['kind' => 'item', 'description' => 'Work', 'qty' => 1, 'unit' => 'job', 'price' => $price, 'discount' => 0, 'tax_code' => 'STD']]]);
    QiActions::issueInvoice($DB, $C, $U, (int)$r['id']);
    return (int)$r['id'];
};
$tie = fn() => round((new Tieout($DB, $C))->glBalance('AR', date('Y-m-d')) - (new Tieout($DB, $C))->arSubledger(date('Y-m-d')), 2);

$late = $newInvoice(1000, date('Y-m-d', strtotime('-50 days')), date('Y-m-d', strtotime('-20 days')));   // R1 150, 20 days late
$cur = $newInvoice(200, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('+25 days')));      // R230, not due
assert_cents(0, $tie(), 'receivables tie out after issuing');

$list = FinReceivables::invoices($DB, $C, ['status' => 'overdue']);
assert_eq(1, $list['total'], 'the late invoice is found under Late (worked out from the due date)');
assert_eq(20, $list['rows'][0]['days_late'], '20 days late');
assert_eq('overdue', $list['rows'][0]['status'], 'shown as overdue');
assert_eq(2, FinReceivables::invoices($DB, $C, ['status' => 'open'])['total'], 'both open');

$ag = FinReceivables::aging($DB, $C, date('Y-m-d'));
assert_cents(1380.00, $ag['totals']['total'], 'aging total');
assert_cents(1150.00, $ag['totals']['d30'], 'the late one is 1–30 days');
assert_cents(230.00, $ag['totals']['current'], 'the other is current');

// receive into a chosen bank account, part of one invoice and all of the other
$bank = fin_bank($DB, '1030', 'Receipts bank');
$key = 'rcv-' . bin2hex(random_bytes(4));
expect_error(fn() => FinReceivables::receive($DB, $C, $U, 'bookkeeper', ['customer_id' => $cust, 'date' => date('Y-m-d'), 'allocations' => [['invoice_id' => $cur, 'amount' => '230.01']]]), 'receiving more than owed is refused', 'more than');
expect_error(fn() => FinReceivables::receive($DB, $C, $U, 'viewer', ['customer_id' => $cust, 'allocations' => [['invoice_id' => $cur, 'amount' => '1']]]), 'a viewer cannot record receipts');
$done = FinReceivables::receive($DB, $C, $U, 'bookkeeper', ['customer_id' => $cust, 'date' => date('Y-m-d'), 'method' => 'eft', 'reference' => 'EFT 123', 'bank_account_id' => $bank, 'key' => $key,
                                                            'allocations' => [['invoice_id' => $late, 'amount' => '500.00'], ['invoice_id' => $cur, 'amount' => '230.00']]]);
assert_eq(2, count($done), 'one receipt per invoice');
$again = FinReceivables::receive($DB, $C, $U, 'bookkeeper', ['customer_id' => $cust, 'date' => date('Y-m-d'), 'bank_account_id' => $bank, 'key' => $key,
                                                             'allocations' => [['invoice_id' => $late, 'amount' => '500.00'], ['invoice_id' => $cur, 'amount' => '230.00']]]);
assert_true($again[0]['duplicate'] && $again[1]['duplicate'], 'the same key does not receive twice');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM payments WHERE company_id = $C")->fetchColumn(), 'two payments only');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM payments WHERE company_id = $C AND bank_account_id = $bank")->fetchColumn(), 'both in the chosen bank account');
assert_eq(73000, gl_balance_cents($DB, '1030'), 'the money is in the chosen bank ledger account (R730)');
assert_eq('paid', $DB->query("SELECT status FROM invoices WHERE id = $cur")->fetchColumn(), 'the small invoice is paid');
assert_eq('part-paid', $DB->query("SELECT status FROM invoices WHERE id = $late")->fetchColumn(), 'the late one is part paid');
assert_cents(0, $tie(), 'receivables tie out after receiving');

// aging as at yesterday ignores today's receipts
$y = FinReceivables::aging($DB, $C, date('Y-m-d', strtotime('-1 day')));
assert_cents(1380.00, $y['totals']['total'], "yesterday's aging is before today's receipts");
$t = FinReceivables::aging($DB, $C, date('Y-m-d'));
assert_cents(650.00, $t['totals']['total'], "today's aging after them");

// statement
$st = FinReceivables::statement($DB, $C, $cust, date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
assert_cents(650.00, $st['closing'], 'statement closing balance = what is still owed');
assert_eq(4, count($st['lines']), 'two invoices and two receipts');
$one = FinReceivables::invoice($DB, $C, $late);
assert_cents(650.00, $one['invoice']['balance'], 'invoice view: balance');
assert_eq(1, count($one['payments']), 'invoice view: its receipt');
assert_true(str_starts_with($one['qi_url'], '/qi/view.php'), 'links to Quotes & Invoices');

test_done('fin_receivables');
