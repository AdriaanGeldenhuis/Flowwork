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
    'ap_match_links', 'vendor_credit_lines', 'vendor_credits', 'vendor_credit_allocations',
    'inventory_movements',
    'gl_vat_periods', 'gl_period_locks',
    'pay_run_employees', 'pay_runs', 'pay_run_lines', 'bank_file_exports',
    'depreciation_lines', 'depreciation_runs',
    'fa_depreciation_lines', 'fa_depreciation_runs', 'gl_fixed_assets', 'fa_disposals',
    'gl_bank_transactions',
    'doc_sequences', 'qi_sequences',
    // Quotes & Invoices documents and the rows that hang off them
    'quote_lines', 'quotes', 'recurring_invoice_lines', 'recurring_invoices', 'yoco_payment_events',
    'qi_line_headings', 'qi_activity', 'qi_email_log', 'payment_milestones', 'milestone_payments',
    // CRM activity, deals, tasks and settings (accounts stay: they are master data)
    'crm_tasks', 'crm_opportunities', 'crm_interactions', 'crm_notify_log', 'crm_merge_candidates',
    'crm_custom_values', 'crm_custom_fields', 'crm_pipeline_stages', 'crm_prefs', 'crm_import_history',
    'audit_log',
    'gl_budgets',
    // Finance: bank workflow, saved settings and supporting documents
    'gl_bank_accounts', 'gl_bank_rules', 'bank_import_batches', 'bank_reconciliations',
    'fin_prefs', 'fin_attachments',
    // Receipts: slips, their readings, check log and the learning memory
    'receipt_policy_log', 'receipt_ocr', 'receipt_file', 'ai_hints',
    // Shopping: lists, their items and trips, links, templates, stores and the price book
    'shopping_actions', 'shopping_candidates', 'shopping_items', 'shopping_sessions', 'shopping_share_tokens', 'shopping_lists',
    'shopping_price_history', 'shopping_templates', 'shopping_store_catalog', 'shopping_stores', 'shopping_preferences',
    // Suppliers AI: searches, what was found and done, and requests for quotes
    'ai_actions', 'ai_candidates', 'ai_queries', 'sai_events', 'sai_request_suppliers', 'sai_requests',
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
