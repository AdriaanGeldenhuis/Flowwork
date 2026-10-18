<?php
/**
 * Recurring invoices: the schedule (month ends, leap years, quarters, weeks,
 * catching up without a backlog), the scheduled run (one invoice per period,
 * issued and posted, headings and notes carried over, never twice), pausing
 * and resuming, the end date, and a failing template that is reported once a
 * day without stopping the others.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiWriter.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiRecurring.php';

QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
$today = date('Y-m-d');

// ---------------------------------------------------------------- the dates
assert_eq('2026-02-28', QiRecurring::nextDate('2026-01-31', 'monthly', 1, 31), '31 Jan → 28 Feb');
assert_eq('2026-03-31', QiRecurring::nextDate('2026-02-28', 'monthly', 1, 31), 'back to the 31st in March');
assert_eq('2028-02-29', QiRecurring::nextDate('2028-01-31', 'monthly', 1, 31), 'leap year February');
assert_eq('2027-02-28', QiRecurring::nextDate('2026-11-30', 'quarterly', 1, 30), 'quarterly across a year end');
assert_eq('2029-02-28', QiRecurring::nextDate('2028-02-29', 'yearly', 1, 29), 'yearly from 29 Feb');
assert_eq('2026-11-01', QiRecurring::nextDate('2026-10-18', 'weekly', 2), 'every two weeks');
assert_eq('2026-12-15', QiRecurring::nextDate('2026-10-15', 'monthly', 2, 15), 'every two months');
assert_eq('2026-11-15', QiRecurring::catchUp('2026-01-15', 'monthly', 1, 15, '2026-10-18'), 'catch up to the next date, no backlog');
assert_eq('2026-10-18', QiRecurring::catchUp('2026-10-18', 'monthly', 1, 18, '2026-10-18'), 'a date that is today stays');

// ---------------------------------------------------------- scheduled runs
$cust = make_customer($DB, 'Retainer Client');
$DB->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, city, postal_code, country, created_at)
              VALUES (?, ?, 'billing', '88 Kloof Street', 'Cape Town', '8001', 'ZA', NOW())")->execute([$C, $cust]);
$rows = [
    ['kind' => 'heading', 'title' => 'Monthly services'],
    ['kind' => 'item', 'description' => 'Yard maintenance retainer', 'qty' => 1, 'unit' => 'month', 'price' => 12500, 'discount' => 0, 'tax_code' => 'STD'],
];
$rec = QiWriter::save($DB, $C, $U, 'recurring', ['customer_id' => $cust, 'template_name' => 'Retainer', 'frequency' => 'monthly', 'interval_count' => 1,
    'next_run_date' => $today, 'payment_terms_days' => 14, 'reference' => 'Contract 12', 'customer_notes' => 'Monthly retainer as agreed.', 'rows' => $rows]);
$recId = (int)$rec['id'];

$run = QiRecurring::runDue($DB, 50);
assert_eq(1, $run['generated'], 'the due template generated an invoice');
assert_eq(0, $run['failed'], 'no failures');
$inv = $DB->query("SELECT * FROM invoices WHERE recurring_invoice_id = $recId")->fetch(PDO::FETCH_ASSOC);
assert_true((bool)$inv, 'invoice linked to its template');
assert_eq('sent', $inv['status'], 'generated invoice is issued');
assert_true((int)$inv['journal_id'] > 0, 'and posted to the ledger');
assert_balanced_journal($DB, (int)$inv['journal_id'], '(recurring invoice)');
assert_cents(14375, $inv['total'], 'R12 500 + VAT');
assert_eq(date('Y-m-d', strtotime($today . ' +14 days')), $inv['due_date'], 'payment terms of the template');
assert_eq('Contract 12', $inv['reference'], 'reference carried over');
assert_eq('Monthly retainer as agreed.', $inv['customer_notes'], 'notes to the customer carried over');
assert_eq(1, count(LineHeadings::fetch($DB, 'invoice', (int)$inv['id'])), 'headings carried over');
$tpl = $DB->query("SELECT * FROM recurring_invoices WHERE id = $recId")->fetch(PDO::FETCH_ASSOC);
$anchor = (int)date('j', strtotime($today));
assert_eq(QiRecurring::nextDate($today, 'monthly', 1, $anchor), $tpl['next_run_date'], 'next run a month later');
assert_eq((int)$inv['id'], (int)$tpl['last_invoice_id'], 'template remembers its last invoice');

$again = QiRecurring::runDue($DB, 50);
assert_eq(0, $again['generated'], 'running again the same day makes nothing');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM invoices WHERE recurring_invoice_id = $recId")->fetchColumn(), 'one invoice per period');

// ------------------------------------------------------- pause and resume
QiRecurring::setActive($DB, $C, $recId, false, $U);
$DB->exec("UPDATE recurring_invoices SET next_run_date = DATE_SUB(CURDATE(), INTERVAL 70 DAY) WHERE id = $recId");
assert_eq(0, QiRecurring::runDue($DB, 50)['generated'], 'a paused template does not run');
try {
    QiRecurring::generate($DB, $C, $recId, $U, true);
    fail('ASSERT FAILED: generating a paused template must fail');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'paused'), 'paused template refuses "Generate now"');
}
$res = QiRecurring::setActive($DB, $C, $recId, true, $U);
assert_true($res['next_run_date'] >= $today, 'resuming skips the missed months instead of billing them');

// ------------------------------------------------------------- end date
$last = QiWriter::save($DB, $C, $U, 'recurring', ['customer_id' => $cust, 'template_name' => 'Last one', 'frequency' => 'monthly', 'interval_count' => 1,
    'next_run_date' => $today, 'end_date' => $today, 'rows' => $rows]);
$g = QiRecurring::generate($DB, $C, (int)$last['id'], $U, false);
assert_true($g['ended'], 'the run on the end date is the last');
assert_eq(0, (int)$DB->query("SELECT active FROM recurring_invoices WHERE id = " . (int)$last['id'])->fetchColumn(), 'template stops after its last invoice');

// --------------------------------- a failing template is reported, once a day
ini_set('error_log', '/dev/null'); // the expected failure is also error_logged
$bare = make_customer($DB, 'No Address Client');
$bad = QiWriter::save($DB, $C, $U, 'recurring', ['customer_id' => $bare, 'template_name' => 'Big job', 'frequency' => 'monthly', 'interval_count' => 1,
    'next_run_date' => $today, 'rows' => [['kind' => 'item', 'description' => 'Big job', 'qty' => 1, 'price' => 90000, 'tax_code' => 'STD']]]);
$badId = (int)$bad['id'];
$r1 = QiRecurring::runDue($DB, 50);
assert_eq(1, $r1['failed'], 'invalid tax invoice (no customer address over R5 000) fails');
$r2 = QiRecurring::runDue($DB, 50);
assert_eq(0, $r2['failed'] + $r2['generated'], 'a failed template waits an hour instead of blocking every run');
$DB->exec("UPDATE recurring_invoices SET last_attempt_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = $badId");
$r3 = QiRecurring::runDue($DB, 50);
assert_eq(1, $r3['failed'], 'tried again after the hour, still failing until it is fixed');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM invoices WHERE recurring_invoice_id = " . (int)$bad['id'])->fetchColumn(), 'nothing half-made');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM qi_activity WHERE doc_type = 'recurring' AND doc_id = " . (int)$bad['id'] . " AND action = 'generate_failed'")->fetchColumn(),
    'the failure is on the timeline once a day');
assert_eq($today, (string)$DB->query("SELECT next_run_date FROM recurring_invoices WHERE id = $badId")->fetchColumn(), 'the schedule does not move on a failure');

// failing templates never hold up the healthy ones behind them
$DB->exec("UPDATE recurring_invoices SET next_run_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY), last_attempt_at = NULL WHERE id = $badId");
$ok = QiWriter::save($DB, $C, $U, 'recurring', ['customer_id' => $cust, 'template_name' => 'Healthy', 'frequency' => 'monthly', 'interval_count' => 1,
    'next_run_date' => $today, 'rows' => $rows]);
$q1 = QiRecurring::runDue($DB, 1);          // room for one: the older, failing template
assert_eq(1, $q1['failed'], 'the older failing template is tried first');
assert_true($q1['more'], 'and the run says more is waiting');
$q2 = QiRecurring::runDue($DB, 1);          // the failing one now waits, the healthy one runs
assert_eq(1, $q2['generated'], 'the healthy template is not blocked');

// editing a template makes it eligible again straight away
$DB->exec("UPDATE recurring_invoices SET last_attempt_at = NOW() WHERE id = $badId");
QiWriter::save($DB, $C, $U, 'recurring', ['id' => $badId, 'customer_id' => $bare, 'template_name' => 'Big job', 'frequency' => 'monthly', 'interval_count' => 1,
    'next_run_date' => $today, 'rows' => [['kind' => 'item', 'description' => 'Smaller job', 'qty' => 1, 'price' => 1000, 'tax_code' => 'STD']]]);
assert_true($DB->query("SELECT last_attempt_at FROM recurring_invoices WHERE id = $badId")->fetchColumn() === null, 'an edit clears the back-off');
assert_eq(1, QiRecurring::runDue($DB, 50)['generated'], 'the corrected template (under R5 000) is billed');

test_done('qi_recurring');
