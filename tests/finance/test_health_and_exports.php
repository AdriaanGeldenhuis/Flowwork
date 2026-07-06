<?php
/**
 * WS8: health checks + VAT audit file export. Seeds a standard invoice and an
 * AP bill through the posting engine, then asserts (a) the AR/AP tie-outs the
 * health page reports, (b) the health-page integrity queries (unbalanced
 * journals, untagged revenue) come back clean, and (c) the VAT audit file
 * (finances/lib/VatAuditFile.php — shared by the export endpoint) FOOTS to
 * the VAT201 Box 5 / Box 9 totals on both accounting bases.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatCalculator.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatAuditFile.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$vatOut = $map->code('finance_vat_output_account_id');
$vatIn  = $map->code('finance_vat_input_account_id');
$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$cust = make_customer($DB);
$sup = make_supplier($DB);
$start = date('Y-m-01');
$end = date('Y-m-t');

// --- Seed: mixed invoice (1000@15 + 500 zero) and AP bill 400@15 -------------
$inv = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std'], [1, 500.00, 0, 'Zero', 'tax_code' => 'ZERO']]);
$svc->postInvoice($inv);
$DB->prepare("UPDATE invoices SET status='sent' WHERE id = ?")->execute([$inv]);

$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), 'HEX-1', 400, 60, 460, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$billId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order)
              VALUES (?, 'Widgets', 1, 400, 15, 460, 0)")->execute([$billId]);
$svc->postApBill($billId);

// --- (a) AR / AP tie-outs (as health.php checks 3.11/3.12 report them) -------
$tieout = new Tieout($DB, TEST_COMPANY_ID);
$asOf = date('Y-m-d');
assert_cents(1650.00, $tieout->arSubledger($asOf), 'AR subledger = invoice total');
assert_cents(1650.00, $tieout->glBalance('AR', $asOf), 'GL AR control ties to subledger');
assert_cents(460.00, $tieout->apSubledger($asOf), 'AP subledger = bill total');
assert_cents(460.00, $tieout->glBalance('AP', $asOf), 'GL AP control ties to subledger');

// --- (b) Health integrity queries (replicated from finances/tools/health.php)
$stmt = $DB->prepare(
    "SELECT je.id
       FROM journal_entries je
       JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = ? AND je.status = 'posted'
      GROUP BY je.id
     HAVING SUM(ROUND(jl.debit*100)) <> SUM(ROUND(jl.credit*100))"
);
$stmt->execute([TEST_COMPANY_ID]);
assert_eq(0, count($stmt->fetchAll(PDO::FETCH_COLUMN)), 'no unbalanced posted journals');

$stmt = $DB->prepare(
    "SELECT COUNT(*)
       FROM journal_lines jl
       JOIN journal_entries je ON je.id = jl.journal_id
       JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
      WHERE je.company_id = ? AND je.status = 'posted'
        AND ga.account_type = 'revenue'
        AND jl.tax_code_id IS NULL"
);
$stmt->execute([TEST_COMPANY_ID]);
assert_eq(0, (int)$stmt->fetchColumn(), 'no untagged posted revenue lines');

// --- (c) VAT audit file foots to the VAT201 — invoice basis -------------------
$boxes = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'invoice');
$rows = VatAuditFile::invoiceBasisRows($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);
assert_true(count($rows) >= 4, 'audit file has one row per contributing line');
foreach (['entry_date','journal_id','reference','source_type','description','account_code',
          'tax_code','rate_percent','is_capital_goods','debit','credit','net_cents','vat_cents','section'] as $k) {
    assert_true(array_key_exists($k, $rows[0]), "audit row carries '$k'");
}
$totals = VatAuditFile::totals($rows);
assert_eq(15000, $boxes['box5_total_output_cents'], 'Box 5 sanity: output VAT 150.00');
assert_eq(6000, $boxes['box9_total_input_cents'], 'Box 9 sanity: input VAT 60.00');
assert_eq($boxes['box5_total_output_cents'], $totals['output_vat_cents'],
    'invoice-basis audit file output VAT foots to Box 5');
assert_eq($boxes['box9_total_input_cents'], $totals['input_vat_cents'],
    'invoice-basis audit file input VAT foots to Box 9');

// Revenue rows are present (base detail) but contribute no VAT to the totals.
$revenueRows = array_values(array_filter($rows, fn($r) => $r['section'] === 'revenue'));
assert_true(count($revenueRows) >= 2, 'revenue base rows included');
assert_eq(0, array_sum(array_map(fn($r) => $r['vat_cents'], $revenueRows)), 'revenue rows carry no VAT');

// --- (c continued) payments basis: half-settle both documents ----------------
$DB->prepare("INSERT INTO payments (company_id, amount, payment_date, method, reference, received_by, created_at)
              VALUES (?, 825.00, CURDATE(), 'eft', 'HEXPAY-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID]);
$payId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,825.00)")
   ->execute([$payId, $inv]);
$svc->postCustomerPayment($payId);

$DB->prepare("INSERT INTO ap_payments (company_id, supplier_id, amount, payment_date, method, reference, created_by, created_at)
              VALUES (?, ?, 230.00, CURDATE(), 'eft', 'HEXAPP-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, TEST_USER_ID]);
$apPayId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_payment_allocations (ap_payment_id, bill_id, amount) VALUES (?,?,230.00)")
   ->execute([$apPayId, $billId]);
$svc->postSupplierPayment($apPayId);

$pBoxes = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn, 'payments');
$pRows = VatAuditFile::paymentsBasisRows($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);
$pTotals = VatAuditFile::totals($pRows);
assert_eq(7500, $pBoxes['box5_total_output_cents'], 'payments basis: half the output VAT received');
assert_eq(3000, $pBoxes['box9_total_input_cents'], 'payments basis: half the input VAT paid');
assert_eq($pBoxes['box5_total_output_cents'], $pTotals['output_vat_cents'],
    'payments-basis audit file output VAT foots to Box 5');
assert_eq($pBoxes['box9_total_input_cents'], $pTotals['input_vat_cents'],
    'payments-basis audit file input VAT foots to Box 9');

// Allocation rows carry the invoice number and the allocation amount.
$allocRows = array_values(array_filter($pRows, fn($r) => $r['source_type'] === 'payment_allocation'));
assert_true(count($allocRows) >= 1, 'payments-basis file has receipt allocation rows');
$invNumber = $DB->query("SELECT invoice_number FROM invoices WHERE id = $inv")->fetchColumn();
assert_eq($invNumber, $allocRows[0]['reference'], 'allocation row references the invoice number');
assert_cents(825.00, $allocRows[0]['allocation'], 'allocation row carries the allocated amount');

// Tie-outs still hold after the receipts/payments (health checks stay green).
assert_cents(825.00, $tieout->arSubledger($asOf), 'AR subledger after receipt');
assert_cents(825.00, $tieout->glBalance('AR', $asOf), 'GL AR control after receipt');
assert_cents(230.00, $tieout->apSubledger($asOf), 'AP subledger after part-payment');
assert_cents(230.00, $tieout->glBalance('AP', $asOf), 'GL AP control after part-payment');

// ---------------------------------------------------------------------------
// REGRESSION (review): the AR tie-out must survive a write-off (the write-off
// journal credits GL AR; the subledger used to keep the full face value and
// show a permanent spurious difference) and an UNAPPLIED receipt (allocations
// deleted after posting — the posted Cr AR must still be counted).
// ---------------------------------------------------------------------------
$invWo = make_invoice($DB, $cust, [[1, 400.00, 15, 'To write off']], ['status' => 'sent']); // 460
$svc->postInvoice($invWo);
$svc->postInvoiceWriteOff($invWo, 460.00, 'gone under');
$DB->prepare("UPDATE invoices SET balance_due = 0, status = 'written_off',
              write_off_amount = 460.00 WHERE id = ?")->execute([$invWo]);

assert_cents($tieout->glBalance('AR', $asOf), $tieout->arSubledger($asOf),
    'AR subledger still ties to the GL control after a full write-off');

// Unapplied receipt: pay 100 against the mixed invoice, post it, then delete
// the allocation (what invoice_action unapply_payments does).
$DB->prepare("INSERT INTO payments (company_id, amount, payment_date, method, reference, received_by, created_at)
              VALUES (?, 100.00, ?, 'eft', 'UNAPPLIED-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $asOf, TEST_USER_ID]);
$unapPid = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,100.00)")
   ->execute([$unapPid, $inv]);
$svc->postCustomerPayment($unapPid);
$DB->prepare("DELETE FROM payment_allocations WHERE payment_id = ?")->execute([$unapPid]);

assert_cents($tieout->glBalance('AR', $asOf), $tieout->arSubledger($asOf),
    'AR subledger counts the unapplied receipt as a customer credit (ties to GL)');

test_done('health_and_exports');
