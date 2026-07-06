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

-- The application sets several statuses that were never added to the enum
-- (part-paid, written_off, refunded, uncollectible) — non-strict MariaDB was
-- silently storing them as ''. Make every status the code writes legal.
ALTER TABLE invoices
  MODIFY COLUMN status ENUM('draft','sent','viewed','part-paid','paid','overdue',
                            'cancelled','written_off','refunded','uncollectible')
         NOT NULL DEFAULT 'draft';

-- Reversal linkage (present in the base schema; ensured here for any
-- environment created from an older dump).
ALTER TABLE journal_entries
  ADD COLUMN IF NOT EXISTS reverses_journal_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS reversed_by_journal_id BIGINT UNSIGNED NULL;

-- source_type was ENUM('invoice','payment','receipt','manual') but the code
-- has always written richer values (ap_bill, credit_note, vendor_credit,
-- payroll, reversal, vat_adjustment...) which non-strict MariaDB silently
-- stored as '' — breaking source-document traceability. Widen to VARCHAR.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type VARCHAR(50) NULL;

-- ---------------------------------------------------------------------------
-- Schema-drift reconciliation: columns the application writes that exist on
-- the production database but have no migration in the repo (the base dump
-- predates them). No-ops where the column already exists.
-- ---------------------------------------------------------------------------
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(12,6) NULL AFTER currency,
  ADD COLUMN IF NOT EXISTS has_milestones TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
  ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS archived_by INT NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS write_off_amount DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS write_off_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS write_off_by INT NULL;
ALTER TABLE credit_notes
  ADD COLUMN IF NOT EXISTS currency CHAR(3) NULL DEFAULT 'ZAR' AFTER total,
  ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(12,6) NULL AFTER currency;
ALTER TABLE quotes
  ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(12,6) NULL,
  ADD COLUMN IF NOT EXISTS has_milestones TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS accepted_ip VARCHAR(45) NULL;
ALTER TABLE recurring_invoices
  ADD COLUMN IF NOT EXISTS template_name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS terms TEXT NULL,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL;
