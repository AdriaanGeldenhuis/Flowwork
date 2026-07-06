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
    'credit note reduces output tax by 15.00 at issue date');

test_done('vat_payments_basis');
