<?php
/** Smoke test: harness connects, schema is loaded, COA + tax codes seeded. */
require __DIR__ . '/../lib/bootstrap.php';

assert_true($DB instanceof PDO, 'DB connection established');

foreach (['journal_entries', 'journal_lines', 'gl_accounts', 'gl_tax_codes',
          'invoices', 'invoice_lines', 'payments', 'ap_bills', 'gl_vat_periods',
          'gl_period_locks', 'audit_log', 'companies'] as $table) {
    $DB->query("SELECT 1 FROM `$table` LIMIT 1");
    assert_true(true, "table $table exists");
}

$n = (int)$DB->query("SELECT COUNT(*) FROM gl_accounts WHERE company_id = 1 AND is_active = 1")->fetchColumn();
assert_true($n > 100, "SARS chart of accounts seeded ($n accounts)");

$codes = $DB->query("SELECT code FROM gl_tax_codes WHERE company_id = 1 AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
foreach (['STD', 'ZERO', 'EXEMPT', 'INPUT'] as $code) {
    assert_true(in_array($code, $codes, true), "tax code $code present");
}

// Factories work and transactional state is clean (reset ran before us).
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(), 'journals reset');
$cust = make_customer($DB);
$inv = make_invoice($DB, $cust, [[2, 100.00, 15], [1, 50.00, 0]]);
$row = $DB->query("SELECT subtotal, tax, total FROM invoices WHERE id = $inv")->fetch();
assert_cents(250.00, $row['subtotal'], 'invoice subtotal');
assert_cents(30.00, $row['tax'], 'invoice tax (15% on 200 only)');
assert_cents(280.00, $row['total'], 'invoice total');

test_done('smoke_db');
