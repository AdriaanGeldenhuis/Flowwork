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

UPDATE quote_lines ql
JOIN quotes q            ON q.id = ql.quote_id
JOIN gl_tax_codes tc     ON tc.company_id = q.company_id
                        AND tc.code = IF(ql.tax_rate > 0, 'STD', 'ZERO')
SET ql.tax_code_id = tc.tax_code_id
WHERE ql.tax_code_id IS NULL;

UPDATE recurring_invoice_lines rl
JOIN recurring_invoices r ON r.id = rl.recurring_invoice_id
JOIN gl_tax_codes tc      ON tc.company_id = r.company_id
                         AND tc.code = IF(rl.tax_rate > 0, 'STD', 'ZERO')
SET rl.tax_code_id = tc.tax_code_id
WHERE rl.tax_code_id IS NULL;

-- Seed the manual-journal sequence from the highest existing JNL-#### number
-- so the new FOR UPDATE-locked Sequence continues (not restarts) numbering.
INSERT INTO doc_sequences (company_id, doc_type, period_key, prefix, pad, last_number, updated_at)
SELECT je.company_id, 'JNL', 'ALL', 'JNL-', 4,
       MAX(CAST(SUBSTRING(je.reference, 5) AS UNSIGNED)), NOW()
FROM journal_entries je
WHERE je.reference LIKE 'JNL-%'
  AND NOT EXISTS (
      SELECT 1 FROM doc_sequences ds
      WHERE ds.company_id = je.company_id AND ds.doc_type = 'JNL' AND ds.period_key = 'ALL'
  )
GROUP BY je.company_id;

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
