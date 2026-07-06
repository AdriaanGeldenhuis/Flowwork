<?php
/**
 * Golden posting-engine tests: amounts, accounts and balance for the core
 * document types. These assertions hold for the engine before AND after the
 * WS2 consolidation (amount logic is unchanged; only structure/immutability
 * changes).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$arCode    = $map->code('finance_ar_account_id');
$salesCode = $map->code('finance_sales_account_id');
$vatOut    = $map->code('finance_vat_output_account_id');

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);

// --- Standard-rated invoice: 2 x 100 @ 15% ----------------------------------
$cust = make_customer($DB);
$inv = make_invoice($DB, $cust, [[2, 100.00, 15]]);
$svc->postInvoice($inv);
$jid = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
assert_true($jid > 0, 'invoice journal created');
assert_balanced_journal($DB, $jid, '(std invoice)');
assert_eq(23000, gl_balance_cents($DB, $arCode), 'AR debit 230.00');
assert_eq(-20000, gl_balance_cents($DB, $salesCode), 'Sales credit 200.00');
assert_eq(-3000, gl_balance_cents($DB, $vatOut), 'VAT output credit 30.00');

// --- Mixed invoice: standard + zero-rated ------------------------------------
$inv2 = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std line'], [1, 500.00, 0, 'Zero-rated line']]);
$svc->postInvoice($inv2);
$jid2 = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv2")->fetchColumn();
assert_balanced_journal($DB, $jid2, '(mixed invoice)');
// VAT only on the standard line: 150.00
$vat2 = $DB->query("SELECT COALESCE(SUM(ROUND(credit*100)),0) FROM journal_lines
                    WHERE journal_id = $jid2 AND account_code = '$vatOut'")->fetchColumn();
assert_eq(15000, (int)$vat2, 'mixed invoice VAT = 15% of standard line only');
// Zero-rated revenue must carry the ZERO tax code, not STD
$zeroTagged = $DB->query(
    "SELECT COUNT(*) FROM journal_lines jl
      JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
     WHERE jl.journal_id = $jid2 AND tc.code = 'ZERO' AND jl.credit > 0")->fetchColumn();
assert_true((int)$zeroTagged >= 1, 'zero-rated revenue line tagged ZERO');

// --- Customer payment --------------------------------------------------------
$DB->prepare("INSERT INTO payments (company_id, amount, payment_date, method, reference, received_by, created_at)
              VALUES (?, 230.00, CURDATE(), 'eft', 'TESTPAY', ?, NOW())")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID]);
$payId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,230.00)")
   ->execute([$payId, $inv]);
$svc->postCustomerPayment($payId);
$pjid = (int)$DB->query("SELECT journal_id FROM payments WHERE id = $payId")->fetchColumn();
assert_true($pjid > 0, 'payment journal created');
assert_balanced_journal($DB, $pjid, '(customer payment)');
// AR balance = inv (230.00) + inv2 (1650.00) - payment (230.00) = 1650.00
assert_eq(165000, gl_balance_cents($DB, $arCode), 'AR = invoices - payment');

// --- AP bill with input VAT --------------------------------------------------
$sup = make_supplier($DB);
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), 'VINV-1', 400.00, 60.00, 460.00, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$billId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order)
              VALUES (?, 'Widgets', 4, 100.00, 15, 460.00, 0)")
   ->execute([$billId]);
$svc->postApBill($billId);
$bjid = (int)$DB->query("SELECT journal_id FROM ap_bills WHERE id = $billId")->fetchColumn();
assert_true($bjid > 0, 'bill journal created');
assert_balanced_journal($DB, $bjid, '(ap bill)');
$apCode = $map->code('finance_ap_account_id');
$vatIn  = $map->code('finance_vat_input_account_id');
assert_eq(-46000, gl_balance_cents($DB, $apCode), 'AP credit 460.00');
assert_eq(6000, gl_balance_cents($DB, $vatIn), 'VAT input debit 60.00');
assert_eq('posted', $DB->query("SELECT status FROM ap_bills WHERE id = $billId")->fetchColumn(), 'bill posted');

test_done('posting_engine');
