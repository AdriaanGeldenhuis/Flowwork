<?php
/**
 * DUE-DILIGENCE-2026-07 A3: an AP payment / vendor credit may only be allocated
 * to a bill already posted to the GL. Paying an unposted draft books Dr AP / Cr
 * Bank with no AP credit behind it, and once the allocation settles the balance
 * the bill flips to 'paid' — after which postApBill permanently refuses to post
 * it (that "bricked" symptom is covered in test_controls.php).
 *
 * finances/ap/api/payment_create.php and vendor_credit_post.php enforce
 *   status IN ('posted','paid') AND journal_id IS NOT NULL
 * inside their FOR UPDATE validation. Those are HTTP endpoints (php://input,
 * exit) so they can't be invoked in-process; this test verifies the exact
 * discriminator against real draft vs posted bills and that a posted-bill
 * payment ties out.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$sup = make_supplier($DB);

// The guard both endpoints apply, verbatim.
$payable = static fn(array $b): bool =>
    in_array($b['status'], ['posted', 'paid'], true) && !empty($b['journal_id']);

// A draft bill (never posted, journal_id NULL).
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), 'A3-1', 100, 15, 115, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$billId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order)
              VALUES (?, 'X', 1, 100, 15, 115, 0)")->execute([$billId]);

// (1) The draft bill is NOT payable — the guard rejects it.
$row = $DB->query("SELECT status, journal_id FROM ap_bills WHERE id=$billId")->fetch(PDO::FETCH_ASSOC);
assert_true(!$payable($row), 'an unposted draft bill is rejected by the AP payment/credit guard');

// (2) Once posted, the bill IS payable.
$svc->postApBill($billId);
$row2 = $DB->query("SELECT status, journal_id FROM ap_bills WHERE id=$billId")->fetch(PDO::FETCH_ASSOC);
assert_true($payable($row2), 'a posted bill passes the guard');

// (3) Paying the posted bill keeps AP control == subledger (the tie-out the
// guard protects — paying the draft instead would have corrupted AP control).
$DB->prepare("INSERT INTO ap_payments (company_id, supplier_id, amount, payment_date, method, reference, created_by, created_at)
              VALUES (?, ?, 115, CURDATE(), 'eft', 'A3-PAY', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$apPay = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount) VALUES (?,?,115)")
   ->execute([$apPay, $billId]);
$svc->postSupplierPayment($apPay);

$tieout = new Tieout($DB, TEST_COMPANY_ID);
$asOf = date('Y-m-d');
assert_cents(0.00, $tieout->apSubledger($asOf), 'AP subledger settled after paying the posted bill');
assert_cents(0.00, $tieout->glBalance('AP', $asOf), 'GL AP control matches the subledger');

test_done('ap_pay_credit_guard');
