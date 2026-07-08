<?php
/**
 * DUE-DILIGENCE-2026-07 A1: a customer cash refund must post to the GL
 * (Dr AR / Cr Bank), not just the AR subledger. Before the fix qi/ajax/
 * refund_invoice.php only inserted a negative payment + allocation, so the
 * original receipt's Dr Bank / Cr AR stayed posted: the GL bank was overstated
 * by the cash paid out and AR control no longer tied to the invoice.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$map = new AccountsMap($DB, TEST_COMPANY_ID);
$arCode   = $map->code('finance_ar_account_id');
$bankCode = $map->code('finance_bank_account_id');
$cust = make_customer($DB);

// Issue an invoice for R115 (100 net + 15 VAT) and receive it in full.
$inv = make_invoice($DB, $cust, [[1, 100.00, 15, 'Widget']]);
$svc->postInvoice($inv);
$DB->prepare("UPDATE invoices SET status='sent', balance_due=115 WHERE id=?")->execute([$inv]);

$DB->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, received_by, created_at)
              VALUES (?, CURDATE(), 115, 'eft', 'RCPT-1', ?, NOW())")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID]);
$payId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,115)")
   ->execute([$payId, $inv]);
$svc->postCustomerPayment($payId);
$DB->prepare("UPDATE invoices SET status='paid', balance_due=0 WHERE id=?")->execute([$inv]);

$bankAfterReceipt = gl_balance_cents($DB, $bankCode);
$arAfterReceipt   = gl_balance_cents($DB, $arCode);

// --- Refund the full R115 exactly the way qi/ajax/refund_invoice.php does ----
$DB->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by, created_at)
              VALUES (?, CURDATE(), -115, 'refund', 'REF-1', 'test refund', ?, NOW())")
   ->execute([TEST_COMPANY_ID, TEST_USER_ID]);
$refId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,-115)")
   ->execute([$refId, $inv]);
$DB->prepare("UPDATE invoices SET balance_due=115, status='refunded' WHERE id=?")->execute([$inv]);
$svc->postCustomerRefund($refId);

// --- Assertions --------------------------------------------------------------
$refJournalId = (int)$DB->query("SELECT journal_id FROM payments WHERE id=$refId")->fetchColumn();
assert_true($refJournalId > 0, 'refund posts a GL journal (not just the subledger)');
assert_balanced_journal($DB, $refJournalId, '(customer refund)');

// Bank credited by 115 (cash out); AR debited by 115 (receivable reinstated).
assert_eq($bankAfterReceipt - 11500, gl_balance_cents($DB, $bankCode), 'refund credits Bank by 115.00');
assert_eq($arAfterReceipt + 11500, gl_balance_cents($DB, $arCode), 'refund debits AR by 115.00');

// After the full round-trip (invoice, receipt, refund) the trial balance still
// nets to zero — every leg is balanced and nothing is stranded.
$net = 0; foreach (tb_snapshot($DB) as $v) { $net += $v; }
assert_eq(0, $net, 'trial balance nets to zero after the refund');

// No VAT leg on the refund itself: the payments-basis output tax is clawed back
// by the negative allocation, not by this journal (s21 is a separate flow).
$vatOnRefund = (int)$DB->query("SELECT COUNT(*) FROM journal_lines
    WHERE journal_id = $refJournalId AND account_code = " . $DB->quote($map->code('finance_vat_output_account_id')))
    ->fetchColumn();
assert_eq(0, $vatOnRefund, 'refund journal books no VAT leg');

test_done('refund_gl');
