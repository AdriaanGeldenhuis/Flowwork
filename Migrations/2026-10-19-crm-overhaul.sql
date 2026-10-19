-- 2026-10-19 CRM overhaul
--
-- Same result as crm/lib/CrmSchema.php, which applies this automatically the
-- first time a CRM page or API, the supplier portal upload or the background
-- tick (notifications/api/tick.php) runs. Run it by hand only if you want the
-- schema in place before then. Safe to run more than once (MariaDB 10.5+).
-- Nothing is dropped; two columns widen (below) and keep their values.
--
-- 1. New tables:
--      crm_tasks            follow-ups with due date/time, priority, assignee and
--                           a phone/bell reminder (remind_at, reminded_at once sent)
--      crm_pipeline_stages  the company's own deal stages (won and lost always exist)
--      crm_custom_fields    the company's own fields on accounts, contacts and deals
--      crm_custom_values    their values
--      crm_notify_log       which compliance reminder steps were already sent
--      crm_prefs            look and wording for the company (user_id 0) and each
--                           person's own layout
-- 2. New columns:
--      crm_accounts         owner_id (who looks after the account)
--      crm_contacts         mobile, notes
--      crm_interactions     opportunity_id, pinned, updated_at
--      crm_opportunities    contact_id, notes, sort_order (board order), created_by
--      crm_industries,
--      crm_regions          company_id (a company's own entries next to the built-in ones)
--      crm_compliance_docs  original_name (the uploaded file's name)
-- 3. Widened:
--      crm_opportunities.stage  enum → varchar(40), so companies can add stages
--      crm_interactions.type    adds 'meeting' and 'whatsapp'
-- 4. Indexes: deals by account, accounts by owner, activity by deal.
-- 5. Backfill: follow-ups that lived on activity (next_action_at) become tasks,
--    once each (crm_tasks.source_interaction_id is unique).
--
-- Not SQL: crm/lib/CrmSchema.php also writes a deny-all .htaccess into
-- uploads/compliance, uploads/company/<id>/compliance and storage/crm, because
-- the deploy does not copy /uploads or /storage.

-- ---------------------------------------------------------------- 1. tables

CREATE TABLE IF NOT EXISTS `crm_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `opportunity_id` int(10) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `notes` text DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `due_all_day` tinyint(1) NOT NULL DEFAULT 0,
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `done_by` int(10) unsigned DEFAULT NULL,
  `remind_at` datetime DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `source_interaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crm_tasks_open` (`company_id`, `done_at`, `due_at`),
  KEY `idx_crm_tasks_assignee` (`company_id`, `assigned_to`, `done_at`),
  KEY `idx_crm_tasks_account` (`company_id`, `account_id`),
  KEY `idx_crm_tasks_opp` (`company_id`, `opportunity_id`),
  KEY `idx_crm_tasks_remind` (`reminded_at`, `remind_at`),
  UNIQUE KEY `uq_crm_tasks_source` (`source_interaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `stage_key` varchar(40) NOT NULL,
  `label` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#8a7f76',
  `probability` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `kind` enum('open','won','lost') NOT NULL DEFAULT 'open',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crm_stage` (`company_id`, `stage_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_custom_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `entity` enum('account','contact','deal') NOT NULL DEFAULT 'account',
  `applies_to` enum('all','customer','supplier') NOT NULL DEFAULT 'all',
  `field_key` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `field_type` varchar(20) NOT NULL DEFAULT 'text',
  `options_json` text DEFAULT NULL,
  `help` varchar(255) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_list` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crm_field` (`company_id`, `entity`, `field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_custom_values` (
  `field_id` int(10) unsigned NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `company_id` int(10) unsigned NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`field_id`, `entity_id`),
  KEY `idx_crm_cv_entity` (`company_id`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_notify_log` (
  `company_id` int(10) unsigned NOT NULL,
  `kind` varchar(30) NOT NULL,
  `ref_id` bigint(20) unsigned NOT NULL,
  `mark` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`company_id`, `kind`, `ref_id`, `mark`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_prefs` (
  `company_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `prefs_json` mediumtext NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`company_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------- 2. columns
ALTER TABLE `crm_accounts`        ADD COLUMN IF NOT EXISTS `owner_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `crm_contacts`        ADD COLUMN IF NOT EXISTS `mobile` varchar(64) DEFAULT NULL,
                                  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL;
ALTER TABLE `crm_interactions`    ADD COLUMN IF NOT EXISTS `opportunity_id` int(10) unsigned DEFAULT NULL,
                                  ADD COLUMN IF NOT EXISTS `pinned` tinyint(1) NOT NULL DEFAULT 0,
                                  ADD COLUMN IF NOT EXISTS `updated_at` datetime DEFAULT NULL;
ALTER TABLE `crm_opportunities`   ADD COLUMN IF NOT EXISTS `contact_id` bigint(20) unsigned DEFAULT NULL,
                                  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL,
                                  ADD COLUMN IF NOT EXISTS `sort_order` int(11) NOT NULL DEFAULT 0,
                                  ADD COLUMN IF NOT EXISTS `created_by` int(10) unsigned DEFAULT NULL;
ALTER TABLE `crm_industries`      ADD COLUMN IF NOT EXISTS `company_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `crm_regions`         ADD COLUMN IF NOT EXISTS `company_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `crm_compliance_docs` ADD COLUMN IF NOT EXISTS `original_name` varchar(255) DEFAULT NULL;

-- ---------------------------------------------------------------- 3. widened
ALTER TABLE `crm_opportunities` MODIFY `stage` varchar(40) NOT NULL DEFAULT 'prospect';
ALTER TABLE `crm_interactions`  MODIFY `type` enum('email','call','visit','note','issue','meeting','whatsapp') NOT NULL;

-- ---------------------------------------------------------------- 4. indexes
ALTER TABLE `crm_opportunities` ADD INDEX IF NOT EXISTS `idx_crmop_company_account` (`company_id`, `account_id`);
ALTER TABLE `crm_accounts`      ADD INDEX IF NOT EXISTS `idx_crm_accounts_owner` (`company_id`, `owner_id`);
ALTER TABLE `crm_interactions`  ADD INDEX IF NOT EXISTS `idx_crm_interactions_opp` (`company_id`, `opportunity_id`);

-- ---------------------------------------------------------------- 5. backfill
INSERT IGNORE INTO crm_tasks (company_id, account_id, contact_id, title, due_at, assigned_to, created_by, source_interaction_id, created_at)
SELECT i.company_id, i.account_id, i.contact_id,
       LEFT(CONCAT('Follow up: ', COALESCE(NULLIF(TRIM(i.subject), ''), i.type)), 190),
       i.next_action_at, i.created_by, i.created_by, i.id, i.created_at
  FROM crm_interactions i
 WHERE i.next_action_at IS NOT NULL;
