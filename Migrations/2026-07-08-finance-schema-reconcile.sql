-- Finance schema reconciliation (DUE-DILIGENCE-2026-07 findings C1 + C2)
--
-- Requires MariaDB 10.11+ (uses ADD COLUMN/INDEX IF NOT EXISTS and CREATE TABLE
-- IF NOT EXISTS). Safe to re-run: every statement is idempotent.
--
-- Background: six migrations under qi/migrations/ were never wired into any
-- tooling (tests/db/setup.sh only globs /Migrations/*.sql), so the objects they
-- define are present in production (applied by hand) but absent from the tracked
-- schema. The 2026-07-06 finance-sars remediation ported MOST of them
-- (invoices/quotes exchange_rate + has_milestones, credit_notes currency +
-- exchange_rate, the invoices delete/write-off/archive columns and the widened
-- invoices.status enum) but NOT the objects reconciled below. Any fresh
-- environment, DR rebuild, new tenant, or the finance test suite fatals on the
-- customer-document and refund flows without them.
--
-- This file ports ONLY the genuinely-missing objects; it deliberately does not
-- re-add anything finance-sars-01 already covers (exchange_rate/currency columns,
-- the status enum, has_milestones), to avoid type conflicts with that migration.

-- =====================================================================
-- C1 (a) — Payment milestones (from qi/migrations/add_milestones.sql).
-- Used by qi/lib/MilestoneAllocator.php, save_invoice/save_quote, the PDF
-- generators and 10+ other qi files. has_milestones on quotes/invoices is
-- already added by finance-sars-01, so it is intentionally omitted here.
-- =====================================================================
CREATE TABLE IF NOT EXISTS payment_milestones (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('quote','invoice') NOT NULL,
    entity_id INT(10) UNSIGNED NOT NULL,
    company_id INT(10) UNSIGNED NOT NULL,
    label VARCHAR(190) NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','due','paid','overdue') DEFAULT 'pending',
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    sort_order INT(11) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS milestone_payments (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    milestone_id INT(10) UNSIGNED NOT NULL,
    payment_id INT(10) UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_milestone (milestone_id),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- C1 (b) — Refund tracking on payments (from add_invoice_delete_options.sql).
-- REQUIRED by qi/ajax/refund_invoice.php (the customer-refund flow flags the
-- original receipt with these columns). Absent from the tracked schema.
-- =====================================================================
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `refunded_at` DATETIME DEFAULT NULL;
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `refunded_by` INT(10) UNSIGNED DEFAULT NULL;
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `refund_reference` VARCHAR(190) DEFAULT NULL;

-- refund_invoice.php records the refund payment with method='refund', but the
-- tracked enum never included it, so the INSERT failed on any DB provisioned
-- from the base dump. Add it (MODIFY is idempotent). Keep every existing value.
ALTER TABLE `payments`
    MODIFY `method` ENUM('card','eft','cash','cheque','yoco','other','refund') NOT NULL DEFAULT 'eft';

-- =====================================================================
-- C1 (c) — Per-customer + per-company currency (from add_customer_currency.sql
-- and add_multi_currency.sql step 4). NULL customer currency = company default.
-- =====================================================================
ALTER TABLE `crm_accounts` ADD COLUMN IF NOT EXISTS `currency` CHAR(3) DEFAULT NULL
    COMMENT 'Preferred billing currency (NULL = company default)';
ALTER TABLE `qi_settings` ADD COLUMN IF NOT EXISTS `default_currency` CHAR(3) NOT NULL DEFAULT 'ZAR';

-- =====================================================================
-- C1 (d) — Deep branding columns for quote/invoice/credit-note views + PDFs
-- (from add_branding_columns.sql). Referenced by qi/lib/Branding.php,
-- generate_pdf.php, credit_note_view.php, quote/invoice views, etc.
-- AFTER clauses are dropped: column order is cosmetic and per-column
-- IF NOT EXISTS keeps this idempotent.
-- =====================================================================
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_heading_color` VARCHAR(7) DEFAULT NULL;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_text_color` VARCHAR(7) DEFAULT NULL;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_table_header_text` VARCHAR(7) DEFAULT '#ffffff';
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_bg_color` VARCHAR(7) DEFAULT NULL;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_border_radius` TINYINT UNSIGNED DEFAULT 8;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_logo_size` SMALLINT UNSIGNED DEFAULT 200;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_logo_position` ENUM('left','center','right') DEFAULT 'left';
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_quote_title` VARCHAR(50) DEFAULT 'QUOTATION';
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_invoice_title` VARCHAR(50) DEFAULT 'INVOICE';
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_show_payment_details` TINYINT(1) DEFAULT 1;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `qi_custom_css` TEXT DEFAULT NULL;

-- C1 (e) — 'corporate' template option (from add_corporate_template.sql).
-- MODIFY is naturally idempotent (re-running sets the same enum).
ALTER TABLE `companies`
    MODIFY `qi_template` ENUM('modern','classic','minimal','bold','corporate') DEFAULT 'modern';

-- =====================================================================
-- C2 — exchange_rate backfill. finance-sars-01 added exchange_rate to these five
-- money tables as DECIMAL(12,6) NULL with no DEFAULT and no backfill, so every
-- pre-migration row (and payments/ap_payments for ALL rows, since no earlier
-- migration ever defined those two) reads NULL. The dashboard AR KPIs multiply
-- balance_due * exchange_rate, and a NULL rate makes the row NULL so SUM drops it
-- (see finances/index.php, hardened separately). Backfill NULL/0 -> 1.
-- (foreign-currency docs -> 1 is the same approximation the original
-- add_multi_currency.sql made, and is strictly better than a silent-zero KPI.)
-- =====================================================================
UPDATE `invoices`     SET `exchange_rate` = 1 WHERE `exchange_rate` IS NULL OR `exchange_rate` = 0;
UPDATE `quotes`       SET `exchange_rate` = 1 WHERE `exchange_rate` IS NULL OR `exchange_rate` = 0;
UPDATE `credit_notes` SET `exchange_rate` = 1 WHERE `exchange_rate` IS NULL OR `exchange_rate` = 0;
UPDATE `payments`     SET `exchange_rate` = 1 WHERE `exchange_rate` IS NULL OR `exchange_rate` = 0;
UPDATE `ap_payments`  SET `exchange_rate` = 1 WHERE `exchange_rate` IS NULL OR `exchange_rate` = 0;
UPDATE `credit_notes` SET `currency` = 'ZAR' WHERE `currency` IS NULL OR `currency` = '';
