<?php
/**
 * WS2: reposting never deletes GL history. Editing + reposting a document
 * produces (original journal) + (linked reversal) + (new journal); the trial
 * balance equals a fresh post; inventory movements net correctly; audit
 * events exist for post and reversal.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);

// Inventory item with stock on hand (receipt of 10 @ 40.00)
$DB->prepare("INSERT INTO inventory_items (company_id, sku, name, uom, is_stocked)
              VALUES (?, CONCAT('SKU-', UUID_SHORT()), 'Test stock item', 'ea', 1)")
   ->execute([TEST_COMPANY_ID]);
$itemId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO inventory_movements (company_id, item_id, movement_date, qty, unit_cost, ref_type)
              VALUES (?, ?, CURDATE(), 10, 40.00, 'opening')")
   ->execute([TEST_COMPANY_ID, $itemId]);

// Invoice with a stocked line: 2 x 100 @ 15%
$cust = make_customer($DB);
$inv = make_invoice($DB, $cust, [[2, 100.00, 15, 'Stocked goods']]);
$DB->prepare("UPDATE invoice_lines SET inventory_item_id = ? WHERE invoice_id = ?")->execute([$itemId, $inv]);

$svc->postInvoice($inv);
$jid1 = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
$tbAfterFirstPost = tb_snapshot($DB);
$movementsAfterFirst = (int)$DB->query(
    "SELECT COUNT(*) FROM inventory_movements WHERE company_id = " . TEST_COMPANY_ID .
    " AND ref_type = 'invoice' AND ref_id = $inv")->fetchColumn();
assert_eq(1, $movementsAfterFirst, 'one stock issue after first post');

// --- Repost (e.g. after an edit) --------------------------------------------
$svc->postInvoice($inv);
$jid2 = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
assert_true($jid2 > 0 && $jid2 !== $jid1, 'a NEW journal was created on repost');

// Original journal still exists, posted, and linked to its reversal.
$orig = $DB->query("SELECT status, reversed_by_journal_id FROM journal_entries WHERE id = $jid1")->fetch();
assert_true((bool)$orig, 'original journal NOT deleted');
assert_eq('posted', $orig['status'], 'original stays posted');
assert_true((int)$orig['reversed_by_journal_id'] > 0, 'original linked to a reversal');
$revId = (int)$orig['reversed_by_journal_id'];
$rev = $DB->query("SELECT reverses_journal_id FROM journal_entries WHERE id = $revId")->fetch();
assert_eq($jid1, $rev['reverses_journal_id'], 'reversal links back to original');
assert_balanced_journal($DB, $revId, '(reversal)');
assert_balanced_journal($DB, $jid2, '(replacement)');

// Trial balance is identical to a single clean post.
assert_eq(json_encode($tbAfterFirstPost), json_encode(tb_snapshot($DB)),
    'trial balance unchanged by repost (reversal + replacement net to zero)');

// Inventory: issue, contra, re-issue => net one issue; on-hand back to 8.
$net = (float)$DB->query(
    "SELECT COALESCE(SUM(qty),0) FROM inventory_movements
      WHERE company_id = " . TEST_COMPANY_ID . " AND item_id = $itemId")->fetchColumn();
assert_eq(8.0, $net, 'on-hand after repost = 10 - 2 (no double issue)');
$contras = (int)$DB->query(
    "SELECT COUNT(*) FROM inventory_movements
      WHERE company_id = " . TEST_COMPANY_ID . " AND reversal_of_movement_id IS NOT NULL")->fetchColumn();
assert_eq(1, $contras, 'exactly one contra movement for the superseded issue');

// Audit trail captured the posting lifecycle.
$actions = $DB->query("SELECT action, COUNT(*) c FROM audit_log GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_true(($actions['journal_posted'] ?? 0) >= 2, 'journal_posted events written');
assert_true(($actions['journal_reversed'] ?? 0) >= 1, 'journal_reversed event written');

// --- Repost into a locked period is blocked, atomically ----------------------
$DB->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active)
              VALUES (?, ?, 'lock all', 1, NOW(), 1)")->execute([TEST_COMPANY_ID, date('Y-m-d')]);
$blocked = false;
try {
    $svc->postInvoice($inv);
} catch (Exception $e) {
    $blocked = true;
}
assert_true($blocked, 'repost into locked period throws');
$jid3 = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
assert_eq($jid2, $jid3, 'blocked repost leaves the existing journal untouched');
$rev2 = $DB->query("SELECT reversed_by_journal_id FROM journal_entries WHERE id = $jid2")->fetch();
assert_true(empty($rev2['reversed_by_journal_id']), 'blocked repost did not reverse anything (atomic rollback)');

test_done('repost_immutability');
