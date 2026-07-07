<?php
/**
 * WS2: post-on-issue lifecycle. Drafts stay out of the GL; issuing posts;
 * issuing twice does not duplicate; voiding reverses and unlinks.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/qi/lib/InvoiceLifecycle.php';

$cust = make_customer($DB);
$inv = make_invoice($DB, $cust, [[1, 1000.00, 15]]); // status draft

// Draft: no journal.
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(),
    'draft invoice has no GL journal');

// Issue: draft -> sent + posted.
$posted = InvoiceLifecycle::issueInvoice($DB, TEST_COMPANY_ID, TEST_USER_ID, $inv);
assert_true($posted, 'issue posts the journal');
$row = $DB->query("SELECT status, journal_id FROM invoices WHERE id = $inv")->fetch();
assert_eq('sent', $row['status'], 'issue transitions draft -> sent');
assert_true((int)$row['journal_id'] > 0, 'issue links the journal');
assert_balanced_journal($DB, (int)$row['journal_id'], '(issued invoice)');

// Issue again (resend): no duplicate journal.
$repost = InvoiceLifecycle::issueInvoice($DB, TEST_COMPANY_ID, TEST_USER_ID, $inv);
assert_true(!$repost, 'reissue does not double-post');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE ref_type='invoice'")->fetchColumn(),
    'one invoice journal after reissue');

// Void: journal reversed (not deleted) and unlinked.
$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$svc->unpostInvoice($inv, 'Voided in test');
$after = $DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetch();
assert_true(empty($after['journal_id']), 'void unlinks the journal');
$orig = $DB->query("SELECT status, reversed_by_journal_id FROM journal_entries WHERE ref_type='invoice'")->fetch();
assert_eq('posted', $orig['status'], 'original journal still posted (immutable)');
assert_true((int)$orig['reversed_by_journal_id'] > 0, 'original journal reversed');
assert_eq(json_encode([]), json_encode(tb_snapshot($DB)), 'TB net zero after void (reversal cancels posting)');

test_done('invoice_lifecycle');
