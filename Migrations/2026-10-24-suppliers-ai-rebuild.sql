-- 2026-10-24 Suppliers AI rebuild
--
-- Same result as suppliers_ai/lib/SaSchema.php, which applies this
-- automatically the first time a Suppliers AI page or API (or the Home page,
-- or the background tick) runs. Run it by hand only if you want the schema in
-- place before then. Safe to run more than once (MariaDB 10.5+). Nothing is
-- dropped and no data is changed.
--
-- 1. New tables:
--      sai_requests            a request for quotes: what, details, quantity, where,
--                              trade, requirements, needed by, quotes by, the budget
--                              (kept inside the company, never sent), status
--                              (draft | open | awarded | closed | cancelled), the
--                              search it came from, the chosen supplier and its order,
--                              and when the background tick last looked for replies
--      sai_request_suppliers   who was asked (a CRM supplier), how (email | whatsapp |
--                              phone | other), the email and its conversation in Mail,
--                              when they replied, declined, their quote (amount, VAT,
--                              lead time, valid until, notes) and the decision
--      sai_events              what happened on a request, for its timeline
-- 2. New columns:
--      ai_queries     location, filters_json, results, external (reached Google or
--                     OpenAI — counted against the hourly and daily ceilings),
--                     ai_used, sources_json (ideas, notes, the place)
--      ai_candidates  source (crm | google), source_ref (Google's place id), rating,
--                     rating_count, maps_url, reasons_json, contact_name, blocked
--      ai_actions     request_id
-- 3. Widened: ai_actions.action enum → varchar(30). The old bulk RFQ wrote
--    'rfq_bulk', which the enum stored as ''.
-- 4. Indexes for the hourly ceiling (searches and AI-written emails) and for opening a search.
--
-- Not SQL: Suppliers AI keeps rate-limit buckets, its schema marker and where
-- places are (30 days) in storage/suppliers_ai, created on demand.

-- ---------------------------------------------------------------- 1. tables
CREATE TABLE IF NOT EXISTS `sai_requests` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(190) NOT NULL,
  `details` text DEFAULT NULL,
  `quantity` varchar(80) DEFAULT NULL,
  `location` varchar(160) DEFAULT NULL,
  `category` varchar(40) DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `needed_by` date DEFAULT NULL,
  `quote_by` date DEFAULT NULL,
  `budget_cents` bigint(20) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `query_id` bigint(20) UNSIGNED DEFAULT NULL,
  `awarded_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `due_notified_at` datetime DEFAULT NULL,
  `replies_checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sai_req_company` (`company_id`, `deleted_at`, `status`),
  KEY `idx_sai_req_owner` (`company_id`, `created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sai_request_suppliers` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `candidate_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `channel` varchar(12) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_by` int(10) UNSIGNED DEFAULT NULL,
  `sent_to` varchar(500) DEFAULT NULL,
  `email_id` bigint(20) DEFAULT NULL,
  `thread_id` bigint(20) DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  `reply_email_id` bigint(20) DEFAULT NULL,
  `reply_notified_at` datetime DEFAULT NULL,
  `declined_at` datetime DEFAULT NULL,
  `quote_cents` bigint(20) DEFAULT NULL,
  `quote_vat` varchar(5) DEFAULT NULL,
  `lead_days` int(11) DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `quote_notes` varchar(500) DEFAULT NULL,
  `quoted_at` datetime DEFAULT NULL,
  `quoted_by` int(10) UNSIGNED DEFAULT NULL,
  `decision` varchar(12) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sai_rs` (`request_id`, `account_id`),
  KEY `idx_sai_rs_account` (`company_id`, `account_id`),
  KEY `idx_sai_rs_thread` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sai_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `recipient_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sai_ev_request` (`request_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- 2. columns
ALTER TABLE `sai_requests`  ADD COLUMN IF NOT EXISTS `requirements` text DEFAULT NULL AFTER `category`,
                            ADD COLUMN IF NOT EXISTS `replies_checked_at` datetime DEFAULT NULL AFTER `due_notified_at`;
ALTER TABLE `ai_queries`    ADD COLUMN IF NOT EXISTS `location` varchar(160) DEFAULT NULL AFTER `q_text`,
                            ADD COLUMN IF NOT EXISTS `filters_json` text DEFAULT NULL AFTER `parsed_json`,
                            ADD COLUMN IF NOT EXISTS `results` int(11) NOT NULL DEFAULT 0 AFTER `took_ms`,
                            ADD COLUMN IF NOT EXISTS `external` tinyint(1) NOT NULL DEFAULT 0 AFTER `results`,
                            ADD COLUMN IF NOT EXISTS `ai_used` tinyint(1) NOT NULL DEFAULT 0 AFTER `external`,
                            ADD COLUMN IF NOT EXISTS `sources_json` text DEFAULT NULL AFTER `ai_used`;
ALTER TABLE `ai_candidates` ADD COLUMN IF NOT EXISTS `source` varchar(20) DEFAULT NULL AFTER `account_id`,
                            ADD COLUMN IF NOT EXISTS `source_ref` varchar(190) DEFAULT NULL AFTER `source`,
                            ADD COLUMN IF NOT EXISTS `rating` decimal(2,1) DEFAULT NULL AFTER `distance_km`,
                            ADD COLUMN IF NOT EXISTS `rating_count` int(11) DEFAULT NULL AFTER `rating`,
                            ADD COLUMN IF NOT EXISTS `maps_url` varchar(500) DEFAULT NULL AFTER `rating_count`,
                            ADD COLUMN IF NOT EXISTS `reasons_json` text DEFAULT NULL AFTER `explanation`,
                            ADD COLUMN IF NOT EXISTS `contact_name` varchar(160) DEFAULT NULL AFTER `reasons_json`,
                            ADD COLUMN IF NOT EXISTS `blocked` tinyint(1) NOT NULL DEFAULT 0 AFTER `contact_name`;
ALTER TABLE `ai_actions`    ADD COLUMN IF NOT EXISTS `request_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `candidate_id`;

-- ---------------------------------------------------------------- 3. widened
ALTER TABLE `ai_actions` MODIFY `action` varchar(30) NOT NULL;

-- ---------------------------------------------------------------- 4. indexes
ALTER TABLE `ai_queries`    ADD INDEX IF NOT EXISTS `idx_sai_external` (`company_id`, `external`, `created_at`);
ALTER TABLE `ai_candidates` ADD INDEX IF NOT EXISTS `idx_sai_query` (`query_id`);
ALTER TABLE `ai_actions`    ADD INDEX IF NOT EXISTS `idx_sai_action` (`company_id`, `action`, `created_at`);
