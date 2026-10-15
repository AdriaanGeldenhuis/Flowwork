-- 2026-10-15 — Mail overhaul
--
-- Brings the mail schema to what the rebuilt /mail module expects. The app
-- also applies exactly these changes itself on first use (mail/lib/MailSchema.php),
-- so running this file is optional; it is idempotent (MariaDB 10.3+ syntax).
--
-- Notable fixes:
--   * emails.uq_emails_message_id was UNIQUE on message_id alone, so a message
--     delivered to two mailboxes (a CC, a newsletter) was stored for only one.
--     It becomes unique per account.
--   * emails.uq_account_folder_uid keyed IMAP UIDs on the *local* folder; UIDs
--     are only unique per *server* folder, so the key moves to imap_folder.
--   * email_links.company_id was referenced by CRM code but missing.

-- ---------------------------------------------------------------- new tables
CREATE TABLE IF NOT EXISTS `email_folder_state` (
  `account_id` bigint(20) NOT NULL,
  `folder` varchar(255) NOT NULL,
  `uidvalidity` bigint(20) DEFAULT NULL,
  `last_uid` bigint(20) NOT NULL DEFAULT 0,
  `initial_done` tinyint(1) NOT NULL DEFAULT 0,
  `last_synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`account_id`,`folder`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_imap_ops` (
  `op_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) NOT NULL,
  `email_id` bigint(20) DEFAULT NULL,
  `imap_folder` varchar(255) DEFAULT NULL,
  `imap_uid` bigint(20) DEFAULT NULL,
  `op` varchar(32) NOT NULL,
  `arg` varchar(512) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`op_id`),
  KEY `idx_ops_account` (`account_id`,`op_id`),
  KEY `idx_ops_email` (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_contacts` (
  `user_id` int(10) NOT NULL,
  `email` varchar(190) NOT NULL,
  `company_id` int(10) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`email`),
  KEY `idx_contacts_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_saved_views` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `filters_json` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_user` (`company_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- new columns
ALTER TABLE `email_accounts`
  ADD COLUMN IF NOT EXISTS `display_name` varchar(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tls_verify` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `save_sent_copy` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `default_signature_id` int(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `folder_map_json` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sync_lock_until` datetime DEFAULT NULL;

ALTER TABLE `email_threads`
  ADD COLUMN IF NOT EXISTS `account_id` bigint(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sla_notified_at` datetime DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_threads_account` (`account_id`);

ALTER TABLE `emails`
  ADD COLUMN IF NOT EXISTS `imap_folder` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `prev_folder` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sender_name` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reply_to` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `list_unsubscribe` varchar(1000) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `size_bytes` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `snoozed_until` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `scheduled_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `send_status` varchar(16) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `send_error` varchar(500) DEFAULT NULL;

ALTER TABLE `email_attachments`
  ADD COLUMN IF NOT EXISTS `content_id` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_inline` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `email_links`
  ADD COLUMN IF NOT EXISTS `company_id` int(10) DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_email_links_company` (`company_id`);

ALTER TABLE `user_mail_prefs`
  ADD COLUMN IF NOT EXISTS `settings_json` longtext DEFAULT NULL;

-- ---------------------------------------------------------------- keys
ALTER TABLE `emails` DROP INDEX IF EXISTS `uq_emails_message_id`;
ALTER TABLE `emails` ADD UNIQUE KEY IF NOT EXISTS `uq_emails_account_msgid` (`account_id`,`message_id`(191));
ALTER TABLE `emails` DROP INDEX IF EXISTS `uq_account_folder_uid`;
ALTER TABLE `emails` ADD UNIQUE KEY IF NOT EXISTS `uq_emails_imap` (`account_id`,`imap_folder`(191),`imap_uid`);

-- ---------------------------------------------------------------- backfill
UPDATE email_links l JOIN emails e ON e.email_id = l.email_id
   SET l.company_id = e.company_id
 WHERE l.company_id IS NULL;

UPDATE email_threads t
  JOIN (SELECT thread_id, MIN(account_id) AS account_id FROM emails
         WHERE thread_id IS NOT NULL AND thread_id > 0 GROUP BY thread_id) x
    ON x.thread_id = t.thread_id
   SET t.account_id = x.account_id
 WHERE t.account_id IS NULL;
