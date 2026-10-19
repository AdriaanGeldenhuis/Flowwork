<?php
/**
 * Finance rebuild — VAT periods and the VAT201 (finances/lib/FinVat.php).
 *
 * The change in behaviour, shown with numbers: output VAT R1,000, input VAT
 * R400 and a +R100 output adjustment (change in use). SARS is owed Box 10 =
 * 1,000 + 100 − 400 = R700.
 *   - Old screen: the adjustment's other side went to VAT Control (Dr 100);
 *     filing then credited VAT Control with Box 10 (700), so VAT Control ended
 *     at R600 — R100 away from what is owed.
 *   - Now: the other side goes to the account the adjustment is about (an
 *     expense here); VAT Control ends at exactly R700, and at nil once SARS is
 *     paid. A VAT or control account is refused as the other side.
 * Also: filing in date order, the lock placed on filing, idempotent
 * adjustments, undo before filing, and year-end journals left out of the
 * "revenue without a VAT code" check (boxes unchanged).
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinVat.php';

fin_setup($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;
[$out, $in, $ctl] = FinVat::codes($DB, $C);
$bankCode = '1020';
$exp = fin_pick_account($DB, 'expense');

// a two-month period in the past, with output 1000 and input 400 in it
$start = date('Y-m-01', strtotime('first day of -6 months'));
$end = date('Y-m-t', strtotime($start . ' +1 month'));
$mid = date('Y-m-15', strtotime($start));
$std = tax_code_id_by_code($DB, 'STD');
$inp = tax_code_id_by_code($DB, 'INPUT');
fin_post($DB, $mid, [[$bankCode, 1000, 0], [$out, 0, 1000, $std]]);
fin_post($DB, $mid, [[$in, 400, 0, $inp], [$bankCode, 0, 400]]);

$id = FinVat::create($DB, $C, $U, 'bookkeeper', $start, $end);
expect_error(fn() => FinVat::create($DB, $C, $U, 'bookkeeper', $mid, date('Y-m-d', strtotime($end . ' +10 days'))), 'an overlapping period is refused', 'overlaps');
expect_error(fn() => FinVat::create($DB, $C, $U, 'viewer', '2020-01-01', '2020-01-31'), 'a viewer cannot add a period');

$p = FinVat::period($DB, $C, $id);
assert_cents(1000.00, $p['period']['figures']['output'], 'live Box 5 before preparing');
assert_cents(400.00, $p['period']['figures']['input'], 'live Box 9 before preparing');
expect_error(fn() => FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => 100]], $exp['id']), 'an open period cannot be adjusted', 'Prepare');

FinVat::prepare($DB, $C, $U, 'bookkeeper', $id);
$row = $DB->query("SELECT status, net_vat_cents FROM gl_vat_periods WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
assert_eq('prepared', $row['status'], 'prepared');
assert_eq(60000, (int)$row['net_vat_cents'], 'prepared Box 10 snapshot R600');

// the other side cannot be VAT Control (the old behaviour) or any control / VAT account
expect_error(fn() => FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => 100]], fin_account_id($DB, $ctl)), 'VAT Control as the other side is refused', 'VAT Control');
expect_error(fn() => FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => 100]], fin_account_id($DB, $in)), 'VAT input as the other side is refused');
expect_error(fn() => FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => 100]], 0), 'an unbalanced adjustment needs an account', 'other side');

$key = 'k' . bin2hex(random_bytes(6));
$j1 = FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => '100', 'memo' => 'Change in use']], $exp['id'], $key);
$j2 = FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => '100', 'memo' => 'Change in use']], $exp['id'], $key);
assert_eq($j1, $j2, 'the same key does not post twice');
assert_balanced_journal($DB, $j1, 'adjustment');
$lines = $DB->query("SELECT account_code, debit, credit FROM journal_lines WHERE journal_id = $j1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assert_eq(2, count($lines), 'two lines');
assert_eq($out, $lines[0]['account_code'], 'credit VAT output');
assert_eq($exp['code'], $lines[1]['account_code'], 'debit the chosen expense — not VAT Control');
assert_eq(0, gl_balance_cents($DB, $ctl), 'VAT Control untouched by the adjustment');

// undo + redo before filing
FinVat::undoAdjustment($DB, $C, $U, 'bookkeeper', $id, $j1);
assert_cents(600.00, FinVat::period($DB, $C, $id)['period']['figures']['net'], 'undone: Box 10 back to R600');
$j3 = FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => '100']], $exp['id'], 'k' . bin2hex(random_bytes(6)));
$p = FinVat::period($DB, $C, $id);
assert_cents(1100.00, $p['period']['figures']['output'], 'Box 5 = 1A + 4 = R1,100');
assert_cents(700.00, $p['period']['figures']['net'], 'Box 10 = R700');
assert_eq('adjusted', $DB->query("SELECT status FROM gl_vat_periods WHERE id = $id")->fetchColumn(), 'adjusted');
expect_error(fn() => FinVat::reopen($DB, $C, $U, 'bookkeeper', $id), 'an adjusted period cannot go back to open');

// filing: admins only, in date order
expect_error(fn() => FinVat::file($DB, $C, $U, 'bookkeeper', $id), 'a bookkeeper cannot file');
$earlier = FinVat::create($DB, $C, $U, 'bookkeeper', date('Y-m-01', strtotime($start . ' -2 months')), date('Y-m-t', strtotime($start . ' -1 month')));
$future = FinVat::create($DB, $C, $U, 'bookkeeper', date('Y-m-01'), date('Y-m-t'));
FinVat::prepare($DB, $C, $U, 'bookkeeper', $future);
expect_error(fn() => FinVat::file($DB, $C, $U, 'admin', $id), 'a later period cannot be filed before an earlier one', 'date order');
FinVat::delete($DB, $C, $U, 'bookkeeper', $earlier);
FinVat::file($DB, $C, $U, 'admin', $id);
expect_error(fn() => FinVat::file($DB, $C, $U, 'admin', $future), 'a period that has not ended cannot be filed', 'ended');
FinVat::reopen($DB, $C, $U, 'bookkeeper', $future);
FinVat::delete($DB, $C, $U, 'bookkeeper', $future);

assert_eq(70000, -gl_balance_cents($DB, $ctl), 'after filing VAT Control holds exactly Box 10 (R700) — the old screen left R600');
assert_eq(0, gl_balance_cents($DB, $out), 'VAT output cleared');
assert_eq(0, gl_balance_cents($DB, $in), 'VAT input cleared');
$lock = $DB->query("SELECT lock_date, lock_reason FROM gl_period_locks WHERE company_id = $C AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
assert_eq($end, $lock['lock_date'], 'filing locks the books through the period end');
assert_eq('vat_period_filed', $lock['lock_reason'], 'with the system reason');
expect_error(fn() => FinVat::adjust($DB, $C, $U, 'bookkeeper', $id, [['side' => 'output', 'amount' => 1]], $exp['id']), 'a filed return cannot be adjusted');

// pay SARS
$bank = fin_bank($DB);
expect_error(fn() => FinVat::pay($DB, $C, $U, 'bookkeeper', $id, ['bank_account_id' => $bank, 'date' => date('Y-m-d'), 'amount' => '800']), 'paying more than Box 10 is refused');
$pj = FinVat::pay($DB, $C, $U, 'bookkeeper', $id, ['bank_account_id' => $bank, 'date' => date('Y-m-d'), 'key' => 'pay1']);
assert_balanced_journal($DB, $pj, 'payment');
assert_eq($pj, FinVat::pay($DB, $C, $U, 'bookkeeper', $id, ['bank_account_id' => $bank, 'date' => date('Y-m-d'), 'key' => 'pay1']), 'the same key does not pay twice');
assert_eq(0, gl_balance_cents($DB, $ctl), 'VAT Control is nil once SARS is paid');
assert_eq('paid', $DB->query("SELECT status FROM gl_vat_periods WHERE id = $id")->fetchColumn(), 'period reads paid');
$b = FinVat::boxes($DB, $C, $start, $end, 'invoice');
assert_eq(70000, $b['box10_net_cents'], 'the payment and settlement journals do not change the boxes');
FinVat::undoPayment($DB, $C, $U, 'bookkeeper', $id, $pj);
assert_eq(70000, -gl_balance_cents($DB, $ctl), 'undoing the payment puts R700 back on VAT Control');
assert_eq('filed', $DB->query("SELECT status FROM gl_vat_periods WHERE id = $id")->fetchColumn(), 'back to filed');

// The old behaviour, reproduced for the record on the next period: an adjustment balanced to
// VAT Control leaves VAT Control R100 short of Box 10 after filing, and the screen says so.
$s2 = date('Y-m-d', strtotime($end . ' +1 day'));
$e2 = date('Y-m-t', strtotime($s2 . ' +1 month'));
$m2 = date('Y-m-15', strtotime($s2));
fin_post($DB, $m2, [[$bankCode, 1000, 0], [$out, 0, 1000, $std]]);
fin_post($DB, $m2, [[$in, 400, 0, $inp], [$bankCode, 0, 400]]);
$id2 = FinVat::create($DB, $C, $U, 'bookkeeper', $s2, $e2);
FinVat::prepare($DB, $C, $U, 'bookkeeper', $id2);
$ctlBefore = gl_balance_cents($DB, $ctl);
(new PostingService($DB, $C, $U))->postAdHocJournal(['entry_date' => $e2, 'reference' => 'VAT Adjustment', 'description' => 'old-style adjustment', 'module' => 'vat_adjust',
    'ref_type' => 'vat_period', 'ref_id' => $id2, 'source_type' => 'vat_adjustment', 'source_id' => $id2],
    [['account_code' => $out, 'description' => 'adj', 'debit' => 0, 'credit' => 100], ['account_code' => $ctl, 'description' => 'VAT adjustment contra', 'debit' => 100, 'credit' => 0]]);
$p2 = FinVat::period($DB, $C, $id2);
assert_eq(10000, $p2['legacy_control_cents'], 'the period shows the R100 an old-style adjustment put on VAT Control');
assert_cents(700.00, $p2['period']['figures']['net'], 'Box 10 is still R700');
FinVat::file($DB, $C, $U, 'admin', $id2);
assert_eq(60000, -(gl_balance_cents($DB, $ctl) - $ctlBefore), 'old style: VAT Control moved by only R600 against R700 owed');

// year-end journals are not "revenue without a VAT code"
$rev = fin_pick_account($DB, 'revenue');
$re = fin_account_id($DB, '3200');
$ys = date('Y-m-01', strtotime('first day of -1 months'));
fin_post($DB, $ys, [[$rev['code'], 250, 0], ['3200', 0, 250]], 'year_end');
$b2 = FinVat::boxes($DB, $C, $ys, date('Y-m-t', strtotime($ys)), 'invoice');
assert_eq(0, $b2['untagged_revenue_base_cents'], 'a year-end closing line is not counted as untagged revenue');
assert_eq(0, $b2['box10_net_cents'], 'and the boxes are untouched');
unset($re);

test_done('fin_vat');
