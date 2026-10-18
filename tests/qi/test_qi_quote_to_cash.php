<?php
/**
 * Quotes & Invoices end to end, through the libraries the pages and the
 * customer links use: quote → sent → accepted by the customer → invoice
 * (exactly once) → issued → part payment (a replayed payment is ignored) →
 * credit note → paid, with the ledger tied out at the end. Also covers the
 * create-once key, edit conflicts and the edit locks on accepted quotes and
 * paid invoices.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiActions.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
$arCode = (new AccountsMap($DB, $C))->code('finance_ar_account_id');
$cust = make_customer($DB, 'QI Customer');
// a full tax invoice over R5 000 needs the customer's address (SARS s20(4))
$DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, city, postal_code, country, created_at)
              VALUES (?, ?, 'billing', '12 Dock Road', 'Cape Town', '8001', 'ZA', NOW())")->execute([$C, $cust]);
$item = fn(string $d, $q, $p, $disc = 0, string $tax = 'STD') => ['kind' => 'item', 'description' => $d, 'qty' => $q, 'unit' => 'ea', 'price' => $p, 'discount' => $disc, 'tax_code' => $tax];
$row = function (string $table, int $id) use ($DB): array {
    $st = $DB->prepare("SELECT * FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$rejects = function (callable $fn, string $message): ?QiInvalid {
    try {
        $fn();
    } catch (QiInvalid $e) {
        assert_true(true, $message);
        return $e;
    }
    fail("ASSERT FAILED: $message (no error raised)");
    return null;
};

// ---------------------------------------------------------------- the quote
$quoteIn = [
    'customer_id' => $cust, 'issue_date' => date('Y-m-d'), 'expiry_date' => date('Y-m-d', strtotime('+14 days')),
    'reference' => 'RFQ 7', 'client_key' => 'qi-test-quote-0001',
    'rows' => [
        ['kind' => 'heading', 'title' => 'Groundwork'],
        $item('Site clearing', 1, 18500),
        $item('Compaction', 240, 38.5, 240),
        ['kind' => 'heading', 'title' => 'Extras'],
        $item('Engineer visit', 2, 2400, 0, 'ZERO'),
    ],
    'milestones' => [['label' => 'Deposit', 'mode' => 'percent', 'percentage' => 40], ['label' => 'Balance', 'mode' => 'percent', 'percentage' => 60]],
];
$q = QiWriter::save($DB, $C, $U, 'quote', $quoteIn);
assert_true($q['created'], 'quote created');
assert_eq('Q' . date('Y') . '-0001', $q['number'], 'first quote number of the year');
$qr = $row('quotes', $q['id']);
assert_cents(32300.00, $qr['subtotal'], 'quote net after line discounts');
assert_cents(240.00, $qr['discount'], 'quote discount');
assert_cents(4125.00, $qr['tax'], 'quote VAT per line (zero-rated line has none)');
assert_cents(36425.00, $qr['total'], 'quote total');
assert_eq(32, strlen((string)$qr['public_token']), 'quote has a customer link token');
assert_eq(2, count(LineHeadings::fetch($DB, 'quote', $q['id'])), 'quote headings stored');
$ms = $DB->query("SELECT ROUND(amount * 100) c FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = {$q['id']} ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
assert_eq([1457000, 2185500], array_map('intval', $ms), 'payment schedule splits the total exactly');

// a double-clicked "create" returns the first quote
$again = QiWriter::save($DB, $C, $U, 'quote', $quoteIn);
assert_eq($q['id'], $again['id'], 'same create key returns the same quote');
assert_true(!empty($again['replayed']), 'replay is reported');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM quotes WHERE customer_id = $cust")->fetchColumn(), 'no second quote');

// an edit based on an old version is refused, a current one goes through
$e = $rejects(fn() => QiWriter::save($DB, $C, $U, 'quote', ['id' => $q['id'], 'version' => '2000-01-01 00:00:00'] + $quoteIn), 'stale edit is refused');
assert_eq(409, $e->getCode(), 'stale edit → 409');
$edit = QiWriter::save($DB, $C, $U, 'quote', ['id' => $q['id'], 'version' => $q['version'], 'reference' => 'RFQ 7b'] + $quoteIn);
assert_eq($q['id'], $edit['id'], 'edit keeps the quote');
assert_eq('RFQ 7b', $row('quotes', $q['id'])['reference'], 'edit saved');

// ------------------------------------------------- sent, then accepted online
QiActions::markQuoteSent($DB, $C, $U, $q['id'], 'Emailed in test');
assert_eq('sent', $row('quotes', $q['id'])['status'], 'quote sent');
$rejects(fn() => QiActions::acceptQuote($DB, $C, null, $q['id'], ['by' => 'customer', 'name' => '']), 'customer must type a name to accept');
$acc = QiActions::acceptQuote($DB, $C, null, $q['id'], ['by' => 'customer', 'name' => 'Thandi Mokoena', 'ip' => '127.0.0.1']);
assert_eq('converted', $acc['status'], 'accepting makes the invoice');
assert_true(empty($acc['convert_error']), 'conversion had no error');
$invId = (int)$acc['invoice_id'];
assert_eq('INV' . date('Y') . '-0001', $acc['invoice_number'], 'first invoice number of the year');

// accepting twice, or converting again, never makes a second invoice
$twice = QiActions::acceptQuote($DB, $C, null, $q['id'], ['by' => 'customer', 'name' => 'Thandi Mokoena']);
assert_true(!empty($twice['already']), 'second accept is a no-op');
$conv = QiActions::convertQuote($DB, $C, $U, $q['id']);
assert_eq($invId, (int)$conv['invoice_id'], 'convert again returns the same invoice');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM invoices WHERE quote_id = {$q['id']}")->fetchColumn(), 'exactly one invoice for the quote');

$e = $rejects(fn() => QiWriter::save($DB, $C, $U, 'quote', ['id' => $q['id']] + $quoteIn), 'accepted quote is locked');
assert_true(!empty($e->extra['locked']), 'lock is reported');
$rejects(fn() => QiActions::declineQuote($DB, $C, $U, $q['id'], ['reason' => 'x']), 'invoiced quote cannot be declined');

// the invoice copies the quote exactly
$ir = $row('invoices', $invId);
assert_eq('draft', $ir['status'], 'converted invoice starts as a draft');
assert_cents($qr['total'], $ir['total'], 'invoice total = quote total');
assert_cents($qr['total'], $ir['balance_due'], 'nothing paid yet');
assert_eq(3, (int)$DB->query("SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = $invId")->fetchColumn(), 'lines copied');
assert_eq(2, count(LineHeadings::fetch($DB, 'invoice', $invId)), 'headings copied');
$ims = $DB->query("SELECT ROUND(amount * 100) FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = $invId ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
assert_eq([1457000, 2185500], array_map('intval', $ims), 'schedule copied to the invoice');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(), 'a draft is not in the ledger');

// --------------------------------------------------------------- issue + pay
$iss = QiActions::issueInvoice($DB, $C, $U, $invId);
assert_true($iss['issued'], 'invoice issued');
$ir = $row('invoices', $invId);
assert_eq('sent', $ir['status'], 'issued invoice is open');
assert_true((int)$ir['journal_id'] > 0, 'issued invoice is posted');
assert_balanced_journal($DB, (int)$ir['journal_id'], '(invoice)');
assert_eq(3642500, gl_balance_cents($DB, $arCode), 'AR control = invoice total');

$pay = ['amount' => 14570, 'date' => date('Y-m-d'), 'method' => 'eft', 'reference' => 'DEP', 'idempotency_key' => 'qi-test-pay-0001'];
$p1 = QiActions::recordPayment($DB, $C, $U, $invId, $pay);
assert_eq('part-paid', $p1['status'], 'deposit → part paid');
assert_cents(21855.00, $p1['balance'], 'balance after the deposit');
$p2 = QiActions::recordPayment($DB, $C, $U, $invId, $pay);
assert_true($p2['duplicate'], 'replayed payment is recognised');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM payment_allocations WHERE invoice_id = $invId")->fetchColumn(), 'replay records nothing');
assert_cents(21855.00, $row('invoices', $invId)['balance_due'], 'replay leaves the balance');
$rejects(fn() => QiActions::recordPayment($DB, $C, $U, $invId, ['amount' => 50000, 'method' => 'eft']), 'cannot receive more than is owing');
assert_eq(2185500, gl_balance_cents($DB, $arCode), 'AR control after the deposit');
$paidMs = $DB->query("SELECT status FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = $invId ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
assert_eq('paid', $paidMs[0], 'deposit milestone settled');

// payments recorded → amounts locked, wording still editable
$before = $row('invoices', $invId);
$w = QiWriter::save($DB, $C, $U, 'invoice', ['id' => $invId, 'customer_id' => $cust, 'reference' => 'PO 991', 'terms' => 'Pay on completion',
    'due_date' => date('Y-m-d', strtotime('+20 days')), 'rows' => [$item('Something else', 1, 1)]]);
$after = $row('invoices', $invId);
assert_eq('wording', $w['lock']['level'], 'part-paid invoice is wording-only');
assert_eq('PO 991', $after['reference'], 'reference changed');
assert_cents($before['total'], $after['total'], 'amounts untouched');
assert_eq(3, (int)$DB->query("SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = $invId")->fetchColumn(), 'lines untouched');

// ----------------------------------------------------- credit note settles it
$cn = QiWriter::save($DB, $C, $U, 'credit_note', ['invoice_id' => $invId, 'issue_date' => date('Y-m-d'), 'reason' => 'Engineer visit not needed',
    'reason_code' => 'correction', 'rows' => [$item('Engineer visit', 1, 2400, 0, 'ZERO')]]);
assert_eq('CN' . date('Y') . '-0001', $cn['number'], 'first credit note number');
$rejects(fn() => QiWriter::save($DB, $C, $U, 'credit_note', ['invoice_id' => $invId, 'reason' => 'Too much', 'rows' => [$item('Too much', 1, 999999)]]),
    'credit cannot exceed what is owing');
QiActions::approveCredit($DB, $C, $U, $cn['id']);
$cnr = $row('credit_notes', $cn['id']);
assert_eq('approved', $cnr['status'], 'credit approved');
assert_true((int)$cnr['journal_id'] > 0, 'approved credit is posted');
assert_balanced_journal($DB, (int)$cnr['journal_id'], '(credit note)');
$ap = QiActions::applyCredit($DB, $C, $U, $cn['id']);
assert_cents(2400.00, $ap['applied'], 'credit applied to the invoice');
assert_cents(19455.00, $row('invoices', $invId)['balance_due'], 'balance after the credit');
$e = $rejects(fn() => QiWriter::save($DB, $C, $U, 'credit_note', ['id' => $cn['id'], 'invoice_id' => $invId, 'reason' => 'x', 'rows' => [$item('x', 1, 1)]]),
    'approved credit note is locked');

$last = QiActions::recordPayment($DB, $C, $U, $invId, ['amount' => 19455, 'method' => 'eft', 'idempotency_key' => 'qi-test-pay-0002']);
assert_eq('paid', $last['status'], 'final payment → paid');
assert_cents(0, $row('invoices', $invId)['balance_due'], 'nothing owing');
assert_eq(0, gl_balance_cents($DB, $arCode), 'AR control back to zero');
$rejects(fn() => QiActions::recordPayment($DB, $C, $U, $invId, ['amount' => 1, 'method' => 'eft']), 'paid invoice takes no more payments');

// every journal balances, the timeline tells the story
$tb = array_sum(tb_snapshot($DB));
assert_eq(0, $tb, 'trial balance nets to zero');
$actions = $DB->query("SELECT action FROM qi_activity WHERE doc_type = 'invoice' AND doc_id = $invId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach (['created', 'issued', 'payment', 'credit'] as $a) {
    assert_true(in_array($a, $actions, true), "invoice timeline has '$a'");
}
$qActions = $DB->query("SELECT action FROM qi_activity WHERE doc_type = 'quote' AND doc_id = {$q['id']} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach (['created', 'edited', 'sent', 'accepted', 'converted'] as $a) {
    assert_true(in_array($a, $qActions, true), "quote timeline has '$a'");
}

test_done('qi_quote_to_cash');
