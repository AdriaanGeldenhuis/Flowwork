-- Drive single sign-on: one-time tokens /drive/ issues so FlowDrive
-- (flowdrive.co.za, same database) opens already signed in.
-- See includes/flowdrive/FlowDriveSso.php. Created on first use as well,
-- so running this file is optional. Same as FlowDrive's 2026_10_add_sso_tokens.sql.

CREATE TABLE IF NOT EXISTS `fd_sso_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the one-time token',
  `state` char(32) NOT NULL COMMENT 'Random value Drive keeps in the browser session',
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL COMMENT 'Active Flowwork company at hand-over',
  `auth_sid` bigint(20) unsigned DEFAULT NULL COMMENT 'Flowwork user_sessions.id of the device',
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fd_sso_token` (`token_hash`),
  KEY `idx_fd_sso_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
