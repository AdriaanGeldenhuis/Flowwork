<?php
/**
 * Test bootstrap: points the app at the local test database, fakes an
 * authenticated admin session for company 1, and provides tiny assertion +
 * factory helpers. Each test file runs in its own PHP process (see
 * tests/run.php) so state never leaks between tests.
 */

putenv('APP_ENV=testing');
putenv('DB_HOST=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1'));
putenv('DB_NAME=' . (getenv('TEST_DB_NAME') ?: 'flowwork_test'));
putenv('DB_USER=' . (getenv('TEST_DB_USER') ?: 'flowwork_test'));
putenv('DB_PASS=' . (getenv('TEST_DB_PASS') ?: 'flowwork_test_pw'));

define('FLOWWORK_ROOT', dirname(__DIR__, 2));
require FLOWWORK_ROOT . '/db.php'; // defines $DB (PDO)

// Fake an authenticated session (company 1 admin) for code that reads $_SESSION.
$_SESSION['company_id'] = 1;
$_SESSION['user_id']    = 1;
$_SESSION['role']       = 'admin';

const TEST_COMPANY_ID = 1;
const TEST_USER_ID    = 1;

// ---------------------------------------------------------------------------
// Assertions — throw on failure; the runner reports the file + message.
// ---------------------------------------------------------------------------

$GLOBALS['__assert_count'] = 0;

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function assert_true($cond, string $message): void
{
    $GLOBALS['__assert_count']++;
    if (!$cond) {
        fail("ASSERT FAILED: $message");
    }
}

function assert_eq($expected, $actual, string $message): void
{
    assert_true($expected == $actual, "$message (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

/** Compare money values in integer cents regardless of input type. */
function assert_cents($expected, $actual, string $message): void
{
    assert_eq((int)round((float)$expected * 100) / 100 * 100, (int)round((float)$actual * 100), $message . ' [cents]');
}

/** Assert a posted journal exists, has >=2 lines, and debits == credits (cents). */
function assert_balanced_journal(PDO $db, int $journalId, string $context = ''): void
{
    $stmt = $db->prepare("SELECT COUNT(*) c,
        COALESCE(SUM(ROUND(debit*100)),0) d, COALESCE(SUM(ROUND(credit*100)),0) cr
        FROM journal_lines WHERE journal_id = ?");
    $stmt->execute([$journalId]);
    $row = $stmt->fetch();
    assert_true($row['c'] >= 2, "journal $journalId has >=2 lines $context");
    assert_eq((int)$row['d'], (int)$row['cr'], "journal $journalId balanced $context");
}

/** Sum of a GL account's (debits - credits) in cents over posted journals. */
function gl_balance_cents(PDO $db, string $accountCode, int $companyId = TEST_COMPANY_ID): int
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(ROUND(jl.debit*100) - ROUND(jl.credit*100)),0)
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_id
        WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?");
    $stmt->execute([$companyId, $accountCode]);
    return (int)$stmt->fetchColumn();
}

/** Trial-balance snapshot: [account_code => net cents] for posted journals. */
function tb_snapshot(PDO $db, int $companyId = TEST_COMPANY_ID): array
{
    $stmt = $db->prepare("SELECT jl.account_code,
        COALESCE(SUM(ROUND(jl.debit*100) - ROUND(jl.credit*100)),0) net
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_id
        WHERE je.company_id = ? AND je.status = 'posted'
        GROUP BY jl.account_code HAVING net <> 0");
    $stmt->execute([$companyId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['account_code']] = (int)$r['net'];
    }
    ksort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// Factories — minimal rows consistent with the live write paths.
// ---------------------------------------------------------------------------

function make_customer(PDO $db, string $name = 'Test Customer', array $overrides = []): int
{
    $name .= ' ' . bin2hex(random_bytes(4)); // crm_accounts has a unique (company,type,name) key
    $db->prepare("INSERT INTO crm_accounts (company_id, type, name, vat_no, status, created_by, created_at)
                  VALUES (?, 'customer', ?, ?, 'active', ?, NOW())")
       ->execute([TEST_COMPANY_ID, $name, $overrides['vat_no'] ?? '4123456789', TEST_USER_ID]);
    return (int)$db->lastInsertId();
}

function make_supplier(PDO $db, string $name = 'Test Supplier'): int
{
    $name .= ' ' . bin2hex(random_bytes(4));
    $db->prepare("INSERT INTO crm_accounts (company_id, type, name, status, created_by, created_at)
                  VALUES (?, 'supplier', ?, 'active', ?, NOW())")
       ->execute([TEST_COMPANY_ID, $name, TEST_USER_ID]);
    return (int)$db->lastInsertId();
}

/**
 * Insert an invoice + lines the way qi/ajax/save_invoice.php does (post-fix):
 * per-line tax_rate/tax_code_id persisted; header totals computed per line in
 * integer cents. $lines = [[qty, unit_price, tax_rate, description?], ...]
 */
function make_invoice(PDO $db, int $customerId, array $lines, array $overrides = []): int
{
    $subtotalC = 0;
    $taxC = 0;
    foreach ($lines as $l) {
        $netC = (int)round($l[0] * $l[1] * 100);
        $subtotalC += $netC;
        $taxC += (int)round($netC * $l[2] / 100);
    }
    $number = $overrides['invoice_number'] ?? ('TST-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT));
    $db->prepare("INSERT INTO invoices
            (company_id, invoice_number, customer_id, issue_date, due_date, status,
             subtotal, discount, tax, total, balance_due, currency, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())")
       ->execute([
           TEST_COMPANY_ID, $number, $customerId,
           $overrides['issue_date'] ?? date('Y-m-d'),
           $overrides['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
           $overrides['status'] ?? 'draft',
           $subtotalC / 100, $taxC / 100, ($subtotalC + $taxC) / 100, ($subtotalC + $taxC) / 100,
           $overrides['currency'] ?? 'ZAR', TEST_USER_ID,
       ]);
    $invoiceId = (int)$db->lastInsertId();

    $codeByRate = tax_code_ids_by_rate($db);
    $stmt = $db->prepare("INSERT INTO invoice_lines
            (invoice_id, item_description, quantity, unit_price, discount, tax_rate, line_total, sort_order, gl_account_id, tax_code_id, inventory_item_id)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
    foreach ($lines as $i => $l) {
        $netC = (int)round($l[0] * $l[1] * 100);
        $lineTotalC = $netC + (int)round($netC * $l[2] / 100);
        $taxCodeId = isset($l['tax_code'])
            ? tax_code_id_by_code($db, $l['tax_code'])
            : ($codeByRate[(string)(float)$l[2]] ?? null);
        $stmt->execute([
            $invoiceId, $l[3] ?? "Line " . ($i + 1), $l[0], $l[1], $l[2],
            $lineTotalC / 100, $i, $l['gl_account_id'] ?? null,
            $taxCodeId,
            $l['inventory_item_id'] ?? null,
        ]);
    }
    return $invoiceId;
}

function tax_code_id_by_code(PDO $db, string $code): ?int
{
    $stmt = $db->prepare("SELECT tax_code_id FROM gl_tax_codes
        WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([TEST_COMPANY_ID, $code]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/** Map of output tax rates to tax_code_id, e.g. ['15' => 1, '0' => 2]. */
function tax_code_ids_by_rate(PDO $db): array
{
    $stmt = $db->prepare("SELECT tax_code_id, rate_percent, code FROM gl_tax_codes
        WHERE company_id = ? AND is_active = 1 AND type IN ('output','zero','exempt')
        ORDER BY FIELD(code,'STD','ZERO','EXEMPT') DESC");
    $stmt->execute([TEST_COMPANY_ID]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(string)(float)$r['rate_percent']] = (int)$r['tax_code_id'];
    }
    return $map;
}

function test_done(string $name): void
{
    fwrite(STDOUT, "PASS: $name ({$GLOBALS['__assert_count']} assertions)\n");
}
