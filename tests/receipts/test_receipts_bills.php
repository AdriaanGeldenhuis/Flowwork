<?php
/**
 * Receipts: a slip becomes a supplier bill through Finance's own engine.
 *   - slips are stored by content, outside the web, once (SHA-1);
 *   - VAT-inclusive prices become net lines exact to the cent, as Finance posts them;
 *   - the supplier is found by VAT number, by what was learned, by name;
 *   - supplier compliance (required types, expiry, renewals, no expiry date);
 *   - the checks: supplier, VAT number, compliance (admin override, on record),
 *     the slip's total, closed periods, duplicates, purchase orders;
 *   - save (draft "review" bill, slip attached in Finance once, the form kept),
 *     post (a blocked post keeps the draft), paid on the spot (payment posted),
 *     posted bills cannot be changed, deleted or set aside;
 *   - delete a draft, set aside / bring back, members see and delete only their own;
 *   - learning; readings the old engine invented are cleared; the readers never invent.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_helpers.php';

$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
FinSchema::ensure($DB);
rc_fresh_schema($DB);
RcKit::saveSettings($DB, $C, ['require_po_over_amount' => 0, 'require_vat_number' => 1, 'block_expired_compliance' => 1, 'default_expense_account' => '',
                              'auto_split_lines' => 1, 'ocr_provider' => 'tesseract', 'default_due_days' => 30, 'variance_tolerance_pct' => 5]);
$DB->exec("DELETE FROM company_settings WHERE company_id = $C AND setting_key = 'receipts_ai_map'");
$requiredBefore = $DB->query("SELECT id, required FROM crm_compliance_types WHERE company_id = $C")->fetchAll(PDO::FETCH_KEY_PAIR);
$DB->exec("UPDATE crm_compliance_types SET required = 0 WHERE company_id = $C");
$made = ['accounts' => [], 'types' => []];
register_shutdown_function(function () use ($DB, $C, $requiredBefore, &$made) {
    if ($made['types']) $DB->exec("DELETE FROM crm_compliance_types WHERE id IN (" . implode(',', $made['types']) . ")");
    if ($made['accounts']) {
        $DB->exec("DELETE FROM crm_compliance_docs WHERE account_id IN (" . implode(',', $made['accounts']) . ")");
        $DB->exec("DELETE FROM crm_accounts WHERE id IN (" . implode(',', $made['accounts']) . ")");
    }
    $restore = $DB->prepare("UPDATE crm_compliance_types SET required = ? WHERE id = ? AND company_id = ?");
    foreach ($requiredBefore as $id => $req) $restore->execute([$req, $id, $C]);
});
$supplier = function (string $name, ?string $vat = null) use ($DB, $C, &$made): int {
    $DB->prepare("INSERT INTO crm_accounts (company_id, type, name, vat_no, status, created_by, created_at) VALUES (?, 'supplier', ?, ?, 'active', 1, NOW())")
       ->execute([$C, $name, $vat]);
    return $made['accounts'][] = (int)$DB->lastInsertId();
};
$tag = strtoupper(bin2hex(random_bytes(2)));
$vat = fn() => '4' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
[$vatLacking, $vatComplete, $vatSlip, $vatPdf] = [$vat(), $vat(), $vat(), $vat()];
$expense = (int)$DB->query("SELECT a.account_id FROM gl_accounts a WHERE a.company_id = $C AND a.account_type = 'expense' AND a.is_active = 1 AND COALESCE(a.is_control,0) = 0
                              AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.parent_id = a.account_id) ORDER BY a.account_code DESC LIMIT 1")->fetchColumn();
assert_true($expense > 0, 'an expense account to book to');

// ---------------------------------------------------------------- storing
$id = rc_slip($DB, ['STORE TEST', 'TOTAL 10.00'], $U, 'cement for the Smith job');
$row = RcBills::row($DB, $C, $id);
assert_true(str_starts_with($row['path'], 'storage/receipts/' . $C . '/'), 'stored under storage/receipts/<company>/ (closed to the web)');
assert_true((bool)RcFiles::path($C, $row['path']), 'the file is on disk');
assert_eq('cement for the Smith job', $row['note'], 'the note is kept');
assert_eq('reading', $row['state'], 'a new slip is being read');
$copy = tempnam(sys_get_temp_dir(), 'rcdup');
copy(RcFiles::path($C, $row['path']), $copy);
$dup = RcFiles::store($DB, $C, $U, $copy, 'again.pdf', 15 * 1048576);
assert_true($dup['duplicate'] && $dup['file_id'] === $id, 'the same file sent in again is caught, not stored twice');
assert_true(RcFiles::path($C, '../config.php') === null && RcFiles::path($C, 'storage/receipts/999/x.pdf') === null && RcFiles::path(2, $row['path']) === null,
            'paths outside the company\'s receipt folders are refused');
$bad = tempnam(sys_get_temp_dir(), 'rcbad');
file_put_contents($bad, "<?php echo 'hi';");
try { RcFiles::store($DB, $C, $U, $bad, 'evil.jpg', 15 * 1048576); fail('a PHP file named .jpg was stored'); } catch (RcProblem $e) { assert_eq(415, $e->getCode(), 'a file is judged by its content'); }

// ---------------------------------------------------------------- the bill maths
$finNet = fn(array $l) => (int)round(((float)$l['qty'] * (float)$l['unit_price'] - (float)$l['discount']) * 100);
$worst = 0;
foreach ([[1, '115.00'], [3, '89.90'], [1, '99.99'], [1, '219.99'], [7, '13.33'], [2.5, '41.17'], [1, '0.01'], [12, '1.99'], [1, '10 000,00']] as [$q, $p]) {
    $r = RcBills::financeLines([['description' => 'x', 'qty' => $q, 'price' => $p, 'rate' => 15]], true, null);
    $l = $r['lines'][0];
    assert_eq($l['net_c'], $finNet($l), "incl. VAT $q × $p: Finance's net (qty × unit − discount) is the net worked out");
    assert_true((float)$l['discount'] >= 0 && (float)$l['discount'] < 0.05 * $q + 0.05, "incl. VAT $q × $p: at most a few cents of discount");
    $gross = (int)round($q * RcKit::cents($p));
    $worst = max($worst, abs($r['total'] - $gross));
}
assert_true($worst <= 1, 'VAT-inclusive lines land within a cent of the printed amount (VAT rounded per line)');
$r = RcBills::financeLines([['description' => 'x', 'qty' => 1, 'price' => '115.00', 'rate' => 15]], true, null);
assert_eq([10000, 1500, 11500], [$r['net'], $r['vat'], $r['total']], 'R115 incl. VAT is R100 + R15');
$r = RcBills::financeLines([['description' => 'x', 'qty' => 2, 'price' => '50', 'rate' => 15], ['description' => '', 'qty' => 1, 'price' => '']], false, null);
assert_eq([1, 10000, 1500, '0.00'], [count($r['lines']), $r['net'], $r['vat'], $r['lines'][0]['discount']], 'excl. VAT: prices as typed; blank lines dropped');
$r = RcBills::financeLines([['description' => 'x', 'qty' => '2,5', 'price' => '20,00', 'rate' => '15'], ['description' => 'y', 'qty' => '', 'price' => '11.50', 'rate' => 15]], true, null);
assert_eq([5000, 1150], [$r['lines'][0]['net_c'] + $r['lines'][0]['vat_c'], $r['lines'][1]['net_c'] + $r['lines'][1]['vat_c']], 'a quantity typed "2,5" is 2.5; an empty quantity is 1');
try { RcBills::financeLines([['description' => 'x', 'qty' => 'two', 'price' => '5', 'rate' => 15]], true, null); fail('a quantity that is not a number was taken'); } catch (RcProblem $e) { assert_true(true, 'a quantity that is not a number is refused, not booked as 1'); }
try { RcBills::financeLines([['description' => 'x', 'qty' => 1, 'price' => '-5', 'rate' => 15]], true, null); fail('a negative price was taken'); } catch (RcProblem $e) { assert_true(true, 'negative prices are refused (refunds are credit notes)'); }

// ---------------------------------------------------------------- finding the supplier
$byVat = $supplier("Vat Match $tag", '4' . substr((string)random_int(100000000, 999999999), 0, 9));
$vatNo = (string)$DB->query("SELECT vat_no FROM crm_accounts WHERE id = $byVat")->fetchColumn();
$s = RcBills::suggestSupplier($DB, $C, 'SOMETHING ELSE ENTIRELY', $vatNo);
assert_eq([$byVat, 'vat'], [$s['id'], $s['source']], 'matched by the VAT number on the slip first');
$named = $supplier("Engen Fourways $tag");
RcLearn::rememberSupplier($DB, $C, "ENGEN STATION $tag", $named);
$s = RcBills::suggestSupplier($DB, $C, "engen station $tag");
assert_eq([$named, 'learned'], [$s['id'], $s['source']], 'a printed name chosen before is remembered');
$s = RcBills::suggestSupplier($DB, $C, "Engen Fourways $tag");
assert_eq([$named, 'name'], [$s['id'], $s['source']], 'an exact name');
$s = RcBills::suggestSupplier($DB, $C, "ENGEN FOURWAYS $tag (Pty) Ltd");
assert_eq($named, $s['id'] ?? null, 'a close name (Pty Ltd, capitals)');
assert_true($s['confidence'] < 0.9, 'a close name is less certain');
assert_true(RcBills::suggestSupplier($DB, $C, 'Zzqx Nothing Like It') === null, 'no guess when nothing is close');

// ---------------------------------------------------------------- compliance (required types only; expiry rules)
$addType = function (string $name, int $required) use ($DB, $C, $tag, &$made): int {
    $DB->prepare("INSERT INTO crm_compliance_types (company_id, code, name, default_expiry_months, required) VALUES (?, ?, ?, 12, ?)")
       ->execute([$C, substr("T$tag" . strtoupper(substr($name, 0, 3)), 0, 32), "$name $tag", $required]);
    return $made['types'][] = (int)$DB->lastInsertId();
};
$tax = $addType('Tax Clearance', 1); $ins = $addType('Liability Insurance', 1); $coida = $addType('COIDA', 1); $bee = $addType('BEE Certificate', 0);
$doc = fn(int $acc, int $type, ?string $exp) => $DB->prepare("INSERT INTO crm_compliance_docs (company_id, account_id, type_id, reference_no, expiry_date, status, created_at) VALUES (?, ?, ?, 'REF', ?, 'valid', NOW())")->execute([$C, $acc, $type, $exp]);
$future = date('Y-m-d', strtotime('+1 year')); $past = date('Y-m-d', strtotime('-10 days'));
$lacking = $supplier("Lacking $tag", $vatLacking);
$doc($lacking, $tax, $future); $doc($lacking, $coida, $past);
$lacking2 = $supplier("Lacking too $tag", $vat());
$doc($lacking2, $tax, $future);
$complete = $supplier("Complete $tag", $vatComplete);
$doc($complete, $tax, null); $doc($complete, $ins, $past); $doc($complete, $ins, $future); $doc($complete, $coida, date('Y-m-d')); $doc($complete, $bee, $past);
assert_eq(['ok' => false, 'missing' => ["COIDA $tag (expired)", "Liability Insurance $tag"]], RcBills::compliance($DB, $C, $lacking), 'a missing and an expired required type; optional ones ignored');
assert_eq(['ok' => true, 'missing' => []], RcBills::compliance($DB, $C, $complete), 'no expiry date, a renewal and an expiry today all count as current');

// ---------------------------------------------------------------- checks
$codes = fn(array $res, string $level = '') => array_values(array_map(fn($c) => $c['code'], array_filter($res['checks'], fn($c) => $level === '' || $c['level'] === $level)));
$id = rc_slip($DB, ['CHECKS']);
rc_reading($DB, $id, ['vendor_name' => "Lacking $tag", 'vendor_vat' => $vatLacking, 'invoice_number' => "CHK-$tag", 'invoice_date' => '2026-10-14', 'total' => 115.00, 'tax' => 15.00, 'subtotal' => 100.00]);
$load = RcBills::load($DB, $C, $U, 'admin', $id);
$form = $load['form'];
assert_eq($lacking, $form['supplier_id'], 'prefill: the supplier from the VAT number');
assert_true($form['incl'] === true && count($form['lines']) === 1 && (float)$form['lines'][0]['price'] === 115.0 && (float)$form['lines'][0]['rate'] === 15.0,
            'prefill: one line for the slip\'s total, VAT included, at 15%');
assert_true((int)$form['lines'][0]['account_id'] > 0, 'prefill: an account (the default)');
assert_eq('2026-11-13', $form['due'], 'prefill: due after the default 30 days');
$row = RcBills::row($DB, $C, $id);
$res = RcBills::checks($DB, $C, $U, 'admin', $row, $form);
assert_eq(['compliance'], $codes($res, 'block'), 'held only by the compliance documents');
assert_eq([11500, 1500], [$res['totals']['total'], $res['totals']['vat']], 'totals in cents');
assert_true(!empty(array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'compliance'))[0]['can_override']), 'an admin may override it');
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['supplier_id' => 0] + $form);
assert_true(in_array('supplier', $codes($res, 'block'), true), 'no supplier: held');
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['slip_total' => '120.00'] + $form);
$st = array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'slip_total'))[0] ?? null;
assert_true($st && $st['level'] === 'warn' && ($st['fix']['op'] ?? '') === 'match_total', 'the bill against the slip\'s total, with a fix for one line');
$noVat = $supplier("No Vat $tag");
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['supplier_id' => $noVat, 'vendor_vat' => ''] + $form);
$vc = array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'vat_number'))[0] ?? null;
assert_true($vc && $vc['level'] === 'block' && $vc['fix']['op'] === 'no_vat', 'VAT claimed without a VAT number: held, with "don\'t claim VAT"');
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['supplier_id' => $noVat, 'vendor_vat' => '', 'lines' => [['rate' => 0] + $form['lines'][0]]] + $form);
assert_true(!in_array('vat_number', $codes($res), true), 'no VAT claimed: nothing to hold');
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['supplier_id' => $noVat, 'vendor_vat' => $vatSlip] + $form);
assert_true(in_array('vat_save', $codes($res, 'info'), true) && !in_array('vat_number', $codes($res), true), 'the slip shows a VAT number the supplier lacks: offered to save it, VAT allowed');
$DB->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active) VALUES (?, '2026-09-30', 'test', 1, NOW(), 1)")->execute([$C]);
$res = RcBills::checks($DB, $C, $U, 'admin', $row, ['issued' => '2026-09-15'] + $form);
assert_true(in_array('locked', $codes($res, 'block'), true), 'a date in a closed period is held');
$DB->exec("DELETE FROM gl_period_locks WHERE company_id = $C AND lock_reason = 'test'");
RcKit::saveSettings($DB, $C, ['require_po_over_amount' => 100]);
$hasPos = (int)$DB->query("SELECT COUNT(*) FROM purchase_orders WHERE company_id = $C")->fetchColumn() > 0;
$res = RcBills::checks($DB, $C, $U, 'admin', $row, $form);
$po = array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'po'))[0] ?? null;
assert_true($po && $po['level'] === ($hasPos ? 'block' : 'warn'), 'a purchase order from R100: held once purchase orders are in use, else a warning');
RcKit::saveSettings($DB, $C, ['require_po_over_amount' => 0]);

// overrides: admins only, with a reason, on record
try { RcBills::override($DB, $C, $U, 'bookkeeper', $id, 'compliance', 'because', $form); fail('a bookkeeper overrode a check'); } catch (RcForbidden $e) { assert_true(true, 'bookkeepers cannot override'); }
try { RcBills::override($DB, $C, $U, 'admin', $id, 'compliance', 'ok', $form); fail('an override without a reason'); } catch (RcProblem $e) { assert_true(true, 'an override needs a reason'); }
try { RcBills::override($DB, $C, $U, 'admin', $id, 'duplicate', 'yes really', $form); fail('a duplicate was overridden'); } catch (RcProblem $e) { assert_true(true, 'not every check can be overridden'); }
try { RcBills::override($DB, $C, $U, 'admin', $id, 'compliance', 'nothing to see', ['supplier_id' => $complete] + $form); fail('overrode a check that holds nothing'); }
catch (RcProblem $e) { assert_eq(409, $e->getCode(), 'nothing to override when the check does not hold the bill'); }
$covers = new ReflectionMethod(RcBills::class, 'covers');
$covers->setAccessible(true);
assert_eq([true, false, true, false, false, false], [$covers->invoke(null, 'compliance', 's5', 's5'), $covers->invoke(null, 'compliance', 's5', 's6'),
          $covers->invoke(null, 'po', 's5:1000', 's5:900'), $covers->invoke(null, 'po', 's5:1000', 's5:1100'), $covers->invoke(null, 'po', 's5:1000', 's6:900'), $covers->invoke(null, 'vat_number', '', '')],
          'an override holds for its supplier, and a purchase-order override up to its amount');

// ---------------------------------------------------------------- save, post blocked, override, post paid
$saved = RcBills::save($DB, $C, $U, 'admin', $id, $form);
$billId = $saved['bill_id'];
$b = $DB->query("SELECT * FROM ap_bills WHERE id = $billId")->fetch(PDO::FETCH_ASSOC);
assert_eq(['review', $id, $lacking, "CHK-$tag", '100.00', '15.00', '115.00'], [$b['status'], (int)$b['file_id'], (int)$b['supplier_id'], $b['vendor_invoice_number'], $b['subtotal'], $b['tax'], $b['total']],
          'a draft bill (Finance status "review") with exact totals, linked to the slip');
assert_true((int)$b['ocr_id'] > 0, 'the reading is linked');
assert_eq($billId, (int)$DB->query("SELECT bill_id FROM receipt_file WHERE file_id = $id")->fetchColumn(), 'the receipt points at its bill');
$att = fn() => (int)$DB->query("SELECT COUNT(*) FROM fin_attachments WHERE entity = 'bill' AND entity_id = $billId AND deleted_at IS NULL")->fetchColumn();
assert_eq(1, $att(), 'the slip is attached to the bill in Finance');
RcBills::save($DB, $C, $U, 'admin', $id, $form);
assert_eq(1, $att(), 'and only once');
$load = RcBills::load($DB, $C, $U, 'admin', $id);
assert_true($load['form']['incl'] === true && (float)$load['form']['lines'][0]['price'] === 115.0 && $load['receipt']['state'] === 'draft', 'the form comes back as typed (VAT-inclusive)');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM receipt_policy_log WHERE file_id = $id AND code = 'compliance' AND severity = 'error' AND override_by IS NULL")->fetchColumn(), 'the hold is on the check log');
try { RcBills::save($DB, $C, $U, 'member', $id, $form); fail('a member saved a bill'); } catch (RcForbidden $e) { assert_true(true, 'members cannot make bills'); }
try {
    RcBills::post($DB, $C, $U, 'admin', $id, $form);
    fail('posted past a hold');
} catch (RcBlocked $e) {
    assert_eq(['compliance'], array_values(array_map(fn($c) => $c['code'], array_filter($e->checks, fn($c) => $c['level'] === 'block'))), 'a held bill is not posted; the hold is named');
}
assert_eq('review', $DB->query("SELECT status FROM ap_bills WHERE id = $billId")->fetchColumn(), 'the draft is kept');
try { FinPayables::postBill($DB, $C, $U, 'admin', $billId); fail('Finance posted a held receipt bill'); }
catch (FinUserError $e) { assert_eq(409, $e->getCode(), 'Finance does not post a bill from a receipt — Receipts does, with its checks'); }
assert_true(FinPayables::bill($DB, $C, $billId)['bill']['post_in_receipts'] && !FinPayables::bill($DB, $C, $billId)['bill']['postable'], 'Finance shows "post in Receipts" instead of Post');

RcBills::override($DB, $C, $U, 'admin', $id, 'compliance', 'Renewal is on its way', $form);
$res = RcBills::checks($DB, $C, $U, 'admin', RcBills::row($DB, $C, $id), $form);
$cc = array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'compliance'))[0];
assert_true($cc['level'] === 'warn' && $cc['overridden']['reason'] === 'Renewal is on its way' && !$res['blocked'] && !isset($cc['scope']), 'overridden: a warning with who and why');
$res = RcBills::checks($DB, $C, $U, 'bookkeeper', RcBills::row($DB, $C, $id), ['supplier_id' => $lacking2, 'vendor_vat' => ''] + $form);
assert_true(in_array('compliance', $codes($res, 'block'), true), 'the override was for that supplier: another supplier lacking documents is held again');
try { RcBills::post($DB, $C, $U, 'bookkeeper', $id, ['supplier_id' => $lacking2, 'vendor_vat' => ''] + $form); fail('posted to another supplier on an old override'); }
catch (RcBlocked $e) { assert_true(true, 'and is not posted'); }
$form['paid'] = ['method' => 'card', 'date' => '2026-10-14', 'bank_account_id' => null];
$post = RcBills::post($DB, $C, $U, 'admin', $id, $form);
$b = $DB->query("SELECT status, journal_id, total FROM ap_bills WHERE id = $billId")->fetch(PDO::FETCH_ASSOC);
assert_eq('paid', $b['status'], 'posted and paid on the spot');
assert_balanced_journal($DB, (int)$b['journal_id'], 'bill');
$pay = $DB->query("SELECT p.id, p.amount, p.method, p.idempotency_key, p.journal_id FROM ap_payments p JOIN ap_payment_allocations a ON a.ap_payment_id = p.id WHERE a.bill_id = $billId")->fetch(PDO::FETCH_ASSOC);
assert_eq(['115.00', 'card', "rcpt-$id-$billId"], [$pay['amount'], $pay['method'], $pay['idempotency_key']], 'the payment is recorded for the full amount, once');
assert_balanced_journal($DB, (int)$pay['journal_id'], 'payment');
assert_eq((int)$pay['id'], $post['payment_id'], 'the payment is reported');
assert_eq('paid', RcBills::row($DB, $C, $id)['state'], 'the receipt is paid');
try { RcBills::save($DB, $C, $U, 'admin', $id, $form); fail('a posted bill was changed'); } catch (RcProblem $e) { assert_eq(409, $e->getCode(), 'a posted bill cannot change'); }
try { RcReceipts::delete($DB, $C, $U, 'admin', [$id]); fail('the source document of a posted bill was deleted'); } catch (RcProblem $e) { assert_eq(409, $e->getCode(), 'its slip is kept'); }
try { RcReceipts::aside($DB, $C, $U, 'admin', [$id], 'personal'); fail('a posted receipt was set aside'); } catch (RcProblem $e) { assert_eq(409, $e->getCode(), 'and cannot be set aside'); }
$loose = rc_slip($DB, ['LOOSE ' . $tag]);
assert_eq(1, RcReceipts::delete($DB, $C, $U, 'admin', [$id, $loose], $kept), 'a batch goes ahead past a receipt in the books');
assert_eq([$id], array_column($kept, 'id'), 'which is kept, and named');

// learning from the saved bill
$id2 = rc_slip($DB, ['LEARN']);
rc_reading($DB, $id2, ['vendor_name' => "Complete $tag", 'vendor_vat' => $vatComplete, 'invoice_number' => "L-$tag", 'invoice_date' => '2026-10-15', 'total' => 230.00, 'tax' => 30.00, 'subtotal' => 200.00]);
$f2 = RcBills::load($DB, $C, $U, 'admin', $id2)['form'];
$f2['lines'][0]['description'] = 'Diesel for the bakkie';
$f2['lines'][0]['account_id'] = $expense;
RcBills::save($DB, $C, $U, 'admin', $id2, $f2);
assert_eq([$expense, 'word'], RcLearn::accountFor($DB, $C, RcLearn::map($DB, $C), null, 'DIESEL 50L'), 'a word remembers its account');
assert_eq([$expense, 'supplier'], RcLearn::accountFor($DB, $C, RcLearn::map($DB, $C), $complete, 'something new'), 'a supplier remembers its account');

// duplicates
$id3 = rc_slip($DB, ['DUP']);
rc_reading($DB, $id3, ['vendor_name' => "Complete $tag", 'vendor_vat' => $vatComplete, 'invoice_number' => "L-$tag", 'invoice_date' => '2026-10-16', 'total' => 50.00]);
$f3 = RcBills::load($DB, $C, $U, 'admin', $id3)['form'];
$res = RcBills::checks($DB, $C, $U, 'admin', RcBills::row($DB, $C, $id3), $f3);
$d = array_values(array_filter($res['checks'], fn($c) => $c['code'] === 'duplicate'))[0] ?? null;
assert_true($d && $d['level'] === 'block' && $d['link']['href'] === '/receipts/review.php?id=' . $id2 && $d['fix']['op'] === 'aside_duplicate', 'the same supplier and number: held, with a link to the other receipt and "set aside"');
try { RcBills::save($DB, $C, $U, 'admin', $id3, $f3); fail('a duplicate bill was saved'); } catch (RcProblem $e) { assert_true(str_contains($e->getMessage(), 'already captured'), 'Finance refuses the duplicate too'); }

// delete a draft; set aside and bring back
RcBills::deleteDraft($DB, $C, $U, 'admin', $id2);
assert_eq('review', RcBills::row($DB, $C, $id2)['state'], 'deleting the draft bill leaves the receipt to review');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM ap_bills WHERE file_id = $id2")->fetchColumn(), 'the bill is gone from Finance');
assert_eq(1, RcReceipts::aside($DB, $C, $U, 'admin', [$id3], 'duplicate', 'same as L-' . $tag), 'set aside');
assert_eq('aside', RcBills::row($DB, $C, $id3)['state'], 'state: set aside');
$list = RcReceipts::list($DB, $C, $U, 'admin', ['view' => 'aside']);
assert_true(in_array($id3, array_column($list['rows'], 'id'), true) && $list['counts']['aside'] >= 1, 'listed under Set aside');
try { RcBills::save($DB, $C, $U, 'admin', $id3, $f3); fail('a set-aside receipt was billed'); } catch (RcProblem $e) { assert_eq(409, $e->getCode(), 'a set-aside receipt is not billed'); }
assert_eq(1, RcReceipts::restore($DB, $C, $U, 'admin', [$id3]), 'brought back');
assert_eq('review', RcBills::row($DB, $C, $id3)['state'], 'to review again');

// members: their own receipts only
$member = 3;
$mine = rc_slip($DB, ['MEMBER SLIP'], $member, 'milk for the office');
try { RcBills::need($DB, $C, $member, 'member', $id3); fail('a member opened someone else\'s receipt'); } catch (RcProblem $e) { assert_eq(404, $e->getCode(), 'a member cannot open others\' receipts'); }
assert_eq($mine, RcBills::need($DB, $C, $member, 'member', $mine)['file_id'], 'but can open their own');
$ml = RcReceipts::list($DB, $C, $member, 'member', ['view' => 'all']);
assert_eq([$mine], array_values(array_unique(array_column($ml['rows'], 'id'))), 'a member\'s list holds only their receipts');
try { RcReceipts::delete($DB, $C, $member, 'member', [$id3]); fail('a member deleted someone else\'s receipt'); } catch (RcProblem $e) { assert_eq(404, $e->getCode(), 'nor delete them'); }
$mrow = RcBills::row($DB, $C, $mine);
assert_eq(1, RcReceipts::delete($DB, $C, $member, 'member', [$mine]), 'a member deletes their own receipt before a bill is made');
assert_true(RcBills::row($DB, $C, $mine) === null && RcFiles::path($C, $mrow['path']) === null, 'the row and the file are gone');

// ---------------------------------------------------------------- readers never invent
$DB->prepare("INSERT INTO receipt_file (company_id, path, mime, size_bytes, uploaded_by, uploaded_at, ocr_status) VALUES (?, 'uploads/receipts/1/old.pdf', 'application/pdf', 1, 1, NOW(), 'parsed')")->execute([$C]);
$old = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO receipt_ocr (file_id, vendor_name, invoice_number, total, confidence_score, created_at) VALUES (?, 'Unknown Supplier', 'INV-4821', 4410.00, 0.00, NOW())")->execute([$old]);
RcSchema::clearInventedReadings($DB);
$o = $DB->query("SELECT rf.ocr_status, ro.vendor_name, ro.invoice_number, ro.total FROM receipt_file rf JOIN receipt_ocr ro ON ro.file_id = rf.file_id WHERE rf.file_id = $old")->fetch(PDO::FETCH_ASSOC);
assert_eq(['failed', null, null, null], [$o['ocr_status'], $o['vendor_name'], $o['invoice_number'], $o['total']], 'a reading the old engine made up is cleared');
RcKit::saveSettings($DB, $C, ['ocr_provider' => 'manual']);
$m = rc_slip($DB, ['MANUAL SUPPLIER', 'TOTAL 99.00']);
$r = RcReader::readAndStore($DB, $C, $U, RcBills::row($DB, $C, $m), RcKit::settings($DB, $C));
assert_true(!$r['ok'] && $r['engine'] === 'manual' && $r['fields']['total'] === null && $r['fields']['vendor_name'] === null, '"type it in": nothing is read, nothing invented');
assert_eq('failed', RcBills::row($DB, $C, $m)['ocr_status'], 'left for a person to type');
if (RcReader::canRun('pdftotext')) {
    RcKit::saveSettings($DB, $C, ['ocr_provider' => 'tesseract']);
    $t = rc_slip($DB, ['ACME HARDWARE', "VAT No $vatPdf", 'Tax Invoice 88231', 'Date 2026-10-16', 'Total 664.99', 'VAT 86.74']);
    $r = RcReader::readAndStore($DB, $C, $U, RcBills::row($DB, $C, $t), RcKit::settings($DB, $C));
    assert_true($r['ok'] && $r['engine'] === 'pdf-text', 'a PDF with text is read from its text');
    assert_eq([$vatPdf, '88231', '2026-10-16'], [$r['fields']['vendor_vat'], $r['fields']['invoice_number'], $r['fields']['invoice_date']], 'and its fields come back');
    assert_cents(664.99, $r['fields']['total'], 'the total');
}

// ---------------------------------------------------------------- settings: the first save keeps the shown defaults
$fresh = 900000 + random_int(1, 99999);
RcKit::saveSettings($DB, $fresh, ['default_due_days' => 7]);
$fs = RcKit::settings($DB, $fresh);
assert_eq([7, 0.0, 'gpt-4o-mini'], [$fs['default_due_days'], $fs['require_po_over_amount'], $fs['ai_model']], 'a company\'s first save writes the defaults the page showed (no purchase-order limit)');
$DB->exec("DELETE FROM receipt_settings WHERE company_id = $fresh");

test_done('receipts_bills');
