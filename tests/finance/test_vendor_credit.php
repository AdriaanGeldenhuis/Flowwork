<?php
/**
 * DUE-DILIGENCE-2026-07 Section F: vendor credits had zero automated coverage.
 * postVendorCredit reverses an expense purchase — Cr expense, Cr VAT input,
 * Dr AP — reducing both the expense and the AP control liability. This verifies
 * the journal balances, each leg lands on the mapped account, and the credit
 * flips to 'applied'.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$map = new AccountsMap($DB, TEST_COMPANY_ID);
$apCode    = $map->code('finance_ap_account_id');
$vatInCode = $map->code('finance_vat_input_account_id');

// A non-capital expense account for the credit line (capital lines route the
// input VAT to the Box 7 side, which is a separate path).
$expId = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='expense' AND (account_subtype IS NULL OR account_subtype<>'fixed_asset')
    AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
assert_true($expId > 0, 'found an expense account for the credit line');
$expCode = $map->getById($expId);

$sup = make_supplier($DB);

$before = [
    'ap'  => gl_balance_cents($DB, $apCode),
    'vat' => gl_balance_cents($DB, $vatInCode),
    'exp' => gl_balance_cents($DB, $expCode),
];

// R100 net + R15 VAT = R115 credit.
$num = 'VC-' . bin2hex(random_bytes(3));
$DB->prepare("INSERT INTO vendor_credits (company_id, credit_number, supplier_id, issue_date, status, subtotal, tax, total, created_by, created_at)
              VALUES (?, ?, ?, CURDATE(), 'draft', 100, 15, 115, ?, NOW())")
   ->execute([TEST_COMPANY_ID, $num, $sup, TEST_USER_ID]);
$creditId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO vendor_credit_lines (credit_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, gl_account_id, sort_order)
              VALUES (?, 'Returned goods', 1, 'ea', 100, 0, 15, 115, ?, 0)")
   ->execute([$creditId, $expId]);

$svc->postVendorCredit($creditId);

$row = $DB->query("SELECT status, journal_id FROM vendor_credits WHERE id=$creditId")->fetch(PDO::FETCH_ASSOC);
assert_eq('applied', $row['status'], 'vendor credit flips to applied after posting');
assert_true(!empty($row['journal_id']), 'vendor credit records its journal_id');
assert_balanced_journal($DB, (int)$row['journal_id'], '(vendor credit)');

$after = [
    'ap'  => gl_balance_cents($DB, $apCode),
    'vat' => gl_balance_cents($DB, $vatInCode),
    'exp' => gl_balance_cents($DB, $expCode),
];
// Dr AP 115 => AP net (debit-credit) rises by +11500 cents (the liability drops).
assert_eq(11500, $after['ap'] - $before['ap'], 'AP control debited by R115 (liability reduced)');
// Cr VAT input 15 => VAT-input net falls by 1500 (reverses the input claim).
assert_eq(-1500, $after['vat'] - $before['vat'], 'VAT input credited by R15');
// Cr expense 100 => expense net falls by 10000 (reverses the cost).
assert_eq(-10000, $after['exp'] - $before['exp'], 'expense credited by R100');

test_done('vendor_credit');
