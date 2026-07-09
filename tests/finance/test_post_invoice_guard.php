<?php
/**
 * DUE-DILIGENCE-2026-07 A2: PostingService::postInvoice must refuse to inject
 * revenue+VAT into the GL for a soft-deleted or cancelled invoice — the central
 * safety net behind the "Sync to GL" button (which posts any invoice whose
 * journal_id is NULL). Draft and the terminal write-off/refund statuses are
 * deliberately still postable here (the GL-rebuild tool reposts issued-then-
 * written-off invoices, and the issue flow reaches this method as 'sent'); the
 * sync endpoint layers the stricter issued-only gate on top.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$cust = make_customer($DB);

// (1) A cancelled invoice cannot be posted.
$invCancelled = make_invoice($DB, $cust, [[1, 100.00, 15, 'Cancelled']]);
$DB->prepare("UPDATE invoices SET status='cancelled' WHERE id=?")->execute([$invCancelled]);
$threw = false;
try { $svc->postInvoice($invCancelled); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'postInvoice rejects a cancelled invoice');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE ref_type='invoice' AND ref_id=$invCancelled")->fetchColumn(),
    'no journal is created for the cancelled invoice');

// (2) A soft-deleted invoice cannot be posted.
$invDeleted = make_invoice($DB, $cust, [[1, 100.00, 15, 'Deleted']]);
$DB->prepare("UPDATE invoices SET deleted_at=NOW() WHERE id=?")->execute([$invDeleted]);
$threw = false;
try { $svc->postInvoice($invDeleted); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'postInvoice rejects a soft-deleted invoice');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE ref_type='invoice' AND ref_id=$invDeleted")->fetchColumn(),
    'no journal is created for the deleted invoice');

// (3) A normal invoice still posts (the guard does not break the happy path).
$invOk = make_invoice($DB, $cust, [[1, 100.00, 15, 'Valid']]);
$svc->postInvoice($invOk);
$jid = (int)$DB->query("SELECT journal_id FROM invoices WHERE id=$invOk")->fetchColumn();
assert_true($jid > 0, 'a valid invoice still posts to the GL');
assert_balanced_journal($DB, $jid, '(valid invoice)');

test_done('post_invoice_guard');
