-- SARS-readiness remediation — schema additions (idempotent, MariaDB 10.11+).
-- See FINANCE-SARS-FINDINGS.md for the findings each change supports.

-- Per-line tax code so zero-rated/exempt supplies are classified exactly
-- (VAT201 boxes are derived from journal_lines.tax_code_id).
ALTER TABLE invoice_lines
  ADD COLUMN IF NOT EXISTS tax_code_id INT UNSIGNED NULL AFTER tax_rate;
ALTER TABLE credit_note_lines
  ADD COLUMN IF NOT EXISTS tax_code_id INT UNSIGNED NULL AFTER tax_rate;

-- Customer receipts: bank account selection, safe double-submit protection,
-- and the payment-date FX rate for realised gain/loss.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS bank_account_id BIGINT UNSIGNED NULL AFTER journal_id,
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL AFTER bank_account_id,
  ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(12,6) NULL AFTER idempotency_key;
ALTER TABLE payments
  ADD UNIQUE INDEX IF NOT EXISTS uq_payments_idem (company_id, idempotency_key);

ALTER TABLE ap_payments
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL AFTER journal_id,
  ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(12,6) NULL AFTER idempotency_key;
ALTER TABLE ap_payments
  ADD UNIQUE INDEX IF NOT EXISTS uq_ap_payments_idem (company_id, idempotency_key);

-- Inventory movements become journal-linked and reversible (repost must not
-- double-issue/receive stock).
ALTER TABLE inventory_movements
  ADD COLUMN IF NOT EXISTS journal_id BIGINT UNSIGNED NULL AFTER ref_id,
  ADD COLUMN IF NOT EXISTS reversal_of_movement_id BIGINT UNSIGNED NULL AFTER journal_id,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER reversal_of_movement_id;
ALTER TABLE inventory_movements
  ADD INDEX IF NOT EXISTS idx_invmov_source (company_id, ref_type, ref_id);

-- VAT accounting basis is stamped on each period at prepare time.
ALTER TABLE gl_vat_periods
  ADD COLUMN IF NOT EXISTS basis VARCHAR(10) NOT NULL DEFAULT 'invoice' AFTER status;

-- Bank statement import de-duplication that tolerates genuinely identical
-- same-day lines (hash includes the occurrence index within the file).
ALTER TABLE gl_bank_transactions
  ADD COLUMN IF NOT EXISTS import_hash CHAR(40) NULL AFTER import_batch_id;
ALTER TABLE gl_bank_transactions
  ADD UNIQUE INDEX IF NOT EXISTS uq_banktx_import_hash (company_id, bank_account_id, import_hash);

-- Payroll postings need an explicit bank account (never "first active").
ALTER TABLE payroll_settings
  ADD COLUMN IF NOT EXISTS default_bank_account_id BIGINT UNSIGNED NULL AFTER default_sdl_gl_code;

-- Minimal SARS wear-and-tear register on fixed assets.
ALTER TABLE gl_fixed_assets
  ADD COLUMN IF NOT EXISTS tax_method ENUM('none','s11e','s12c') NOT NULL DEFAULT 'none' AFTER depreciation_method,
  ADD COLUMN IF NOT EXISTS tax_writeoff_years TINYINT UNSIGNED NULL AFTER tax_method;

-- Part-paid invoices are a first-class status (code already references it).
ALTER TABLE invoices
  MODIFY COLUMN status ENUM('draft','sent','viewed','part-paid','paid','overdue','cancelled') NOT NULL DEFAULT 'draft';

-- Reversal linkage (present in the base schema; ensured here for any
-- environment created from an older dump).
ALTER TABLE journal_entries
  ADD COLUMN IF NOT EXISTS reverses_journal_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS reversed_by_journal_id BIGINT UNSIGNED NULL;
