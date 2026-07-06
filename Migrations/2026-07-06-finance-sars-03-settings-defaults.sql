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

-- Payroll GL codes: correct legacy defaults that pointed at VAT-control-band
-- codes (2100/2101/2102 — wrong or non-existent in the SARS chart).
UPDATE payroll_settings SET default_wage_gl_code = '6010'
 WHERE default_wage_gl_code IS NULL OR default_wage_gl_code IN ('', '5100');
UPDATE payroll_settings SET default_paye_gl_code = '2130'
 WHERE default_paye_gl_code IS NULL OR default_paye_gl_code IN ('', '2100');
UPDATE payroll_settings SET default_uif_gl_code = '2140'
 WHERE default_uif_gl_code IS NULL OR default_uif_gl_code IN ('', '2101');
UPDATE payroll_settings SET default_sdl_gl_code = '2150'
 WHERE default_sdl_gl_code IS NULL OR default_sdl_gl_code IN ('', '2102');
