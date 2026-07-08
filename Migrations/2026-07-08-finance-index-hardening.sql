-- Finance index & uniqueness hardening (DUE-DILIGENCE-2026-07 finding C3).
--
-- Requires MariaDB 10.11+ (ADD KEY / ADD UNIQUE KEY IF NOT EXISTS). Every
-- statement is idempotent and additive — safe to re-run.
--
-- Scope note: this file adds the missing INDEXES and the vendor_credits
-- uniqueness constraint. The FOREIGN KEYS listed in C3 are intentionally NOT
-- added here — see the "Deferred" block at the bottom for why and for the exact
-- turnkey DDL.

-- =====================================================================
-- Missing hot-path indexes on the AR/credit/depreciation child tables. The
-- base dump created these tables with a PRIMARY KEY only, so every join/lookup
-- on the columns below is a full scan.
-- =====================================================================
ALTER TABLE `fa_depreciation_lines`   ADD KEY IF NOT EXISTS `idx_fa_dep_lines_run`     (`run_id`);
ALTER TABLE `fa_depreciation_lines`   ADD KEY IF NOT EXISTS `idx_fa_dep_lines_asset`   (`asset_id`);
ALTER TABLE `vendor_credit_lines`     ADD KEY IF NOT EXISTS `idx_vcl_credit`           (`credit_id`);
ALTER TABLE `vendor_credit_allocations` ADD KEY IF NOT EXISTS `idx_vca_credit_bill`    (`credit_id`, `bill_id`);

-- =====================================================================
-- Vendor-credit numbers must be unique per company (mirrors the doc-sequence
-- uniqueness already enforced on invoices/quotes/credit notes). If a production
-- database already holds duplicate (company_id, credit_number) rows this ADD
-- fails soft (the migration is SKIPped by tests/db/setup.sh); dedupe first, then
-- re-run. No duplicates exist in the base dump.
-- =====================================================================
ALTER TABLE `vendor_credits` ADD UNIQUE KEY IF NOT EXISTS `uq_vendor_credit_number` (`company_id`, `credit_number`);

-- =====================================================================
-- Deferred — foreign keys (C3, not applied here):
--   invoice_lines(invoice_id)            -> invoices(id)
--   payment_allocations(payment_id)      -> payments(id)
--   payment_allocations(invoice_id)      -> invoices(id)
--   credit_note_lines(credit_note_id)    -> credit_notes(id)
--   credit_note_allocations(credit_note_id) -> credit_notes(id)
--   credit_note_allocations(invoice_id)  -> invoices(id)
--   vendor_credit_lines(credit_id)       -> vendor_credits(id)
--   vendor_credit_allocations(credit_id) -> vendor_credits(id)
--   vendor_credit_allocations(bill_id)   -> ap_bills(id)
--
-- Deferred deliberately, not overlooked:
--   1. MariaDB 10.11 has NO "ADD CONSTRAINT ... FOREIGN KEY IF NOT EXISTS"
--      (verified — it is a syntax error), so idempotent FK adds need an
--      information_schema guard / stored procedure rather than plain DDL.
--   2. Signedness mismatches block errno 150 even with foreign_key_checks off:
--      credit_note_allocations.{credit_note_id,invoice_id} are int(11) SIGNED
--      while invoices.id/credit_notes.id are int(10) UNSIGNED — each such column
--      must be MODIFYed to match the parent (checking for NULL/negative rows)
--      before the FK is added.
--   3. ON DELETE CASCADE changes deletion semantics on live financial data and
--      warrants its own review + test pass, separate from the critical
--      money-correctness fixes this migration set ships alongside.
-- The indexes above already back these columns, so the FK follow-up only needs
-- the constraint + the per-column type reconciliation.
-- =====================================================================
