<?php
/**
 * DUE-DILIGENCE-2026-07 B11: a standalone credit note (no linked invoice) must
 * post to the GL. It is never routed through apply_credit_note.php (which
 * requires an invoice), so approve_credit_note.php now posts it at approval.
 * PostingService::postCreditNote must handle a NULL invoice_id, and the AR
 * subledger must still tie to the GL AR control (the CN is a customer credit).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$map = new AccountsMap($DB, TEST_COMPANY_ID);
$arCode = $map->code('finance_ar_account_id');
$cust = make_customer($DB);
$asOf = date('Y-m-d');

// Standalone credit note: no invoice_id, status 'approved' (as approve_credit_note
// leaves it), R115 = 100 net + 15 output VAT.
$DB->prepare("INSERT INTO credit_notes
        (company_id, credit_note_number, invoice_id, customer_id, issue_date, status,
         subtotal, tax, total, currency, exchange_rate, reason, reason_code, created_by, journal_id)
     VALUES (?, CONCAT('CN-STD-', UUID_SHORT()), NULL, ?, CURDATE(), 'approved',
         100, 15, 115, 'ZAR', 1, 'goodwill', 'other', ?, NULL)")
   ->execute([TEST_COMPANY_ID, $cust, TEST_USER_ID]);
$cnId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO credit_note_lines
        (credit_note_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order)
     VALUES (?, 'Goodwill credit', 1, 'ea', 100, 0, 15, ?, 115, 0)")
   ->execute([$cnId, tax_code_id_by_code($DB, 'STD')]);

assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE ref_type='credit_note'")->fetchColumn(),
    'no journal before posting');

$svc->postCreditNote($cnId);

$jid = (int)$DB->query("SELECT journal_id FROM credit_notes WHERE id=$cnId")->fetchColumn();
assert_true($jid > 0, 'standalone credit note posts a GL journal (B11)');
assert_balanced_journal($DB, $jid, '(standalone credit note)');

// The CN debits Sales Return + VAT Output and credits AR, so AR nets -115
// (a customer credit balance).
assert_eq(-11500, gl_balance_cents($DB, $arCode), 'standalone CN credits AR control by 115.00');

// Tie-out: the AR subledger subtracts posted credit-note totals, so it must
// still equal the GL AR control.
$tieout = new Tieout($DB, TEST_COMPANY_ID);
assert_cents($tieout->glBalance('AR', $asOf), $tieout->arSubledger($asOf),
    'AR subledger == GL AR control with a standalone credit note posted');

test_done('credit_note_standalone');
