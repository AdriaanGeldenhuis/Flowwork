<?php
/**
 * DUE-DILIGENCE-2026-07 C2 / B21: the dashboard AR KPIs multiply
 * balance_due * exchange_rate. invoices.exchange_rate was added NULL with no
 * default, and a NULL rate makes the product NULL so SUM silently drops the row
 * — every such invoice read R0 in Receivables Outstanding and every aging
 * bucket. The fix guards each site with COALESCE(NULLIF(exchange_rate,0),1)
 * (finances/index.php) and backfills NULL -> 1 (the reconcile migration).
 *
 * make_invoice() inserts without exchange_rate, so a fresh invoice reproduces
 * the exact B21 trigger (a NULL rate) — which is why no prior test caught it.
 */
require __DIR__ . '/../lib/bootstrap.php';

$cust = make_customer($DB);
$inv = make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std']]); // total 1150, NULL exchange_rate
$DB->prepare("UPDATE invoices SET status='sent', balance_due=1150 WHERE id=?")->execute([$inv]);

// Precondition: the invoice really has a NULL rate (the B21 trigger).
$rate = $DB->query("SELECT exchange_rate FROM invoices WHERE id=$inv")->fetchColumn();
assert_true($rate === null, 'make_invoice leaves exchange_rate NULL (reproduces the B21 trigger)');

// The BROKEN expression (bare rate) drops the NULL-rate invoice from the SUM.
$broken = (float)$DB->query(
    "SELECT COALESCE(SUM(balance_due * exchange_rate), 0) FROM invoices WHERE id=$inv"
)->fetchColumn();
assert_cents(0.00, $broken, 'bare balance_due*exchange_rate silently drops the NULL-rate invoice (the bug)');

// The FIXED expression (the exact guard used across finances/index.php) includes
// it at rate 1.
$fixed = (float)$DB->query(
    "SELECT COALESCE(SUM(balance_due * COALESCE(NULLIF(exchange_rate,0),1)), 0) FROM invoices WHERE id=$inv"
)->fetchColumn();
assert_cents(1150.00, $fixed, 'COALESCE(NULLIF(rate,0),1) includes the NULL-rate invoice at rate 1 (the fix)');

// And the reconcile migration's backfill means real rows are never NULL: assert
// no seeded/base-dump invoice is left NULL after provisioning.
$nullCount = (int)$DB->query(
    "SELECT COUNT(*) FROM invoices WHERE company_id = " . TEST_COMPANY_ID . " AND exchange_rate IS NULL AND id <> $inv"
)->fetchColumn();
assert_eq(0, $nullCount, 'the reconcile backfill leaves no pre-existing invoice with a NULL exchange_rate');

test_done('dashboard_ar_kpi');
