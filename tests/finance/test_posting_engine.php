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

// ---------------------------------------------------------------------------
// REGRESSION (review): postAdHocJournal is the public choke-point entry for
// system endpoints (VAT adjustments, inventory returns) — it must enforce the
// same guarantees as every engine posting.
// ---------------------------------------------------------------------------
$salesCode = $map->code('finance_sales_account_id');
$bankCode  = $map->code('finance_bank_account_id');
$threw = false;
try {
    $svc->postAdHocJournal(
        ['entry_date' => date('Y-m-d'), 'reference' => 'ADHOC-BAD'],
        [
            ['account_code' => $bankCode, 'description' => 'x', 'debit' => 100.00, 'credit' => 0],
            ['account_code' => $salesCode, 'description' => 'x', 'debit' => 0, 'credit' => 99.99],
        ]
    );
} catch (Exception $e) {
    $threw = true;
}
assert_true($threw, 'postAdHocJournal rejects an unbalanced journal (1c off)');

$DB->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active)
              VALUES (?, ?, 'test lock', 1, NOW(), 1)")->execute([TEST_COMPANY_ID, date('Y-m-d')]);
$threw = false;
try {
    $svc->postAdHocJournal(
        ['entry_date' => date('Y-m-d'), 'reference' => 'ADHOC-LOCKED'],
        [
            ['account_code' => $bankCode, 'description' => 'x', 'debit' => 100.00, 'credit' => 0],
            ['account_code' => $salesCode, 'description' => 'x', 'debit' => 0, 'credit' => 100.00],
        ]
    );
} catch (Exception $e) {
    $threw = true;
}
assert_true($threw, 'postAdHocJournal rejects a locked period');
$DB->exec("DELETE FROM gl_period_locks WHERE company_id = " . TEST_COMPANY_ID);

$adhocId = $svc->postAdHocJournal(
    ['entry_date' => date('Y-m-d'), 'reference' => 'ADHOC-OK', 'module' => 'fin'],
    [
        ['account_code' => $bankCode, 'description' => 'x', 'debit' => 100.00, 'credit' => 0],
        ['account_code' => $salesCode, 'description' => 'x', 'debit' => 0, 'credit' => 100.00],
    ]
);
assert_true($adhocId > 0, 'postAdHocJournal posts a valid journal');
assert_eq('posted', $DB->query("SELECT status FROM journal_entries WHERE id = $adhocId")->fetchColumn(),
    'ad-hoc journal is status posted (not invisible draft)');
assert_balanced_journal($DB, $adhocId, '(ad hoc)');

// ---------------------------------------------------------------------------
// REGRESSION (review): a NEGATIVE-quantity inventory line (customer return
// netted on the invoice) must restock and credit COGS — it used to adjust
// revenue only, leaving stock short and COGS overstated.
// ---------------------------------------------------------------------------
$DB->prepare("INSERT INTO inventory_items (company_id, sku, name, uom, is_stocked)
              VALUES (?, CONCAT('SKU-', UUID_SHORT()), 'Return widget', 'ea', 1)")->execute([TEST_COMPANY_ID]);
$retItem = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO inventory_movements (company_id, item_id, movement_date, qty, unit_cost, ref_type)
              VALUES (?, ?, CURDATE(), 10, 50.00, 'opening')")->execute([TEST_COMPANY_ID, $retItem]);

$cogsCode = $map->code('finance_cogs_account_id');
$cogsBefore = gl_balance_cents($DB, $cogsCode);
$invRet = make_invoice($DB, $cust, [[3, 100.00, 15, 'Widgets'], [-1, 100.00, 15, 'Widget returned']], ['status' => 'sent']);
$DB->prepare("UPDATE invoice_lines SET inventory_item_id = ? WHERE invoice_id = ?")->execute([$retItem, $invRet]);
$svc->postInvoice($invRet);

$onHand = (float)$DB->query("SELECT COALESCE(SUM(qty),0) FROM inventory_movements
    WHERE company_id = " . TEST_COMPANY_ID . " AND item_id = $retItem")->fetchColumn();
assert_eq(8.0, $onHand, 'on-hand = 10 - 3 sold + 1 returned');
assert_eq($cogsBefore + 10000, gl_balance_cents($DB, $cogsCode),
    'COGS = 3 out - 1 back at R50 avg cost (net 100.00)');
assert_balanced_journal($DB, (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $invRet")->fetchColumn(),
    '(invoice with return line)');

test_done('posting_engine');
