<?php
/**
 * FlowDrive self-heal from page loads must never hold a page up: the first
 * backfill runs in slices that resume where the previous one stopped, and the
 * daily sweep works through documents that share one updated_at (a bulk
 * update) instead of redoing the same first ones forever.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiSchema.php';
require_once FLOWWORK_ROOT . '/qi/lib/QiWriter.php';
require_once FLOWWORK_ROOT . '/includes/flowdrive/FlowDriveBackfill.php';

QiSchema::ensure($DB);
$C = TEST_COMPANY_ID;
$setting = function (string $key) use ($DB, $C): ?string {
    $st = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
    $st->execute([$C, $key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
};
$DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key LIKE 'flowdrive\\_%'")->execute([$C]);

$cust = make_customer($DB, 'Drive Client');
$ids = [];
for ($i = 1; $i <= 5; $i++) {
    $r = QiWriter::save($DB, $C, TEST_USER_ID, 'invoice', ['customer_id' => $cust, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+30 days')),
        'rows' => [['kind' => 'item', 'description' => "Job $i", 'qty' => 1, 'price' => 100 * $i, 'tax_code' => 'STD']]]);
    $ids[] = (int)$r['id'];
}
$docs = (int)$DB->query("SELECT (SELECT COUNT(*) FROM invoices WHERE company_id = $C AND deleted_at IS NULL)
                              + (SELECT COUNT(*) FROM quotes WHERE company_id = $C)
                              + (SELECT COUNT(*) FROM credit_notes WHERE company_id = $C)
                              + (SELECT COUNT(*) FROM crm_compliance_docs WHERE company_id = $C AND file_path IS NOT NULL AND file_path <> '')
                              + (SELECT COUNT(*) FROM receipt_file rf JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                                  WHERE rf.company_id = $C AND rf.drive_node_id IS NULL AND b.status IN ('posted','paid'))")->fetchColumn();

// ------------------------------------------------ first backfill, in slices
// A deadline that has already passed still publishes one document per call.
$calls = 0;
do {
    $res = FlowDriveBackfill::runForCompany($DB, $C, null, microtime(true) - 1);
    $calls++;
    assert_eq(1, $res['ok'] + $res['fail'], "slice $calls publishes exactly one document");
    if (!$res['complete']) {
        assert_true($setting(FlowDriveBackfill::DONE_FLAG) !== '1', 'not marked done while slices remain');
        assert_true($setting(FlowDriveBackfill::CURSOR_FLAG) !== '', 'progress remembered between slices');
    }
} while (!$res['complete'] && $calls <= $docs + 2);
assert_eq($docs, $calls, 'every document published once, one slice each');
assert_eq('1', $setting(FlowDriveBackfill::DONE_FLAG), 'backfill marked done at the end');
assert_eq('', $setting(FlowDriveBackfill::CURSOR_FLAG), 'cursor cleared when done');
assert_true(!empty($res['since']), 'the backfill reports when it began');

// the page-load entry point: done now, and the sweep is not due yet
$DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$C, FlowDriveBackfill::SWEEP_FLAG, $res['since']]);
assert_eq('skipped', FlowDriveBackfill::autoRunOnce($DB, $C), 'nothing to do on the next page load');

// a fresh company state runs through autoRunOnce until done
$DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key LIKE 'flowdrive\\_%'")->execute([$C]);
$outcome = '';
for ($i = 0; $i < $docs + 2 && $outcome !== 'ran'; $i++) {
    $outcome = FlowDriveBackfill::autoRunOnce($DB, $C);
    assert_true(in_array($outcome, ['ran', 'partial'], true), "page load $i backfills (got $outcome)");
}
assert_eq('ran', $outcome, 'page loads finish the backfill');
assert_true($setting(FlowDriveBackfill::SWEEP_FLAG) !== null, 'daily sweep scheduled after the backfill');

// ------------------------------------------- sweep through a bulk update
$T = '2030-01-01 00:00:00'; // after anything else in the test data
$in = implode(',', $ids);
$DB->exec("UPDATE invoices SET updated_at = '$T' WHERE id IN ($in)");
$seen = [];
$after = '';
for ($i = 0; $i < 10; $i++) {
    $s = FlowDriveBackfill::sweepForCompany($DB, $C, $T, microtime(true) - 1, $after);
    if ($s['ok'] + $s['fail'] === 0) break;
    assert_eq(1, $s['ok'] + $s['fail'], 'one document per bounded sweep');
    assert_eq($T, $s['max_updated'], 'still inside the tied second');
    [$pos, $id] = array_map('intval', explode(':', (string)$s['cursor']));
    assert_eq(0, $pos, 'invoices are the first source');
    $seen[] = $id;
    assert_eq($i < 4, $s['exhausted'], 'more to do until the last one');
    $after = (string)$s['cursor'];
}
sort($ids);
assert_eq($ids, $seen, 'each tied document published once, in order — no endless redo');
$done = FlowDriveBackfill::sweepForCompany($DB, $C, $T, null, $after);
assert_eq(0, $done['ok'] + $done['fail'], 'nothing left after the cursor');
assert_true(!$done['exhausted'], 'sweep complete');
$full = FlowDriveBackfill::sweepForCompany($DB, $C, $T);
assert_eq(5, $full['ok'] + $full['fail'], 'without a cursor or deadline the whole window is swept');

$DB->exec("UPDATE invoices SET updated_at = NOW() WHERE id IN ($in)");
test_done('qi_flowdrive_bounded');
