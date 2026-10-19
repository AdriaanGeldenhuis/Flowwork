<?php
/**
 * Quotes & Invoices: putting mistakes right without breaking the books.
 * Void → reopen → issue again; unapply payments so an invoice can be voided;
 * partial and full write-off; refunds; deleting drafts vs issued invoices
 * (admins only) and restoring them; crediting in full, unapplying and
 * cancelling a credit note; archiving; uncollectible → reopen. After every
 * step the AR control account must equal what customers really owe.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiActions.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';

QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
$arCode = (new AccountsMap($DB, $C))->code('finance_ar_account_id');
$cust = make_customer($DB, 'QI Corrections');
$DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, city, postal_code, country, created_at)
              VALUES (?, ?, 'billing', '3 Quay Road', 'Cape Town', '8001', 'ZA', NOW())")->execute([$C, $cust]);

$newInvoice = function (float $price) use ($DB, $C, $U, $cust): int {
    $r = QiWriter::save($DB, $C, $U, 'invoice', ['customer_id' => $cust, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+30 days')),
        'rows' => [['kind' => 'item', 'description' => 'Work', 'qty' => 1, 'unit' => 'job', 'price' => $price, 'discount' => 0, 'tax_code' => 'STD']]]);
    return (int)$r['id'];
};
$inv = function (int $id) use ($DB): array {
    return $DB->query("SELECT * FROM invoices WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
};
$rejects = function (callable $fn, string $message): void {
    try {
        $fn();
    } catch (QiInvalid $e) {
        assert_true(true, $message);
        return;
    }
    fail("ASSERT FAILED: $message (no error raised)");
};
// The AR control account must tie to the debtors subledger (the finance
// module's own definition: issued invoices less receipts — allocated or kept on
// account — posted credit notes and write-offs).
$tieout = new Tieout($DB, $C);
$tied = function (string $when) use ($DB, $arCode, $tieout): void {
    $asOf = date('Y-m-d');
    assert_eq((int)round($tieout->arSubledger($asOf) * 100), gl_balance_cents($DB, $arCode), "AR control ties to the debtors $when");
};
$owing = function () use ($DB, $C): int {
    return (int)$DB->query("SELECT COALESCE(SUM(ROUND(balance_due * 100)), 0) FROM invoices
                             WHERE company_id = $C AND deleted_at IS NULL AND status NOT IN ('draft', 'cancelled')")->fetchColumn();
};

// --- void, reopen, issue again --------------------------------------------
$a = $newInvoice(1000);                       // R1 150 incl. VAT
QiActions::issueInvoice($DB, $C, $U, $a);
$tied('after issue');
$rejects(fn() => QiActions::voidInvoice($DB, $C, $U, $newInvoice(10), 'x'), 'a draft cannot be voided');
QiActions::voidInvoice($DB, $C, $U, $a, 'Wrong customer');
assert_eq('cancelled', $inv($a)['status'], 'voided');
assert_true(empty($inv($a)['journal_id']), 'void unlinks the ledger entry');
$tied('after void');
$rejects(fn() => QiWriter::save($DB, $C, $U, 'invoice', ['id' => $a, 'customer_id' => $cust, 'rows' => [['kind' => 'item', 'description' => 'x', 'qty' => 1, 'price' => 1]]]),
    'a voided invoice cannot be edited');
QiActions::reopenInvoice($DB, $C, $U, $a);
$r = $inv($a);
assert_eq('draft', $r['status'], 'reopened as a draft');
assert_cents(1150, $r['balance_due'], 'reopened invoice owes its full total again');
QiActions::issueInvoice($DB, $C, $U, $a);
assert_true((int)$inv($a)['journal_id'] > 0, 'issued again → posted again');
$tied('after re-issue');

// --- a paid invoice can only be voided once its payments are detached -----
$b = $newInvoice(2000);                       // R2 300
QiActions::issueInvoice($DB, $C, $U, $b);
QiActions::recordPayment($DB, $C, $U, $b, ['amount' => 300, 'method' => 'eft']);
$rejects(fn() => QiActions::voidInvoice($DB, $C, $U, $b, 'x'), 'payments block a void');
$rejects(fn() => QiActions::reopenInvoice($DB, $C, $U, $b), 'payments block reopening');
$un = QiActions::unapplyPayments($DB, $C, $U, $b);
assert_eq('sent', $un['status'], 'payments removed → open again');
assert_cents(2300, $inv($b)['balance_due'], 'full total owing after unapply');
$tied('after unapplying a payment (R300 kept on account)');
QiActions::voidInvoice($DB, $C, $U, $b, 'Duplicate');
assert_eq('cancelled', $inv($b)['status'], 'voided after unapply');

// --- write-offs --------------------------------------------------------------
$c = $newInvoice(4000);                       // R4 600
QiActions::issueInvoice($DB, $C, $U, $c);
$w1 = QiActions::writeOff($DB, $C, $U, $c, 600, 'Settlement discount');
assert_cents(4000, $w1['balance'], 'part written off');
assert_eq('sent', $w1['status'], 'still open after a partial write-off');
$tied('after a partial write-off');
$rejects(fn() => QiActions::writeOff($DB, $C, $U, $c, 5000, 'x'), 'cannot write off more than is owing');
$rejects(fn() => QiActions::voidInvoice($DB, $C, $U, $c, 'x'), 'a write-off blocks a void');
$w2 = QiActions::writeOff($DB, $C, $U, $c, null, 'Customer liquidated');
assert_eq('written_off', $w2['status'], 'rest written off');
assert_cents(0, $inv($c)['balance_due'], 'nothing owing after the write-off');
$tied('after a full write-off');

// --- refunds ---------------------------------------------------------------
$d = $newInvoice(1000);                       // R1 150
QiActions::issueInvoice($DB, $C, $U, $d);
QiActions::recordPayment($DB, $C, $U, $d, ['amount' => 1150, 'method' => 'eft']);
assert_eq('paid', $inv($d)['status'], 'paid');
$rejects(fn() => QiActions::refund($DB, $C, $U, $d, ['amount' => 2000]), 'cannot refund more than was paid');
$rf = QiActions::refund($DB, $C, $U, $d, ['amount' => 150, 'reason' => 'Returned part']);
assert_eq('part-paid', $rf['status'], 'partial refund reopens the balance');
assert_cents(150, $inv($d)['balance_due'], 'refunded amount is owing again');
$tied('after a refund');

// --- deleting --------------------------------------------------------------
$draft = $newInvoice(50);
$del = QiActions::deleteInvoice($DB, $C, $U, $draft, false);
assert_eq('hard', $del['mode'], 'a draft is simply removed');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM invoices WHERE id = $draft")->fetchColumn(), 'draft gone');
$e = $newInvoice(700);                        // R805
QiActions::issueInvoice($DB, $C, $U, $e);
$rejects(fn() => QiActions::deleteInvoice($DB, $C, $U, $e, false), 'only an admin can delete an issued invoice');
$del = QiActions::deleteInvoice($DB, $C, $U, $e, true, 'Entered twice');
assert_eq('soft', $del['mode'], 'issued invoice is soft-deleted');
assert_true(!empty($inv($e)['deleted_at']), 'marked deleted');
$tied('after deleting an issued invoice');
$rejects(fn() => QiActions::recordPayment($DB, $C, $U, $e, ['amount' => 1, 'method' => 'eft']), 'no payments on a deleted invoice');
QiActions::restoreInvoice($DB, $C, $U, $e);
assert_true(empty($inv($e)['deleted_at']), 'restored');
assert_true((int)$inv($e)['journal_id'] > 0, 'restored invoice is back in the ledger');
$tied('after restore');

// --- credit in full, unapply, cancel ----------------------------------------
$f = $newInvoice(3000);                       // R3 450
QiActions::issueInvoice($DB, $C, $U, $f);
$rejects(fn() => QiActions::creditInFull($DB, $C, $U, $f, ''), 'a credit note needs a reason');
$cf = QiActions::creditInFull($DB, $C, $U, $f, 'Job cancelled by the customer');
assert_cents(3450, $cf['applied'], 'credited in full');
assert_cents(0, $inv($f)['balance_due'], 'nothing owing after the full credit');
$tied('after a full credit');
$rejects(fn() => QiActions::creditInFull($DB, $C, $U, $f, 'Again'), 'only one full credit');
$cnId = (int)$cf['credit_note_id'];
$rejects(fn() => QiActions::cancelCredit($DB, $C, $U, $cnId), 'an applied credit cannot be cancelled');
QiActions::unapplyCredit($DB, $C, $U, $cnId);
assert_cents(3450, $inv($f)['balance_due'], 'credit taken off the invoice');
assert_eq('sent', $inv($f)['status'], 'invoice open again');
QiActions::cancelCredit($DB, $C, $U, $cnId, 'Customer went ahead after all');
$cn = $DB->query("SELECT status, journal_id FROM credit_notes WHERE id = $cnId")->fetch(PDO::FETCH_ASSOC);
assert_eq('cancelled', $cn['status'], 'credit note cancelled');
assert_true(empty($cn['journal_id']), 'cancelled credit note left the ledger');
$tied('after cancelling the credit note');
$rejects(fn() => QiActions::deleteCredit($DB, $C, $U, $cnId), 'only a draft credit note can be deleted');

// --- archive, uncollectible --------------------------------------------------
$rejects(fn() => QiActions::archiveInvoice($DB, $C, $U, $f, true), 'an owing invoice cannot be archived');
QiActions::archiveInvoice($DB, $C, $U, $c, true);           // written off: nothing owing
assert_true(!empty($inv($c)['archived_at']), 'archived');
QiActions::archiveInvoice($DB, $C, $U, $c, false);
assert_true(empty($inv($c)['archived_at']), 'back from the archive');
QiActions::markUncollectible($DB, $C, $U, $f, 'No contact');
assert_eq('uncollectible', $inv($f)['status'], 'marked uncollectible');
QiActions::reopenInvoice($DB, $C, $U, $f);
assert_eq('sent', $inv($f)['status'], 'uncollectible → open again');
$tied('at the end');
// a: R1 150 · d: R150 refunded · e: R805 restored · f: R3 450 reopened
assert_eq(115000 + 15000 + 80500 + 345000, $owing(), 'what customers owe at the end');
assert_eq(0, array_sum(tb_snapshot($DB)), 'trial balance nets to zero');

test_done('qi_corrections');
