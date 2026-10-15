<?php
/**
 * FinanceBootstrap: a company's finance foundation (core VAT codes, chart of
 * accounts, finance_* account mappings) self-heals at the point of need.
 *
 * Regressions for two production errors:
 *   - "Unknown tax code 'ZERO'" saving a zero-rated quote/invoice line when
 *     the company's ZERO code was missing or inactive;
 *   - "Unknown GL account code(s): 1020" recording a payment on a legacy
 *     chart (the bank is 1110 there) with no explicit bank mapping.
 * Plus: a brand-new company (no chart, no tax codes) can invoice and be paid.
 *
 * Master data (companies, charts, settings) is shared by every test file and
 * not reset between them, so everything here runs in one transaction that is
 * rolled back at the end.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/TaxCodes.php';
require_once FLOWWORK_ROOT . '/finances/lib/CoaSeed.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinanceBootstrap.php';
require_once FLOWWORK_ROOT . '/qi/lib/InvoiceLifecycle.php';

Audit::setPdo($DB);
$DB->beginTransaction();

// ---------------------------------------------------------------------------
// Helpers: the save_invoice.php / record_payment.php write paths for any company
// ---------------------------------------------------------------------------

function new_company(PDO $db, string $name): int
{
    $db->prepare("INSERT INTO companies (name, plan_id, created_at) VALUES (?, 1, NOW())")->execute([$name]);
    return (int)$db->lastInsertId();
}

function company_customer(PDO $db, int $cid): int
{
    $db->prepare("INSERT INTO crm_accounts (company_id, type, name, status, created_by, created_at)
                  VALUES (?, 'customer', ?, 'active', 1, NOW())")
       ->execute([$cid, 'Customer ' . bin2hex(random_bytes(4))]);
    return (int)$db->lastInsertId();
}

/** Lines [[qty, price, rate, tax_code]] — tax codes resolved like save_invoice.php. */
function company_invoice(PDO $db, int $cid, int $customerId, array $lines): int
{
    $taxCodes = new TaxCodes($db, $cid);
    $subC = 0;
    $vatC = 0;
    $rows = [];
    foreach ($lines as [$qty, $price, $rate, $code]) {
        $netC = (int)round($qty * $price * 100);
        $lineVatC = (int)round($netC * $rate / 100);
        $rows[] = [$qty, $price, $rate, $taxCodes->resolveOutputForLine(null, $code, $rate), ($netC + $lineVatC) / 100];
        $subC += $netC;
        $vatC += $lineVatC;
    }
    $db->prepare("INSERT INTO invoices
            (company_id, invoice_number, customer_id, issue_date, due_date, status,
             subtotal, discount, tax, total, balance_due, currency, created_by, created_at)
         VALUES (?, ?, ?, CURDATE(), CURDATE(), 'draft', ?, 0, ?, ?, ?, 'ZAR', 1, NOW())")
       ->execute([$cid, 'BOOT-' . random_int(1, 999999), $customerId,
                  $subC / 100, $vatC / 100, ($subC + $vatC) / 100, ($subC + $vatC) / 100]);
    $invoiceId = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO invoice_lines
            (invoice_id, item_description, quantity, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order)
         VALUES (?, 'Line', ?, ?, 0, ?, ?, ?, ?)");
    foreach ($rows as $i => [$qty, $price, $rate, $taxCodeId, $lineTotal]) {
        $ins->execute([$invoiceId, $qty, $price, $rate, $taxCodeId, $lineTotal, $i]);
    }
    return $invoiceId;
}

/** Record + post a receipt exactly like qi/ajax/record_payment.php. Returns the journal id. */
function company_payment(PDO $db, int $cid, int $invoiceId, float $amount): int
{
    $db->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by, exchange_rate)
                  VALUES (?, CURDATE(), ?, 'eft', '', '', 1, 1)")->execute([$cid, $amount]);
    $paymentId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)")
       ->execute([$paymentId, $invoiceId, $amount]);
    (new PostingService($db, $cid, 1))->postCustomerPayment($paymentId);
    return (int)$db->query("SELECT journal_id FROM payments WHERE id = $paymentId")->fetchColumn();
}

function journal_codes(PDO $db, int $journalId, string $side): array
{
    $codes = $db->query("SELECT account_code FROM journal_lines
                          WHERE journal_id = $journalId AND $side > 0 ORDER BY account_code")
                ->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $codes);
}

function account_id(PDO $db, int $cid, string $code): int
{
    $stmt = $db->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = ?");
    $stmt->execute([$cid, $code]);
    return (int)$stmt->fetchColumn();
}

function setting(PDO $db, int $cid, string $key): ?string
{
    $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
    $stmt->execute([$cid, $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

// ---------------------------------------------------------------------------
// 1. The PHP seed mirrors the seed_sars_chart_of_accounts() procedure
// ---------------------------------------------------------------------------
$sql = file_get_contents(FLOWWORK_ROOT . '/Migrations/2026-04-08-coa-sars-upgrade.sql');
preg_match_all('/INSERT INTO `_sars_coa` VALUES(.*?);/s', $sql, $blocks);
$procRows = [];
foreach ($blocks[1] as $block) {
    preg_match_all('/^\s*\((.*)\)\s*,?\s*$/m', $block, $m);
    foreach ($m[1] as $tuple) {
        $vals = array_map('trim', str_getcsv($tuple, ',', "'", '\\'));
        $procRows['c' . $vals[0]] = array_map(fn($v) => $v === 'NULL' ? null : $v, $vals);
    }
}
assert_true(count($procRows) > 150, 'parsed the stored-procedure seed');
$phpRows = [];
foreach (CoaSeed::ACCOUNTS as $r) {
    $phpRows['c' . $r[0]] = array_map(fn($v) => $v === null ? null : (string)$v, $r);
}
$mismatch = [];
foreach ($procRows as $k => $p) {
    if (($phpRows[$k] ?? null) !== $p) {
        $mismatch[] = substr($k, 1);
    }
}
assert_eq('', implode(',', $mismatch), 'CoaSeed rows match the stored procedure');
$extra = array_map(fn($k) => substr($k, 1), array_keys(array_diff_key($phpRows, $procRows)));
assert_eq(json_encode(CoaSeed::EXTRA_CODES), json_encode(array_values($extra)), 'only the documented extra seed codes');
foreach (AccountsMap::DEFAULTS as $key => $code) {
    assert_true(CoaSeed::definition($code) !== null, "seed defines the $key default ($code)");
}

// ---------------------------------------------------------------------------
// 2. Screenshot 1: ZERO inactive on an existing company -> saving a zero-rated line
// ---------------------------------------------------------------------------
$DB->exec("UPDATE gl_tax_codes SET is_active = 0 WHERE company_id = " . TEST_COMPANY_ID . " AND code = 'ZERO'");
$zeroId = (new TaxCodes($DB, TEST_COMPANY_ID))->resolveOutputForLine(null, 'ZERO', 0.0);
assert_true($zeroId > 0, 'zero-rated line resolves after ZERO was deactivated');
assert_eq(1, (int)$DB->query("SELECT is_active FROM gl_tax_codes WHERE tax_code_id = $zeroId")->fetchColumn(),
    'the existing ZERO code was reactivated (not duplicated)');

// ---------------------------------------------------------------------------
// 3. Screenshot 3: legacy chart, no bank mapping, no 1020, ZERO missing
// ---------------------------------------------------------------------------
$legacy = new_company($DB, 'Legacy Chart Co');
$legacyChart = [
    // code, name, type, subtype (as the 2026-04-08 backfill assigns), is_control
    ['1000', 'ASSETS', 'asset', 'current_asset', 0],
    ['1100', 'Current Assets', 'asset', 'current_asset', 0],
    ['1110', 'Bank Accounts', 'asset', 'current_asset', 0],
    ['1120', 'Petty Cash', 'asset', 'current_asset', 0],
    ['1200', 'Accounts Receivable', 'asset', 'accounts_receivable', 1],
    ['1300', 'Inventory', 'asset', 'current_asset', 0],
    ['2000', 'LIABILITIES', 'liability', 'current_liability', 0],
    ['2100', 'Current Liabilities', 'liability', 'current_liability', 0],
    ['2110', 'Accounts Payable', 'liability', 'accounts_payable', 1],
    ['2120', 'VAT Output', 'liability', 'vat_control', 1],
    ['2130', 'VAT Input', 'liability', 'vat_control', 1],
    ['2140', 'PAYE Liability', 'liability', 'paye_liability', 0],
    ['3200', 'Retained Earnings', 'equity', 'retained_earnings', 0],
    ['4100', 'Sales Income', 'revenue', 'operating_revenue', 0],
    ['5000', 'COST OF SALES', 'expense', 'cost_of_sales', 0],
    ['6700', 'Bank Charges', 'expense', 'operating_expense', 0],
    ['6800', 'Depreciation', 'expense', 'operating_expense', 0],
];
$insAcct = $DB->prepare("INSERT INTO gl_accounts
        (company_id, account_code, account_name, account_type, normal_balance, account_subtype,
         is_system, is_control, is_active, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, NOW(), NOW())");
foreach ($legacyChart as [$code, $name, $type, $subtype, $control]) {
    $insAcct->execute([$legacy, $code, $name, $type, in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit', $subtype, $control]);
}
$DB->prepare("INSERT INTO gl_tax_codes (company_id, code, description, rate_percent, type, is_active)
              VALUES (?, 'STD', 'Standard Rate (15%)', 15.00, 'output', 1)")->execute([$legacy]);
$insSet = $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)");
foreach (['finance_ar_account_id' => '1200', 'finance_ap_account_id' => '2110',
          'finance_vat_output_account_id' => '2120', 'finance_vat_input_account_id' => '2130',
          'finance_sales_account_id' => '4100', 'finance_cogs_account_id' => '5000',
          'finance_inventory_account_id' => '1300'] as $key => $code) {
    $insSet->execute([$legacy, $key, (string)account_id($DB, $legacy, $code)]);
}

$cust = company_customer($DB, $legacy);
$inv = company_invoice($DB, $legacy, $cust, [[1, 1000.00, 15, 'STD'], [1, 500.00, 0, 'ZERO']]);
assert_eq(4, (int)$DB->query("SELECT COUNT(*) FROM gl_tax_codes WHERE company_id = $legacy AND is_active = 1")->fetchColumn(),
    'legacy company: missing core tax codes provisioned on save');
InvoiceLifecycle::issueInvoice($DB, $legacy, 1, $inv);
$invJournal = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
assert_balanced_journal($DB, $invJournal, '(legacy invoice)');
assert_eq(['1200'], journal_codes($DB, $invJournal, 'debit'), 'legacy invoice debits legacy AR 1200');
assert_eq(['2120', '4100', '4100'], journal_codes($DB, $invJournal, 'credit'), 'legacy invoice credits 4100 (STD + ZERO groups) and VAT 2120');
$zeroTagged = (int)$DB->query("SELECT COUNT(*) FROM journal_lines jl JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
                                WHERE jl.journal_id = $invJournal AND tc.code = 'ZERO'")->fetchColumn();
assert_eq(1, $zeroTagged, 'zero-rated revenue tagged ZERO (VAT201 box 2)');

$payJournal = company_payment($DB, $legacy, $inv, 1650.00);
assert_balanced_journal($DB, $payJournal, '(legacy payment)');
assert_eq(['1110'], journal_codes($DB, $payJournal, 'debit'), 'receipt debits the legacy bank 1110 (not 1020)');
assert_eq(['1200'], journal_codes($DB, $payJournal, 'credit'), 'receipt relieves legacy AR 1200');
assert_eq(0, account_id($DB, $legacy, '1020'), 'no stray 1020 created on the legacy chart');
assert_eq((string)account_id($DB, $legacy, '1110'), setting($DB, $legacy, 'finance_bank_account_id'),
    'bank mapping pinned to 1110 (visible in Finance Settings)');
assert_true((int)$DB->query("SELECT COUNT(*) FROM audit_log WHERE company_id = $legacy
                              AND action = 'finance_mapping_provisioned'")->fetchColumn() >= 1,
    'auto-mapping is audit-logged');

// Role checks on a legacy chart whose codes mean other things than the seed's.
$map = new AccountsMap($DB, $legacy);
assert_eq('2140', $map->code('finance_paye_account_id'), 'PAYE: default 2130 is VAT Input here -> PAYE Liability 2140');
assert_eq('2101', $map->code('finance_vat_control_account_id'), 'VAT Control: 2100 is a header here -> created at 2101');
assert_eq('VAT Control', $DB->query("SELECT account_name FROM gl_accounts WHERE company_id = $legacy AND account_code = '2101'")->fetchColumn(),
    'VAT Control created from the seed definition');
assert_eq('6801', $map->code('finance_bad_debt_account_id'), 'Bad debts: 6800 is Depreciation here -> created at 6801');
assert_eq('3200', $map->code('finance_retained_earnings_account_id'), 'Retained earnings found by subtype (legacy 3200)');
assert_eq('4100', $map->code('finance_sales_account_id'), 'explicit sales mapping is returned as-is');

// An explicit mapping always wins, even one the resolver would not choose.
$DB->prepare("UPDATE company_settings SET setting_value = ? WHERE company_id = ? AND setting_key = 'finance_sales_account_id'")
   ->execute([(string)account_id($DB, $legacy, '6700'), $legacy]);
assert_eq('6700', $map->code('finance_sales_account_id'), 'explicit mapping is never second-guessed');

// A mapping whose account no longer exists is repaired.
$DB->prepare("UPDATE company_settings SET setting_value = '987654321' WHERE company_id = ? AND setting_key = 'finance_ar_account_id'")
   ->execute([$legacy]);
assert_eq('1200', $map->code('finance_ar_account_id'), 'broken AR mapping (deleted account) -> AR by subtype, not header 1100');
assert_eq((string)account_id($DB, $legacy, '1200'), setting($DB, $legacy, 'finance_ar_account_id'), 'broken AR mapping repaired');

// A Banking -> Accounts entry linked to a GL account is preferred for the bank.
$DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key = 'finance_bank_account_id'")->execute([$legacy]);
$insAcct->execute([$legacy, '1115', 'FNB Business Cheque', 'asset', 'debit', 'current_asset', 0]);
$DB->prepare("INSERT INTO gl_bank_accounts (company_id, name, gl_account_id, currency, is_active, created_at)
              VALUES (?, 'FNB', ?, 'ZAR', 1, NOW())")->execute([$legacy, account_id($DB, $legacy, '1115')]);
assert_eq('1115', $map->code('finance_bank_account_id'), 'bank mapping prefers the GL account linked in Banking -> Accounts');

// ---------------------------------------------------------------------------
// 4. Brand-new company: no tax codes, no chart, no settings
// ---------------------------------------------------------------------------
$fresh = new_company($DB, 'Brand New Co');
$cust = company_customer($DB, $fresh);
$inv = company_invoice($DB, $fresh, $cust, [[2, 250.00, 15, 'STD'], [1, 100.00, 0, 'ZERO'], [1, 80.00, 0, 'EXEMPT']]);
InvoiceLifecycle::issueInvoice($DB, $fresh, 1, $inv);
assert_eq(count(CoaSeed::ACCOUNTS), (int)$DB->query("SELECT COUNT(*) FROM gl_accounts WHERE company_id = $fresh")->fetchColumn(),
    'new company: full SARS chart seeded on first posting');
assert_true((int)$DB->query("SELECT parent_id FROM gl_accounts WHERE company_id = $fresh AND account_code = '1020'")->fetchColumn()
    === account_id($DB, $fresh, '1000'), 'seeded chart parents wired (1020 under 1000)');
$invJournal = (int)$DB->query("SELECT journal_id FROM invoices WHERE id = $inv")->fetchColumn();
assert_balanced_journal($DB, $invJournal, '(new-company invoice)');
assert_eq(['1100'], journal_codes($DB, $invJournal, 'debit'), 'new company invoice debits AR Control 1100');
$payJournal = company_payment($DB, $fresh, $inv, 755.00);
assert_balanced_journal($DB, $payJournal, '(new-company payment)');
assert_eq(['1020'], journal_codes($DB, $payJournal, 'debit'), 'new company receipt debits Primary Bank 1020');
assert_eq(null, setting($DB, $fresh, 'finance_bank_account_id'), 'a valid seed default is used without writing a setting');

// provisionCompany (registration / add company) pins every role to a real account.
$provisioned = new_company($DB, 'Provisioned Co');
FinanceBootstrap::provisionCompany($DB, $provisioned);
$map = new AccountsMap($DB, $provisioned);
$unresolved = [];
foreach (AccountsMap::DEFAULTS as $key => $code) {
    if ($map->explicitCode($key) !== $code) {
        $unresolved[] = $key;
    }
}
assert_eq('', implode(',', $unresolved), 'provisionCompany pins every mapping to its seed account');
assert_eq(4, (int)$DB->query("SELECT COUNT(*) FROM gl_tax_codes WHERE company_id = $provisioned AND is_active = 1")->fetchColumn(),
    'provisionCompany creates the core tax codes');

$DB->rollBack();
test_done('finance_bootstrap');
