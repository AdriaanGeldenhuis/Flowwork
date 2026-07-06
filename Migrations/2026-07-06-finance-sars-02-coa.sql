-- SARS-readiness remediation — chart-of-accounts and tax-code completeness
-- (idempotent). Per-company items that need next-free-code logic (a dedicated
-- net VAT Control account when 2100 is occupied by a legacy header) are done
-- by finances/tools/finance_setup.php, which must be run after migrations.

-- Dedicated Cost of Goods Sold leaf under 5000 for perpetual-inventory COGS
-- postings (the seed chart has no generic COGS leaf).
INSERT INTO gl_accounts
    (company_id, account_code, account_name, description, account_type,
     normal_balance, account_subtype, parent_id, tax_code_id,
     is_system, is_control, is_locked, allow_manual_journal, is_active, afs_line_code)
SELECT c.id, '5150', 'Cost of Goods Sold', 'Perpetual inventory cost of sales',
       'expense', 'debit', 'cost_of_sales', p.account_id, NULL,
       1, 0, 0, 1, 1, 'IS-COS'
FROM companies c
LEFT JOIN gl_accounts p ON p.company_id = c.id AND p.account_code = '5000'
WHERE NOT EXISTS (
    SELECT 1 FROM gl_accounts a
    WHERE a.company_id = c.id AND a.account_code = '5150'
);

-- Every company must have the four core VAT codes.
INSERT INTO gl_tax_codes (company_id, code, description, rate_percent, type, is_active)
SELECT c.id, t.code, t.description, t.rate_percent, t.type, 1
FROM companies c
JOIN (
    SELECT 'STD'    AS code, 'Standard rate output VAT (15%)' AS description, 15.00 AS rate_percent, 'output' AS type
    UNION ALL SELECT 'ZERO',   'Zero-rated output (0%)',              0.00, 'output'
    UNION ALL SELECT 'EXEMPT', 'Exempt supplies',                     0.00, 'exempt'
    UNION ALL SELECT 'INPUT',  'Standard rate input VAT (15%)',      15.00, 'input'
) t
WHERE NOT EXISTS (
    SELECT 1 FROM gl_tax_codes x
    WHERE x.company_id = c.id AND x.code = t.code
);
