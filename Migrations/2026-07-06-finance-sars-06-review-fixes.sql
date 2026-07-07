-- Finance SARS remediation — PR review fixes.
--
-- 1. fa_depreciation_runs had NO unique key on (company_id, run_month): two
--    concurrent "Run depreciation" clicks both passed the (separately
--    committed) existence check and each posted a full depreciation journal,
--    doubling the month's expense. The endpoint now locks + retries; this
--    index is the database-level backstop.
--    Duplicate DRAFT runs (posted-less leftovers) are removed first, keeping
--    the lowest id per month. Duplicate POSTED runs are NOT auto-removed —
--    if the ALTER fails, an operator must reverse one of the runs' journals.
DELETE r1 FROM fa_depreciation_runs r1
  JOIN fa_depreciation_runs r2
    ON r2.company_id = r1.company_id
   AND r2.run_month = r1.run_month
   AND r2.id < r1.id
 WHERE r1.status = 'draft'
   AND r1.journal_id IS NULL;

ALTER TABLE fa_depreciation_runs
  ADD UNIQUE INDEX IF NOT EXISTS uq_fa_dep_run_month (company_id, run_month);

-- 2. Repair blank invoices.status rows. The pre-widening ENUM silently
--    truncated unknown statuses (e.g. 'part-paid') to '' under non-strict
--    SQL mode; the ENUM widening in migration 01 keeps such rows unchanged.
--    Re-derive the status from the amounts, which were written correctly.
UPDATE invoices
   SET status = CASE
        WHEN COALESCE(write_off_amount, 0) > 0 AND balance_due <= 0.005 THEN 'written_off'
        WHEN balance_due <= 0.005 AND total > 0 THEN 'paid'
        WHEN balance_due < total THEN 'part-paid'
        ELSE 'sent'
    END
 WHERE status = '' OR status IS NULL;
