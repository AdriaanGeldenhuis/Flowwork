<?php
/**
 * WS6: control hardening — AP repost guards, payment idempotency keys,
 * over-allocation under lock, subledger tie-out with drafts excluded.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$cust = make_customer($DB);
$sup = make_supplier($DB);

// --- AP repost guards ---------------------------------------------------------
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), 'GUARD-1', 100, 15, 115, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$billId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order)
              VALUES (?, 'X', 1, 100, 15, 115, 0)")->execute([$billId]);
$svc->postApBill($billId);

$threw = false;
try { $svc->postApBill($billId); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'reposting a posted bill without the explicit flag is rejected');
$svc->postApBill($billId, true); // explicit repost allowed
assert_eq('posted', $DB->query("SELECT status FROM ap_bills WHERE id = $billId")->fetchColumn(), 'explicit repost ok');

// Pay it in full, then reposting must be impossible even with the flag.
$DB->prepare("INSERT INTO ap_payments (company_id, supplier_id, amount, payment_date, method, reference, idempotency_key, created_by, created_at)
              VALUES (?, ?, 115, CURDATE(), 'eft', 'GUARDPAY', 'idem-ap-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$apPay = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount) VALUES (?,?,115)")
   ->execute([$apPay, $billId]);
$svc->postSupplierPayment($apPay);
assert_eq('paid', $DB->query("SELECT status FROM ap_bills WHERE id = $billId")->fetchColumn(), 'bill settled');
$threw = false;
try { $svc->postApBill($billId, true); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'reposting a PAID bill is always rejected');

// --- Payment idempotency: unique key constraint --------------------------------
$dupBlocked = false;
try {
    $DB->prepare("INSERT INTO ap_payments (company_id, supplier_id, amount, payment_date, method, reference, idempotency_key, created_by, created_at)
                  VALUES (?, ?, 10, CURDATE(), 'eft', 'DUP', 'idem-ap-1', ?, NOW())")
       ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
} catch (PDOException $e) {
    $dupBlocked = true;
}
assert_true($dupBlocked, 'duplicate idempotency key rejected by unique index');

// --- Tie-out: AR subledger == GL AR control, drafts excluded -------------------
$invDraft = make_invoice($DB, $cust, [[1, 999.00, 15, 'Draft — must not count']]); // stays draft
$invPosted = make_invoice($DB, $cust, [[1, 200.00, 15, 'Posted']]);
$svc->postInvoice($invPosted);
$DB->prepare("UPDATE invoices SET status='sent' WHERE id = ?")->execute([$invPosted]);

$tieout = new Tieout($DB, TEST_COMPANY_ID);
$asOf = date('Y-m-d');
assert_cents(230.00, $tieout->arSubledger($asOf), 'AR subledger excludes drafts');
assert_cents(230.00, $tieout->glBalance('AR', $asOf), 'GL AR control matches (via finance mapping)');

// AP: subledger vs GL (bill 115 fully paid => 0 outstanding)
assert_cents(0.00, $tieout->apSubledger($asOf), 'AP subledger settled');
assert_cents(0.00, $tieout->glBalance('AP', $asOf), 'GL AP control settled');

test_done('controls');
