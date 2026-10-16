-- Phone notifications for the Flowwork Android app, and the in-app
-- notifications table every module already writes to but that no migration
-- ever created (inserts failed silently where it was missing).
--
-- The app also creates these tables on first use (includes/notify.php,
-- includes/push.php), so running this file is optional. Safe to run more
-- than once.
--
-- If `notifications` already exists from a hand-made copy, compare it with
-- the definition below: the code needs url, link, is_read, read_at and
-- created_at (missing columns are added automatically on first use too).

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'general',
  `title` varchar(255) NOT NULL DEFAULT '',
  `message` varchar(500) NOT NULL DEFAULT '',
  `url` varchar(500) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`company_id`, `user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per app install, bound to the signed-in device row it runs on
-- (user_sessions.id), so signing a device out stops its notifications.
CREATE TABLE IF NOT EXISTS `push_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_id` bigint(20) unsigned DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `token` text NOT NULL,
  `platform` varchar(16) NOT NULL DEFAULT 'android',
  `app_version` varchar(20) DEFAULT NULL,
  `device_label` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_sent_at` datetime DEFAULT NULL,
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_devices_token` (`token_hash`),
  KEY `idx_push_devices_user` (`user_id`, `disabled_at`),
  KEY `idx_push_devices_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue of pushes waiting to be sent (and retried) by
-- notifications/worker/send_push.php.
CREATE TABLE IF NOT EXISTS `push_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `payload` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `send_after` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `claim_token` char(32) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_count` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_push_outbox_due` (`sent_at`, `send_after`),
  KEY `idx_push_outbox_claim` (`claim_token`),
  KEY `idx_push_outbox_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
