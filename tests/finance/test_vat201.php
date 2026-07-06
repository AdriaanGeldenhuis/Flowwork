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

// ---------------------------------------------------------------------------
// REGRESSION (review): a MIXED bill (fixed-asset + consumables) must split its
// input VAT between Box 7 and Box 8 per line — the bill-level capital flag
// used to mark the WHOLE bill's VAT as capital goods.
// ---------------------------------------------------------------------------
$mixedVat = round(10000 * 0.15 + 1000 * 0.15, 2);
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, issue_date, due_date, vendor_invoice_number,
              subtotal, tax, total, status, created_by, created_at)
              VALUES (?, ?, CURDATE(), CURDATE(), CONCAT('V-', UUID_SHORT()), 11000, ?, ?, 'draft', ?, NOW())")
   ->execute([TEST_COMPANY_ID, $sup, $mixedVat, 11000 + $mixedVat, TEST_USER_ID]);
$mixedBill = (int)$DB->lastInsertId();
$insLine = $DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit_price, tax_rate, line_total, sort_order, gl_account_id)
              VALUES (?, ?, 1, ?, 15, ?, ?, ?)");
$insLine->execute([$mixedBill, 'Laptop (capital)', 10000, 11500, 0, $faAccount]);
$insLine->execute([$mixedBill, 'Stationery', 1000, 1150, 1, null]);
$svc->postApBill($mixedBill);

$boxes3 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);
assert_eq($boxes2['input_capital_cents'] + 150000, $boxes3['input_capital_cents'],
    'mixed bill: only the fixed-asset line\'s VAT (1500) lands in Box 7');
assert_eq($boxes2['input_other_cents'] + 15000, $boxes3['input_other_cents'],
    'mixed bill: the consumables line\'s VAT (150) stays in Box 8');

// ---------------------------------------------------------------------------
// REGRESSION (review): s22 bad-debt write-off relief is an INPUT-side
// adjustment inside Box 9 — it must NOT reduce Box 1A (eFiling derives
// field 4 from field 1, so netting the output side breaks the form).
// ---------------------------------------------------------------------------
$invWo = make_invoice($DB, $cust, [[1, 800.00, 15, 'ToWriteOff']], ['status' => 'sent']); // 920 incl.
$svc->postInvoice($invWo);
$svc->postInvoiceWriteOff($invWo, 920.00, 'debtor liquidated');

$boxes4 = VatCalculator::vat201Boxes($DB, TEST_COMPANY_ID, $start, $end, $vatOut, $vatIn);
assert_eq($boxes3['output_standard_vat_cents'] + 12000, $boxes4['output_standard_vat_cents'],
    'Box 1A carries the written-off invoice\'s output VAT undiminished');
assert_eq(12000, $boxes4['bad_debt_relief_input_cents'],
    's22 relief (120.00) reported as the bad-debts input adjustment');
assert_eq($boxes3['box9_total_input_cents'] + 12000, $boxes4['box9_total_input_cents'],
    'Box 9 includes the s22 relief');
// The relief journal debits the VAT INPUT account under module bad_debt
$woRelief = $DB->query("SELECT COALESCE(SUM(jl.debit - jl.credit),0)
    FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
    WHERE je.company_id = " . TEST_COMPANY_ID . " AND je.module = 'bad_debt'
      AND jl.account_code = '" . $vatIn . "'")->fetchColumn();
assert_cents(12000, (float)$woRelief * 100 / 100 * 100, 'relief debit sits on the VAT input account');

// GL tie-outs must still hold after the mixed bill and the write-off.
assert_eq($boxes4['box5_total_output_cents'], -gl_balance_cents($DB, $vatOut),
    'Box 5 still ties to the VAT output GL account after the write-off');
assert_eq($boxes4['box9_total_input_cents'], gl_balance_cents($DB, $vatIn),
    'Box 9 still ties to the VAT input GL account after the write-off');

// ---------------------------------------------------------------------------
// REGRESSION (review): FX write-off posts in ZAR at the invoice's captured
// rate (s25D) — the face-value posting stranded 17/18ths of a USD invoice's
// AR and mis-sized the s22 relief.
// ---------------------------------------------------------------------------
$invFx = make_invoice($DB, $cust, [[1, 100.00, 15, 'USD services']],
    ['status' => 'sent', 'currency' => 'USD']); // USD 115.00
$DB->prepare("UPDATE invoices SET exchange_rate = 18.0 WHERE id = ?")->execute([$invFx]);
$svc->postInvoice($invFx);
$svc->postInvoiceWriteOff($invFx, 115.00, 'foreign debtor defaulted');
$woJournal = (int)$DB->query("SELECT id FROM journal_entries
    WHERE company_id = " . TEST_COMPANY_ID . " AND ref_type = 'invoice_write_off' AND ref_id = " . (int)$invFx)->fetchColumn();
assert_true($woJournal > 0, 'FX write-off journal exists');
assert_balanced_journal($DB, $woJournal, 'FX write-off');
$arCode = $map->code('finance_ar_account_id');
$woAr = (float)$DB->query("SELECT COALESCE(SUM(credit),0) FROM journal_lines
    WHERE journal_id = $woJournal AND account_code = '$arCode'")->fetchColumn();
assert_cents(207000, $woAr * 100, 'FX write-off credits AR with the full ZAR value (115 x 18 = 2070)');

test_done('vat201');
