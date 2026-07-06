<?php
/**
 * WS4: payments-basis VAT201. A partial receipt on a mixed-rate invoice is
 * apportioned across the invoice's per-tax-code profile by payment date;
 * credit notes count at issue date; invoice and payments bases agree once
 * everything is fully settled.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatCalculator.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$vatOut = $map->code('finance_vat_output_account_id');
$vatIn  = $map->code('finance_vat_input_account_id');
$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$cust = make_customer($DB);
$sup = make_supplier($DB);
$start = date('Y-m-01');
$end = date('Y-m-t');

function receive_payment(PDO $db, int $invoiceId, float $amount, string $date): int
{
    $db->prepare("INSERT INTO payments (company_id, amount, payment_date, method, reference, received_by, created_at)
                  VALUES (?, ?, ?, 'eft', CONCAT('P-', UUID_SHORT()), ?, NOW())")
       ->execute([TEST_COMPANY_ID, $amount, $date, TEST_USER_ID]);
    $pid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,?)")
       ->execute([$pid, $invoiceId, $amount]);
    return $pid;
}

// Mixed invoice: 1000 @ 15% (150 VAT) + 500 zero-rated => total 1650.
$inv = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std'], [1, 500.00, 0, 'Zero', 'tax_code' => 'ZERO']]);
$svc->postInvoice($inv);

// Payments basis with NO receipts: nothing is due yet.
$pb0 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq(0, $pb0['box5_total_output_cents'], 'no receipts -> no output tax (payments basis)');

// Invoice basis: full output tax immediately.
$ib = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'invoice');
assert_eq(15000, $ib['box5_total_output_cents'], 'invoice basis: full 150 output tax');

// 33% receipt (544.50 of 1650): apportioned -> VAT 49.50, std base 330, zero base 165.
$p1 = receive_payment($DB, $inv, 544.50, date('Y-m-d'));
$svc->postCustomerPayment($p1);
$pb1 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq(4950, $pb1['box5_total_output_cents'], 'partial receipt: 33% of VAT (49.50)');
assert_eq(33000, $pb1['output_standard_base_cents'], 'partial receipt: 33% of std base');
assert_eq(16500, $pb1['output_zero_base_cents'], 'partial receipt: 33% of zero-rated base');

// Settle the rest — both bases must agree over the full life.
$p2 = receive_payment($DB, $inv, 1105.50, date('Y-m-d'));
$svc->postCustomerPayment($p2);
$pb2 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($ib['box5_total_output_cents'], $pb2['box5_total_output_cents'],
    'fully settled: payments basis output equals invoice basis');
assert_eq($ib['output_zero_base_cents'], $pb2['output_zero_base_cents'],
    'fully settled: zero-rated base agrees');

// --- Input side: bill 400 @ 15% paid half -----------------------------------
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), 'VP-1', 400, 60, 460, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$billId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order)
              VALUES (?, 'Widgets', 1, 400, 15, 460, 0)")->execute([$billId]);
$svc->postApBill($billId);

$DB->prepare("INSERT INTO ap_payments (company_id, supplier_id, amount, payment_date, method, reference, created_by, created_at)
              VALUES (?, ?, 230, CURDATE(), 'eft', 'APP-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$apPayId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount) VALUES (?,?,230)")
   ->execute([$apPayId, $billId]);
$svc->postSupplierPayment($apPayId);

$pb3 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq(3000, $pb3['box9_total_input_cents'], 'half-paid bill: half the input VAT (30.00)');

// --- Credit note counts fully at issue date under payments basis ------------
$DB->prepare("INSERT INTO credit_notes (company_id, credit_note_number, invoice_id, customer_id, issue_date,
              status, subtotal, tax, total, currency, reason, reason_code, created_by, journal_id)
              VALUES (?, CONCAT('CN-', UUID_SHORT()), ?, ?, CURDATE(), 'draft', 100, 15, 115, 'ZAR', 'test', 'return', ?, NULL)")
   ->execute([TEST_COMPANY_ID, $inv, $cust, TEST_USER_ID]);
$cnId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO credit_note_lines (credit_note_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order)
              VALUES (?, 'Return', 1, 'ea', 100, 0, 15, ?, 115, 0)")
   ->execute([$cnId, tax_code_id_by_code($DB, 'STD')]);
$svc->postCreditNote($cnId);

$pb4 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($pb2['box5_total_output_cents'] - 1500, $pb4['box5_total_output_cents'],
    'credit note reduces output tax by 15.00 at issue date (fully-paid invoice)');

// ---------------------------------------------------------------------------
// REGRESSION (review): s21 clawback — a credit note against a NEVER-PAID
// invoice must not manufacture an output-tax refund, and crediting the unpaid
// half of an invoice must not double-count against the receipt of the other
// half. Previously credit notes were recognised in FULL at issue while
// receipts still apportioned by the original total.
// ---------------------------------------------------------------------------
function make_credit_note(PDO $db, int $invId, int $cust, float $net, float $vat, PostingService $svc): int
{
    $db->prepare("INSERT INTO credit_notes (company_id, credit_note_number, invoice_id, customer_id, issue_date,
                  status, subtotal, tax, total, currency, reason, reason_code, created_by, journal_id)
                  VALUES (?, CONCAT('CN-', UUID_SHORT()), ?, ?, CURDATE(), 'draft', ?, ?, ?, 'ZAR', 'test', 'return', ?, NULL)")
       ->execute([TEST_COMPANY_ID, $invId, $cust, $net, $vat, $net + $vat, TEST_USER_ID]);
    $cnId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO credit_note_lines (credit_note_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order)
                  VALUES (?, 'Return', 1, 'ea', ?, 0, 15, ?, ?, 0)")
       ->execute([$cnId, $net, tax_code_id_by_code($db, 'STD'), $net + $vat]);
    $svc->postCreditNote($cnId);
    return $cnId;
}

$before = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');

// (1) Full credit of a never-paid invoice: zero payments-basis effect.
$invUnpaid = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std']]); // 1150 incl.
$svc->postInvoice($invUnpaid);
make_credit_note($DB, $invUnpaid, $cust, 1000.00, 150.00, $svc);
$after1 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($before['box5_total_output_cents'], $after1['box5_total_output_cents'],
    'crediting a never-paid invoice claims no output-tax refund (payments basis)');

// (2) Credit half, customer pays the remaining half: net output = VAT on the
// R575 actually received (75.00), not 75 - 75 = 0.
$invHalf = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std']]); // 1150 incl.
$svc->postInvoice($invHalf);
make_credit_note($DB, $invHalf, $cust, 500.00, 75.00, $svc); // cancels the unpaid half
$pHalf = receive_payment($DB, $invHalf, 575.00, date('Y-m-d'));
$svc->postCustomerPayment($pHalf);
$after2 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($after1['box5_total_output_cents'] + 7500, $after2['box5_total_output_cents'],
    'credit-then-pay-remainder recognises exactly the VAT on the consideration received (75.00)');

// (3) Running-total rounding: R33.33 @ 15% (VAT exactly 5.00) received in
// three ragged instalments must recognise exactly 500c in total — the old
// per-allocation rounding drifted to 5.01.
$invRound = make_invoice($DB, $cust, [[1, 33.33, 15, 'Rounding']]); // total 38.33
$svc->postInvoice($invRound);
foreach ([12.78, 12.78, 12.77] as $amt) {
    $p = receive_payment($DB, $invRound, $amt, date('Y-m-d'));
    $svc->postCustomerPayment($p);
}
$after3 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
assert_eq($after2['box5_total_output_cents'] + 500, $after3['box5_total_output_cents'],
    'three ragged instalments recognise exactly the invoice VAT (running-total rounding)');

// (4) The VAT audit file must still FOOT to the boxes after all of the above.
require_once FLOWWORK_ROOT . '/finances/lib/VatAuditFile.php';
$rows = VatAuditFile::rows($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
$totals = VatAuditFile::totals($rows);
assert_eq($after3['box5_total_output_cents'], $totals['output_vat_cents'],
    'audit file output rows foot to Box 5 (payments basis, incl. clawback + rounding)');
assert_eq($after3['box9_total_input_cents'], $totals['input_vat_cents'],
    'audit file input rows foot to Box 9 (payments basis)');

// (5) periodBasis(): an OPEN period follows the company's CURRENT election;
// a prepared period trusts its stamped snapshot.
$DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value)
              VALUES (?, 'finance_vat_basis', 'payments')
              ON DUPLICATE KEY UPDATE setting_value = 'payments'")->execute([TEST_COMPANY_ID]);
assert_eq('payments', VatCalculator::periodBasis($DB, TEST_COMPANY_ID,
    ['status' => 'open', 'basis' => 'invoice']),
    'open period ignores the un-stamped default basis and follows the company election');
assert_eq('invoice', VatCalculator::periodBasis($DB, TEST_COMPANY_ID,
    ['status' => 'prepared', 'basis' => 'invoice']),
    'prepared period trusts the basis stamped at prepare time');
$DB->prepare("UPDATE company_settings SET setting_value = 'invoice'
              WHERE company_id = ? AND setting_key = 'finance_vat_basis'")->execute([TEST_COMPANY_ID]);

test_done('vat_payments_basis');
