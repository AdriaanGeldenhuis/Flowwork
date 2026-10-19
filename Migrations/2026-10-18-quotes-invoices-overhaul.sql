-- 2026-10-18 Quotes & Invoices overhaul
--
-- Same result as qi/lib/QiSchema.php (with qi/lib/LineHeadings.php for the
-- headings table), which applies this automatically the first time a Quotes &
-- Invoices page or API, or the recurring-invoice job, runs. Run it by hand only
-- if you want the schema in place before then. Safe to run more than once
-- (MariaDB 10.5+). Nothing is dropped or retyped.
--
-- 1. New tables: qi_designs (the document designer: one design per company,
--    with the previous one kept for "undo"), qi_activity (the timeline on
--    every quote, invoice, credit note and recurring invoice) and
--    qi_line_headings (section headings between document lines).
-- 2. New columns:
--      quotes              reference, notes to the customer, sent / viewed times
--      invoices            reference, notes to the customer, the customer's link
--                          token, sent / viewed times, the recurring invoice it
--                          came from, the Yoco checkout
--      credit_notes        reference
--      credit_note_lines   ledger account and stock item (kept from the invoice line)
--      recurring_invoices  currency, the day of the month it bills on, email it
--                          automatically, payment terms, the last scheduled attempt
--      qi_settings         number format (prefixes, year, digits, separator),
--                          how long a quote is valid, default notes
-- 3. Indexes: the customer link on invoices (unique) and on quotes, and
--    invoices by recurring invoice.
-- 4. Backfill: recurring invoices remember the day of the month they bill on
--    (updated_at is left as it was). Quotes without a customer link token get
--    one from PHP (random_bytes) the first time Quotes & Invoices opens.

-- ---------------------------------------------------------------- 1. tables

CREATE TABLE IF NOT EXISTS `qi_designs` (
  `company_id` int(10) unsigned NOT NULL,
  `design_json` longtext NOT NULL,
  `previous_json` longtext DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qi_activity` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `doc_type` varchar(16) NOT NULL,
  `doc_id` int(10) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `actor` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qi_activity_doc` (`company_id`, `doc_type`, `doc_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `qi_line_headings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `parent_type` varchar(10) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qlh_parent` (`parent_type`, `parent_id`, `sort_order`),
  KEY `idx_qlh_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- 2. columns

ALTER TABLE `quotes`
  ADD COLUMN IF NOT EXISTS `reference` varchar(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `customer_notes` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sent_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `viewed_at` datetime DEFAULT NULL;

ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `reference` varchar(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `customer_notes` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `public_token` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sent_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `viewed_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recurring_invoice_id` int(10) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `yoco_checkout_id` varchar(100) DEFAULT NULL;

ALTER TABLE `credit_notes`
  ADD COLUMN IF NOT EXISTS `reference` varchar(120) DEFAULT NULL;

ALTER TABLE `credit_note_lines`
  ADD COLUMN IF NOT EXISTS `gl_account_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inventory_item_id` bigint(20) DEFAULT NULL;

ALTER TABLE `recurring_invoices`
  ADD COLUMN IF NOT EXISTS `currency` char(3) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `anchor_day` tinyint(3) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `auto_send` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `payment_terms_days` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_attempt_at` datetime DEFAULT NULL;

ALTER TABLE `qi_settings`
  ADD COLUMN IF NOT EXISTS `quote_prefix` varchar(20) NOT NULL DEFAULT 'Q',
  ADD COLUMN IF NOT EXISTS `invoice_prefix` varchar(20) NOT NULL DEFAULT 'INV',
  ADD COLUMN IF NOT EXISTS `credit_prefix` varchar(20) NOT NULL DEFAULT 'CN',
  ADD COLUMN IF NOT EXISTS `number_year` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `number_digits` tinyint(3) unsigned NOT NULL DEFAULT 4,
  ADD COLUMN IF NOT EXISTS `number_sep` varchar(3) NOT NULL DEFAULT '-',
  ADD COLUMN IF NOT EXISTS `quote_validity_days` int(11) NOT NULL DEFAULT 14,
  ADD COLUMN IF NOT EXISTS `default_notes` text DEFAULT NULL;

-- ---------------------------------------------------------------- 3. indexes

-- An empty token is "no link yet"; empty strings would collide in the unique key.
UPDATE `invoices` SET `public_token` = NULL, `updated_at` = `updated_at` WHERE `public_token` = '';

ALTER TABLE `invoices`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_invoices_public_token` (`public_token`),
  ADD INDEX IF NOT EXISTS `idx_invoices_recurring` (`company_id`, `recurring_invoice_id`);

ALTER TABLE `quotes`
  ADD INDEX IF NOT EXISTS `idx_quotes_public_token` (`public_token`);

-- ---------------------------------------------------------------- 4. backfill

UPDATE `recurring_invoices`
   SET `anchor_day` = DAY(`next_run_date`), `updated_at` = `updated_at`
 WHERE `anchor_day` IS NULL AND `next_run_date` IS NOT NULL AND `next_run_date` > '1000-01-01';
