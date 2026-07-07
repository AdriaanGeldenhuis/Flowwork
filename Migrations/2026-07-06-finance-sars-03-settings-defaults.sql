-- SARS-readiness remediation — settings defaults (idempotent).
-- Account-mapping settings (finance_*_account_id) are seeded by
-- finances/tools/finance_setup.php because they need per-company
-- subtype resolution; this file covers the plain key/value defaults.

-- VAT accounting basis: 'invoice' (accrual) unless the company elects
-- payments basis in Finance Settings.
INSERT INTO company_settings (company_id, setting_key, setting_value)
SELECT c.id, 'finance_vat_basis', 'invoice'
FROM companies c
WHERE NOT EXISTS (
    SELECT 1 FROM company_settings s
    WHERE s.company_id = c.id AND s.setting_key = 'finance_vat_basis'
);

-- Segregation of duties (approver must differ from preparer) — opt-in.
INSERT INTO company_settings (company_id, setting_key, setting_value)
SELECT c.id, 'finance_require_sod', '0'
FROM companies c
WHERE NOT EXISTS (
    SELECT 1 FROM company_settings s
    WHERE s.company_id = c.id AND s.setting_key = 'finance_require_sod'
);

-- NOTE: payroll GL accounts are NOT corrected here with code literals —
-- account codes mean different things on legacy vs seeded charts (on some
-- charts 2130 is VAT Input; on the SARS seed it is PAYE). Payroll postings
-- resolve through the finance_* account mappings seeded per company by
-- finances/tools/finance_setup.php (subtype-based), which is chart-safe.
