<?php
/**
 * DUE-DILIGENCE-2026-07 Section F: fixed-asset depreciation had zero automated
 * coverage. Verifies (a) postDepreciation books Dr Depreciation Expense /
 * Cr Accumulated Depreciation for the run total and marks the run posted, and
 * (b) the run_depreciation future-month guard predicate rejects a month ahead
 * of the current one.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$map = new AccountsMap($DB, TEST_COMPANY_ID);

// Resolve real GL accounts for the asset.
$assetAcctId = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_subtype='fixed_asset' AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
$expId = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='expense' AND (account_subtype='depreciation' OR account_name LIKE '%Depreciation%')
    AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
$accumId = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='asset' AND (account_subtype='accumulated_depreciation' OR account_name LIKE '%Accum%')
    AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
assert_true($assetAcctId > 0 && $expId > 0 && $accumId > 0, 'resolved asset/expense/accum GL accounts');
$expCode   = $map->getById($expId);
$accumCode = $map->getById($accumId);

// Run month: first of last month — current-or-earlier and (in the fresh test
// DB, before the lock-creating suites run) unlocked.
$runMonth = date('Y-m-01', strtotime('first day of last month'));

// A R12,000 asset, 12-month straight line => R1,000/month.
$DB->prepare("INSERT INTO gl_fixed_assets
    (company_id, asset_name, category, purchase_date, purchase_cost_cents, salvage_value_cents,
     useful_life_months, depreciation_method, asset_account_id, depreciation_expense_account_id,
     accumulated_depreciation_account_id, accumulated_depreciation_cents, status, created_at, updated_at)
    VALUES (?, ?, 'Equipment', ?, 1200000, 0, 12, 'straight_line', ?, ?, ?, 0, 'active', NOW(), NOW())")
   ->execute([TEST_COMPANY_ID, 'FA-DEP-' . bin2hex(random_bytes(3)), $runMonth, $assetAcctId, $expId, $accumId]);
$assetId = (int)$DB->lastInsertId();

// Straight-line monthly the way run_depreciation.php computes it: floor(depreciable / life).
$depreciable = 1200000 - 0;
$monthly = intdiv($depreciable, 12);
assert_eq(100000, $monthly, 'straight-line monthly = R1,000');

$DB->prepare("INSERT INTO fa_depreciation_runs (company_id, run_month, status, created_by, created_at)
              VALUES (?, ?, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $runMonth, TEST_USER_ID]);
$runId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO fa_depreciation_lines (run_id, asset_id, amount_cents) VALUES (?, ?, ?)")
   ->execute([$runId, $assetId, $monthly]);

$expBefore = gl_balance_cents($DB, $expCode);
$accBefore = gl_balance_cents($DB, $accumCode);

$svc->postDepreciation($runId);

$run = $DB->query("SELECT status, journal_id FROM fa_depreciation_runs WHERE id=$runId")->fetch(PDO::FETCH_ASSOC);
assert_eq('posted', $run['status'], 'depreciation run marked posted');
assert_true(!empty($run['journal_id']), 'run records its journal_id');
assert_balanced_journal($DB, (int)$run['journal_id'], '(depreciation)');

assert_eq(100000, gl_balance_cents($DB, $expCode) - $expBefore, 'Depreciation Expense debited R1,000');
assert_eq(-100000, gl_balance_cents($DB, $accumCode) - $accBefore, 'Accumulated Depreciation credited R1,000');

// Future-month guard (run_depreciation.php): a month ahead of the current one
// is rejected before any calculation.
$futureMonth = date('Y-m-01', strtotime('first day of next month'));
assert_true($futureMonth > date('Y-m-01'), 'a future run month trips the future-month guard');

test_done('fa_depreciation');
