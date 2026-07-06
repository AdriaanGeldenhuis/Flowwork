<?php
/**
 * Reset transactional data in the flowwork_test database between tests while
 * keeping master data (companies, users, chart of accounts, tax codes,
 * settings, inventory items).
 */
require __DIR__ . '/../lib/bootstrap.php';

$tables = [
    'journal_lines', 'journal_entries',
    'invoice_lines', 'invoices',
    'credit_note_lines', 'credit_note_allocations', 'credit_notes',
    'payment_allocations', 'payments',
    'ap_bill_lines', 'ap_bills', 'ap_payment_allocations', 'ap_payments',
    'ap_match_links', 'vendor_credits', 'vendor_credit_allocations',
    'inventory_movements',
    'gl_vat_periods', 'gl_period_locks',
    'pay_run_employees', 'pay_runs',
    'depreciation_lines', 'depreciation_runs',
    'fa_depreciation_lines', 'fa_depreciation_runs', 'gl_fixed_assets', 'fa_disposals',
    'bank_transactions', 'bank_import_batches',
    'doc_sequences', 'qi_sequences',
    'audit_log',
    'gl_budgets',
];

$DB->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    try {
        $DB->exec("TRUNCATE TABLE `$t`");
    } catch (PDOException $e) {
        // Table may not exist in this schema revision — fine.
    }
}
$DB->exec('SET FOREIGN_KEY_CHECKS = 1');
exit(0);
