-- 2026-10-19 Finance overhaul
--
-- Same result as finances/lib/FinSchema.php, which applies this automatically
-- the first time a Finance page or API runs. Run it by hand only if you want
-- the schema in place before then. Safe to run more than once (MariaDB 10.3+ /
-- MySQL 8). Nothing is dropped or altered in place; no posted data changes.
--
-- 1. New tables:
--      fin_prefs            look, wording, parts, layout, thresholds and rules for
--                           the company (user_id 0) and each person's own layout
--      fin_attachments      supporting documents for journals, bills, supplier
--                           credits, assets and bank lines (files themselves live
--                           in storage/finance/<company>/<year>/, random names)
--      bank_reconciliations reconciliation history (already in the original schema;
--                           created only where it is missing)
-- 2. Index: audit_log (company_id, entity_type, entity_id) — the journal audit
--    trail reads per journal.
--
-- No data migration is needed: existing journals, bills, credits, payments,
-- VAT periods, locks and assets are read as they are. VAT adjustments posted by
-- the old screen against VAT Control stay as posted; the VAT period screen
-- shows their amount so a correcting journal can be made.
--
-- Not SQL: FinSchema also writes a deny-all .htaccess into storage/finance,
-- storage/bank_statements and uploads/bank_statements (the deploy does not copy
-- /uploads or /storage). finances/lib and finances/partials carry their own
-- deny-all .htaccess in the repository.

-- ---------------------------------------------------------------- 1. tables

CREATE TABLE IF NOT EXISTS `fin_prefs` (
  `company_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `prefs_json` mediumtext NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`company_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `entity` varchar(20) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_att_entity` (`company_id`, `entity`, `entity_id`),
  UNIQUE KEY `uq_fin_att_stored` (`stored_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `statement_start` date NOT NULL,
  `statement_end` date NOT NULL,
  `opening_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `closing_balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by` int(10) unsigned DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_period` (`bank_account_id`, `statement_start`, `statement_end`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- 2. index

SET @fin_has_idx := (SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema = DATABASE() AND table_name = 'audit_log' AND index_name = 'idx_audit_entity');
SET @fin_sql := IF(@fin_has_idx = 0,
                   'ALTER TABLE `audit_log` ADD KEY `idx_audit_entity` (`company_id`, `entity_type`, `entity_id`)',
                   'SELECT 1');
PREPARE fin_stmt FROM @fin_sql;
EXECUTE fin_stmt;
DEALLOCATE PREPARE fin_stmt;
