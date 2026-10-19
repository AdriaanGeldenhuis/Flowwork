<?php
/**
 * Finance rebuild — fixed assets (FinAssets) and closing the books (FinClose).
 * Depreciation uses the old run's sums (straight line floor((cost−residual)/life),
 * declining balance floor(book × 2/life)), once per month, never ahead; a
 * disposal journal balances to the cent with gain/loss and VAT on proceeds.
 * Locks: reserved reasons refused, removal by an admin with a reason, system
 * locks only through their own process. Year end: closes P&L into retained
 * earnings, locks the year, refuses a second close, and can be reopened.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinAssets.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinClose.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinReports.php';

fin_setup($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;

// ---------------------------------------------------------------- assets
$asset = ['name' => 'Hilux', 'category' => 'Vehicles', 'purchased' => date('Y-m-d', strtotime('first day of -4 months')), 'cost' => '450000', 'salvage' => '50000', 'life' => 60,
          'method' => 'straight_line', 'asset_account_id' => fin_account_id($DB, '1530'), 'accum_account_id' => fin_account_id($DB, '1630'), 'expense_account_id' => fin_account_id($DB, '6920')];
expect_error(fn() => FinAssets::save($DB, $C, $U, 'bookkeeper', ['salvage' => '500000'] + $asset), 'a residual above cost is refused', 'residual');
expect_error(fn() => FinAssets::save($DB, $C, $U, 'bookkeeper', ['expense_account_id' => fin_account_id($DB, '1530')] + $asset), 'depreciation must go to an expense account', 'expense');
$id = FinAssets::save($DB, $C, $U, 'bookkeeper', $asset);
$row = $DB->query("SELECT * FROM gl_fixed_assets WHERE asset_id = $id")->fetch(PDO::FETCH_ASSOC);
assert_eq(666666, FinAssets::monthlyCents($row), 'straight line: floor(400,000 / 60) = R6,666.66 a month');
$ddb = $row; $ddb['depreciation_method'] = 'declining_balance';
assert_eq(1500000, FinAssets::monthlyCents($ddb), 'declining balance: floor(450,000 × 2 / 60) = R15,000 the first month');
assert_eq(0, FinAssets::monthlyCents(['accumulated_depreciation_cents' => 40000000] + $row), 'nothing once fully depreciated');

$m1 = date('Y-m', strtotime('first day of -2 months'));
expect_error(fn() => FinAssets::run($DB, $C, $U, 'bookkeeper', date('Y-m', strtotime('first day of +1 month'))), 'no depreciation for a future month', 'future');
$r = FinAssets::run($DB, $C, $U, 'bookkeeper', $m1);
assert_cents(6666.66, $r['total'], 'one month posted');
$jid = (int)$DB->query("SELECT journal_id FROM fa_depreciation_runs WHERE id = {$r['run_id']}")->fetchColumn();
assert_balanced_journal($DB, $jid, 'depreciation');
assert_eq(-666666, gl_balance_cents($DB, '1630'), 'accumulated depreciation credited');
expect_error(fn() => FinAssets::run($DB, $C, $U, 'bookkeeper', $m1), 'a month runs once', 'already');
expect_error(fn() => FinAssets::save($DB, $C, $U, 'bookkeeper', ['id' => $id, 'cost' => '460000'] + $asset), 'cost is fixed once depreciated', 'depreciated');
FinAssets::save($DB, $C, $U, 'bookkeeper', ['id' => $id, 'name' => 'Toyota Hilux'] + $asset);
assert_eq('Toyota Hilux', $DB->query("SELECT asset_name FROM gl_fixed_assets WHERE asset_id = $id")->fetchColumn(), 'the name can still change');

// dispose for R460,000 incl. VAT: VAT R60,000, R400,000 ex VAT against a book value of R443,333.34 → loss R43,333.34
$bank = fin_bank($DB, '1020', 'Ops');
$dj = FinAssets::dispose($DB, $C, $U, 'bookkeeper', $id, ['date' => date('Y-m-d', strtotime('-3 days')), 'proceeds' => '460000', 'vat' => true, 'bank_account_id' => $bank]);
assert_balanced_journal($DB, $dj, 'disposal');
$lines = $DB->query("SELECT account_code, debit, credit FROM journal_lines WHERE journal_id = $dj")->fetchAll(PDO::FETCH_ASSOC);
$by = []; foreach ($lines as $l) $by[$l['account_code']] = (int)round(((float)$l['debit'] - (float)$l['credit']) * 100);
assert_eq(46000000, $by['1020'], 'proceeds into the chosen bank');
assert_eq(-6000000, $by[fin_code($DB, 'finance_vat_output_account_id')], 'output VAT 15/115 of the proceeds');
assert_eq(4333334, $by[fin_code($DB, 'finance_loss_on_disposal_account_id')], 'loss = book value 443,333.34 − 400,000 ex VAT');
assert_eq(0, gl_balance_cents($DB, '1630') - (-666666) - 666666, 'accumulated depreciation reversed');
expect_error(fn() => FinAssets::dispose($DB, $C, $U, 'bookkeeper', $id, ['date' => date('Y-m-d'), 'proceeds' => '1']), 'an asset is disposed of once', 'already');

// ----------------------------------------------------------------- locks
expect_error(fn() => FinClose::lock($DB, $C, $U, 'bookkeeper', date('Y-m-d', strtotime('-40 days')), 'vat_period_filed'), 'a reserved reason is refused', 'reserved');
expect_error(fn() => FinClose::lock($DB, $C, $U, 'bookkeeper', date('Y-m-d', strtotime('-40 days')), 'year_end_close_2026'), 'the year-end reason is reserved too', 'reserved');
$lid = FinClose::lock($DB, $C, $U, 'bookkeeper', date('Y-m-d', strtotime('-40 days')), 'Month reviewed');
expect_error(fn() => FinClose::unlock($DB, $C, $U, 'bookkeeper', $lid, 'oops'), 'only an admin removes a lock');
expect_error(fn() => FinClose::unlock($DB, $C, $U, 'admin', $lid, ''), 'removing a lock needs a reason', 'why');
FinClose::unlock($DB, $C, $U, 'admin', $lid, 'Late supplier invoice');
assert_eq(0, (int)$DB->query("SELECT is_active FROM gl_period_locks WHERE lock_id = $lid")->fetchColumn(), 'lock removed (kept as history)');
$DB->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active) VALUES (?, ?, 'vat_period_filed', ?, NOW(), 1)")->execute([$C, date('Y-m-d', strtotime('-400 days')), $U]);
$sys = (int)$DB->lastInsertId();
expect_error(fn() => FinClose::unlock($DB, $C, $U, 'admin', $sys, 'try'), 'a VAT-filing lock is not removed here', 'VAT filing');
$DB->exec("DELETE FROM gl_period_locks WHERE lock_id = $sys");

// -------------------------------------------------------------- year end
// last financial year: income 1,000, expense 300 → profit 700 to retained earnings
$fyStart = (int)substr(Fin::fyStart($DB, $C), 0, 4) - 1;
$fy = Fin::fyStart($DB, $C, ($fyStart) . '-' . sprintf('%02d', Fin::monthNumber(Fin::engineSettings($DB, $C)['fiscal_year_start'])) . '-15');
$fyEnd = date('Y-m-d', strtotime($fy . ' +1 year -1 day'));
$rev = fin_pick_account($DB, 'revenue'); $exp = fin_pick_account($DB, 'expense');
$mid = date('Y-m-d', strtotime($fy . ' +2 months'));
fin_post($DB, $mid, [['1020', 1000, 0], [$rev['code'], 0, 1000]]);
fin_post($DB, $mid, [[$exp['code'], 300, 0], ['1020', 0, 300]]);
$pv = FinClose::preview($DB, $C, $fy);
assert_cents(700.00, $pv['net'], 'preview: profit R700 to retained earnings');
expect_error(fn() => FinClose::closeYear($DB, $C, $U, 'bookkeeper', $fy), 'only an admin closes a year');
$reBefore = gl_balance_cents($DB, '3200');
$yj = FinClose::closeYear($DB, $C, $U, 'admin', $fy);
assert_balanced_journal($DB, $yj, 'closing journal');
assert_eq(-70000, gl_balance_cents($DB, '3200') - $reBefore, 'retained earnings credited R700');
assert_true((new PeriodService($DB, $C))->isLocked($fyEnd), 'the year is locked');
expect_error(fn() => FinClose::closeYear($DB, $C, $U, 'admin', $fy), 'a year is closed once', 'already closed');
$years = FinClose::years($DB, $C);
assert_true(count(array_filter($years, fn($y) => $y['start'] === $fy && $y['closed'])) === 1, 'listed as closed');

// the balance sheet on the last day of the closed year has no stray "earlier years" line, and balances
$bs = FinReports::balanceSheet($DB, $C, $fyEnd);
assert_true($bs['checks'][0]['ok'], 'balance sheet balances at year end');
assert_eq([], array_values(array_filter($bs['rows'], fn($r) => str_contains($r['label'], 'earlier years'))), 'no stray retained earnings line after the close');
$pl = FinReports::incomeStatement($DB, $C, $fy, $fyEnd, 'none');
$net = array_values(array_filter($pl['rows'], fn($r) => ($r['kind'] ?? '') === 'grand'))[0]['cells']['amount'];
assert_cents(700.00, $net, 'the P&L for the closed year still shows R700 (the closing journal is left out)');

// reopen: reversal on the same day, lock lifted
expect_error(fn() => FinClose::reopenYear($DB, $C, $U, 'admin', $fy, ''), 'reopening needs a reason', 'why');
$rv = FinClose::reopenYear($DB, $C, $U, 'admin', $fy, 'Auditor adjustments');
assert_balanced_journal($DB, $rv, 'reopen reversal');
assert_eq($reBefore, gl_balance_cents($DB, '3200'), 'retained earnings back where they were');
assert_true(!(new PeriodService($DB, $C))->isLocked($fyEnd), 'the year is open again');
$yj2 = FinClose::closeYear($DB, $C, $U, 'admin', $fy);
assert_true($yj2 > 0, 'and can be closed again');

test_done('fin_assets_close');
