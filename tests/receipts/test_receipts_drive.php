<?php
/**
 * Receipts in FlowWork Drive: the slip behind a posted bill is filed under
 * Suppliers/{supplier}/Receipts as "{date} {number}.{ext}", byte for byte and
 * once (posting it again or the catch-up makes no second copy), tagged with
 * its receipt. Drafts are never filed. A name another receipt holds becomes
 * "(receipt N)". With filing switched off nothing is filed; switched back on,
 * the page-load catch-up files what was missed. A slip gone from the server
 * is marked and not tried again.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_helpers.php';
require_once FLOWWORK_ROOT . '/includes/flowdrive/FlowDriveBackfill.php';

$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
FinSchema::ensure($DB);
rc_fresh_schema($DB);
assert_true(FlowDriveRepo::available($DB), 'the FlowDrive tables are in the test database');
RcKit::saveSettings($DB, $C, ['require_po_over_amount' => 0, 'require_vat_number' => 0, 'block_expired_compliance' => 0, 'publish_to_drive' => 1]);

$tag = strtoupper(bin2hex(random_bytes(3)));
$DB->prepare("INSERT INTO crm_accounts (company_id, type, name, vat_no, status, created_by, created_at) VALUES (?, 'supplier', ?, NULL, 'active', 1, NOW())")
   ->execute([$C, "Drive Hardware $tag"]);
$sup = (int)$DB->lastInsertId();
register_shutdown_function(fn() => $DB->exec("DELETE FROM crm_accounts WHERE id = $sup"));

$settings = function (string $key) use ($DB, $C): ?string {
    $st = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
    $st->execute([$C, $key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
};
$setSetting = function (string $key, ?string $value) use ($DB, $C): void {
    if ($value === null) {
        $DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key = ?")->execute([$C, $key]);
        return;
    }
    $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([$C, $key, $value]);
};
$flags = [FlowDriveBackfill::DONE_FLAG, FlowDriveBackfill::SWEEP_FLAG, FlowDriveBackfill::RECEIPTS_FLAG, FlowDriveBackfill::RECEIPTS_CURSOR];
$before = array_combine($flags, array_map($settings, $flags));
register_shutdown_function(function () use ($before, $setSetting) { foreach ($before as $k => $v) $setSetting($k, $v); });

/** A receipt from Drive Hardware, read and ready to post. */
$slip = function (string $number) use ($DB, $C, $U, $sup, $tag): array {
    $id = rc_slip($DB, ["DRIVE HARDWARE $tag", $number]);
    rc_reading($DB, $id, ['vendor_name' => "Drive Hardware $tag", 'invoice_number' => $number, 'invoice_date' => '2026-10-14', 'total' => 115.00, 'tax' => 15.00, 'subtotal' => 100.00]);
    $form = RcBills::load($DB, $C, $U, 'admin', $id)['form'];
    return [$id, ['supplier_id' => $sup, 'number' => $number, 'issued' => '2026-10-14', 'currency' => 'ZAR', 'paid' => ['method' => '']] + $form];
};
$nodeOf = fn(int $id) => $DB->query("SELECT drive_node_id FROM receipt_file WHERE file_id = $id")->fetchColumn();
$node = fn(int $nodeId) => $DB->query("SELECT n.*, p.name AS folder, a.name AS account, a.meta_json AS account_meta, t.name AS top, t.parent_id AS top_parent, b.data
                                          FROM fd_nodes n JOIN fd_nodes p ON p.id = n.parent_id JOIN fd_nodes a ON a.id = p.parent_id JOIN fd_nodes t ON t.id = a.parent_id
                                          LEFT JOIN fd_blobs b ON b.id = n.blob_id WHERE n.id = $nodeId")->fetch(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------- a draft is not filed
[$id, $form] = $slip("DRV-$tag");
RcBills::save($DB, $C, $U, 'admin', $id, $form);
assert_true($nodeOf($id) === null && RcDrive::publish($DB, $C, $id) === null, 'a draft bill\'s slip stays out of the drive');
assert_true(!in_array($id, RcDrive::pending($DB, $C), true), 'and is not waiting to be filed');

// ---------------------------------------------------------------- posting files it
$post = RcBills::post($DB, $C, $U, 'admin', $id, $form);
$nid = (int)$nodeOf($id);
assert_true($post['drive'] && $nid > 0, 'posting files the slip in the drive');
$n = $node($nid);
$path = RcFiles::path($C, (string)$DB->query("SELECT path FROM receipt_file WHERE file_id = $id")->fetchColumn());
assert_eq(["2026-10-14 DRV-$tag", 'pdf', 'Receipts', "Drive Hardware $tag", 'Suppliers', null], [$n['name'], $n['ext'], $n['folder'], $n['account'], $n['top'], $n['top_parent']],
          'Suppliers / Drive Hardware / Receipts / "2026-10-14 DRV-…".pdf');
assert_eq($sup, (int)json_decode($n['account_meta'], true)['fw_account_id'], 'in the supplier\'s own folder (by id, rename-proof)');
assert_true($n['data'] === file_get_contents($path), 'the slip byte for byte');
assert_eq($id, (int)json_decode($n['meta_json'], true)['fw_receipt_file_id'], 'tagged with its receipt');
assert_true(RcBills::shapeRow($DB, RcBills::row($DB, $C, $id))['in_drive'], 'the review page knows it is filed');

// ---------------------------------------------------------------- once
assert_eq($nid, RcDrive::publish($DB, $C, $id), 'filing it again finds the same drive file');
$tagged = fn(int $rid) => (int)$DB->query("SELECT COUNT(*) FROM fd_nodes WHERE type = 'file' AND deleted_at IS NULL AND JSON_EXTRACT(meta_json, '$.fw_receipt_file_id') = $rid")->fetchColumn();
assert_eq(1, $tagged($id), 'one copy in the drive');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM fd_node_versions WHERE node_id = $nid")->fetchColumn(), 'and no new version of the same bytes');

// ---------------------------------------------------------------- a name another receipt holds
$other = FlowDriveSync::fileReceipt($DB, $C, $sup, 999000000 + random_int(1, 99999), "2026-10-14 DRV-$tag.pdf", "%PDF-1.4 other slip $tag", 'application/pdf');
assert_true($other !== null && $other !== $nid, 'a second receipt with the same date and number is filed beside it');
assert_true(str_ends_with($node($other)['name'], ')') && str_contains($node($other)['name'], '(receipt '), 'as "… (receipt N)"');
assert_true($node($nid)['data'] === file_get_contents($path), 'the first one is untouched');

// ---------------------------------------------------------------- switched off, then the catch-up
RcKit::saveSettings($DB, $C, ['publish_to_drive' => 0]);
[$id2, $form2] = $slip("DRV2-$tag");
$post2 = RcBills::post($DB, $C, $U, 'admin', $id2, $form2);
assert_true(!$post2['drive'] && $nodeOf($id2) === null, 'with filing off, a posted slip stays out of the drive');
assert_eq([], RcDrive::pending($DB, $C), 'and nothing waits while it is off');
RcKit::saveSettings($DB, $C, ['publish_to_drive' => 1]);
assert_true(in_array($id2, RcDrive::pending($DB, $C), true), 'switched on: the missed one waits to be filed');
$setSetting(FlowDriveBackfill::DONE_FLAG, '1');
$setSetting(FlowDriveBackfill::SWEEP_FLAG, $DB->query("SELECT NOW()")->fetchColumn());
$setSetting(FlowDriveBackfill::RECEIPTS_FLAG, null);
$setSetting(FlowDriveBackfill::RECEIPTS_CURSOR, null);
FlowDriveBackfill::autoRunOnce($DB, $C);
assert_true((int)$nodeOf($id2) > 0, 'the next page load files it');
assert_true($settings(FlowDriveBackfill::RECEIPTS_FLAG) !== null && $settings(FlowDriveBackfill::RECEIPTS_CURSOR) === '', 'and the catch-up rests for an hour');
$DB->exec("UPDATE receipt_file SET drive_node_id = NULL WHERE file_id = $id2");
assert_eq('skipped', FlowDriveBackfill::autoRunOnce($DB, $C), 'within the hour page loads do not look again');
$res = FlowDriveBackfill::publishReceipts($DB, $C);
assert_true($res['complete'] && $res['ok'] >= 1 && (int)$nodeOf($id2) > 0, 'the full backfill files what is missing too');
assert_eq(1, $tagged($id2), 'still one copy');
$DB->exec("UPDATE receipt_file SET drive_node_id = NULL WHERE file_id IN ($id, $id2)");
$n1 = 0;
$part = FlowDriveBackfill::publishReceipts($DB, $C, 0, function () use (&$n1): bool { return $n1++ > 0; });
assert_true(!$part['complete'] && $part['ok'] === 1 && $part['cursor'] === $id, 'a pass out of time stops after what it filed');
$rest = FlowDriveBackfill::publishReceipts($DB, $C, $part['cursor']);
assert_true($rest['complete'] && (int)$nodeOf($id2) > 0 && (int)$nodeOf($id) === $nid, 'and the next one carries on from there');

// ---------------------------------------------------------------- a slip gone from the server
$p2 = RcFiles::path($C, (string)$DB->query("SELECT path FROM receipt_file WHERE file_id = $id2")->fetchColumn());
$DB->exec("UPDATE receipt_file SET drive_node_id = NULL WHERE file_id = $id2");
rename($p2, $p2 . '.away');
assert_true(RcDrive::publish($DB, $C, $id2) === null && (int)$nodeOf($id2) === 0, 'a slip gone from the server is marked, not filed');
assert_true(!in_array($id2, RcDrive::pending($DB, $C), true), 'and not tried on every page load');
rename($p2 . '.away', $p2);

test_done('receipts_drive');
