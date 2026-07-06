-- SARS-readiness remediation — data corrections (idempotent, pre-launch data).

-- Backfill per-line tax codes from the stored rate: 15% -> STD, 0% -> ZERO.
-- (Exempt lines cannot be distinguished from zero-rated retroactively; both
-- were stored as 0%. Zero-rated is the conservative default — the bookkeeper
-- can reclassify via the line editor.)
UPDATE invoice_lines il
JOIN invoices i          ON i.id = il.invoice_id
JOIN gl_tax_codes tc     ON tc.company_id = i.company_id
                        AND tc.code = IF(il.tax_rate > 0, 'STD', 'ZERO')
SET il.tax_code_id = tc.tax_code_id
WHERE il.tax_code_id IS NULL;

UPDATE credit_note_lines cl
JOIN credit_notes cn     ON cn.id = cl.credit_note_id
JOIN gl_tax_codes tc     ON tc.company_id = cn.company_id
                        AND tc.code = IF(cl.tax_rate > 0, 'STD', 'ZERO')
SET cl.tax_code_id = tc.tax_code_id
WHERE cl.tax_code_id IS NULL;

-- Collapse duplicate standard-rate output codes: some companies carry both
-- 'STD' and a legacy 'STANDARD' (same 15% output). Point references at STD,
-- then deactivate the duplicate so pickers show one standard code.
UPDATE journal_lines jl
JOIN gl_tax_codes dup ON dup.tax_code_id = jl.tax_code_id AND dup.code = 'STANDARD'
JOIN gl_tax_codes std ON std.company_id = dup.company_id AND std.code = 'STD'
                     AND std.rate_percent = dup.rate_percent AND std.type = dup.type
SET jl.tax_code_id = std.tax_code_id;

UPDATE gl_accounts a
JOIN gl_tax_codes dup ON dup.tax_code_id = a.tax_code_id AND dup.code = 'STANDARD'
JOIN gl_tax_codes std ON std.company_id = dup.company_id AND std.code = 'STD'
                     AND std.rate_percent = dup.rate_percent AND std.type = dup.type
SET a.tax_code_id = std.tax_code_id;

UPDATE invoice_lines il
JOIN gl_tax_codes dup ON dup.tax_code_id = il.tax_code_id AND dup.code = 'STANDARD'
JOIN gl_tax_codes std ON std.company_id = dup.company_id AND std.code = 'STD'
                     AND std.rate_percent = dup.rate_percent AND std.type = dup.type
SET il.tax_code_id = std.tax_code_id;

UPDATE gl_tax_codes dup
JOIN gl_tax_codes std ON std.company_id = dup.company_id AND std.code = 'STD'
                     AND std.rate_percent = dup.rate_percent AND std.type = dup.type
SET dup.is_active = 0
WHERE dup.code = 'STANDARD';
