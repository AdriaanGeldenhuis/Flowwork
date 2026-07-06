<?php
/**
 * WS3: VAT201 box arithmetic — standard / zero-rated / exempt supplies,
 * capital-goods input split, manual adjustments, Box 5/9/10 totals.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/VatCalculator.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';
require_once FLOWWORK_ROOT . '/finances/lib/TaxCodes.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$vatOut = $map->code('finance_vat_output_account_id');
$vatIn  = $map->code('finance_vat_input_account_id');
$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$cust = make_customer($DB);
$sup = make_supplier($DB);

// --- TaxCodes single source of truth ----------------------------------------
$tc = new TaxCodes($DB, TEST_COMPANY_ID);
assert_eq(15.0, $tc->standardRatePercent(), 'standard rate from STD tax code');
$threw = false;
try { $tc->resolveOutputForLine(null, 'STD', 10.0); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'rate/code mismatch rejected');
assert_true($tc->resolveOutputForLine(null, 'EXEMPT', 0.0) > 0, 'EXEMPT resolves');

// --- Supplies: standard 1000@15, zero-rated 500, exempt 200 -----------------
$svc->postInvoice(make_invoice($DB, $cust, [[1, 1000.00, 15, 'Std']]));
$svc->postInvoice(make_invoice($DB, $cust, [[1, 500.00, 0, 'Zero', 'tax_code' => 'ZERO']]));
$svc->postInvoice(make_invoice($DB, $cust, [[1, 200.00, 0, 'Exempt', 'tax_code' => 'EXEMPT']]));

// --- Input: expense bill 400@15 (60 VAT), capital bill 2000@15 (300 VAT) ----
function make_bill(PDO $db, int $sup, float $net, float $rate, ?int $glAccountId = null): int
{
    $vat = round($net * $rate / 100, 2);
    $db->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
                  subtotal, tax, total, status, created_by, created_at)
                  VALUES (?, ?, CURDATE(), CURDATE(), CONCAT('V-', UUID_SHORT()), ?, ?, ?, 'draft', ?, NOW())")
       ->execute([TEST_COMPANY_ID, $sup, $net, $vat, $net + $vat, TEST_USER_ID]);
    $billId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order, gl_account_id)
                  VALUES (?, 'Line', 1, ?, ?, ?, 0, ?)")
       ->execute([$billId, $net, $rate, $net + $vat, $glAccountId]);
    return $billId;
}

$svc->postApBill(make_bill($DB, $sup, 400.00, 15));
// Capital goods: line hits a fixed_asset account
$faAccount = (int)$DB->query("SELECT account_id FROM gl_accounts
    WHERE company_id = 1 AND account_subtype = 'fixed_asset' AND is_active = 1
    ORDER BY account_code LIMIT 1")->fetchColumn();
assert_true($faAccount > 0, 'a fixed_asset account exists');
$svc->postApBill(make_bill($DB, $sup, 2000.00, 15, $faAccount));

// --- Boxes before adjustments ------------------------------------------------
$start = date('Y-m-01');
$end = date('Y-m-t');
$boxes = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);

assert_eq(100000, $boxes['output_standard_base_cents'], 'Box 1: standard base 1000');
assert_eq(15000, $boxes['output_standard_vat_cents'], 'Box 1A: output VAT 150');
assert_eq(50000, $boxes['output_zero_base_cents'], 'Box 2: zero-rated 500');
assert_eq(20000, $boxes['output_exempt_base_cents'], 'Box 3: exempt 200');
assert_eq(0, $boxes['untagged_revenue_base_cents'], 'no untagged revenue');
assert_eq(30000, $boxes['input_capital_cents'], 'Box 7: capital input VAT 300');
assert_eq(6000, $boxes['input_other_cents'], 'Box 8: other input VAT 60');
assert_eq(15000, $boxes['box5_total_output_cents'], 'Box 5 = 1A (no adjustments)');
assert_eq(36000, $boxes['box9_total_input_cents'], 'Box 9 = 7 + 8');
assert_eq(-21000, $boxes['box10_net_cents'], 'Box 10 = 5 - 9 (refund)');

// --- Manual adjustment: +R50 output (module vat_adjust) ----------------------
$vatCtrl = $map->code('finance_vat_control_account_id');
$DB->prepare("INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, status, created_by, created_at, posted_by, posted_at)
              VALUES (?, ?, 'VAT Adjustment', 'change in use', 'vat_adjust', 'vat_period', 'posted', 1, NOW(), 1, NOW())")
   ->execute([TEST_COMPANY_ID, $end]);
$adjJid = (int)$DB->lastInsertId();
$ins = $DB->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit) VALUES (?,?,?,?,?)");
$ins->execute([$adjJid, $vatOut, 'VAT adjustment', 0, 50.00]);
$ins->execute([$adjJid, $vatCtrl, 'VAT adjustment contra', 50.00, 0]);

$boxes2 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);
assert_eq(15000, $boxes2['output_standard_vat_cents'], 'Box 1A unchanged by adjustment');
assert_eq(5000, $boxes2['change_in_use_output_cents'], 'Box 4 = adjustment 50');
assert_eq(20000, $boxes2['box5_total_output_cents'], 'Box 5 = 1A + Box 4');
assert_eq(-16000, $boxes2['box10_net_cents'], 'Box 10 includes the adjustment');

// GL tie-out: Box 5 equals the VAT output account balance (credit-positive)
assert_eq($boxes2['box5_total_output_cents'], -gl_balance_cents($DB, $vatOut),
    'Box 5 ties to the VAT output GL account');
assert_eq($boxes2['box9_total_input_cents'], gl_balance_cents($DB, $vatIn),
    'Box 9 ties to the VAT input GL account');

test_done('vat201');
