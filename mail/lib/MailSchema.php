<?php
// mail/lib/MailSchema.php
//
// Self-provisioning for the Mail schema. The mail tables were created outside
// the tracked migrations, so production, staging and fresh installs can differ.
// ensure() makes every environment converge on the same shape:
//   - creates any missing table (full definition, matching the production dump),
//   - adds any missing column / index the Mail module relies on,
//   - replaces the global UNIQUE(message_id) on emails (which silently dropped a
//     message when two mailboxes received the same email) with a per-account one,
//   - backfills email_links.company_id.
// It never drops or retypes an existing column. Mirrors
// Migrations/2026-10-15-mail-overhaul.sql. Runs once per SCHEMA_VERSION (a
// marker file under storage/mail/), so the cost is a single file_exists() per
// request after the first run.

class MailSchema
{
    public const SCHEMA_VERSION = '2026-10-15-2';

    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;

        $marker = self::markerPath();
        if ($marker !== null && is_file($marker)) return;
        // After a failure, wait before retrying so a stuck ALTER is not re-run on every request.
        $failed = $marker !== null ? $marker . '.failed' : null;
        if ($failed !== null && is_file($failed) && filemtime($failed) > time() - 600) return;

        // Tables and columns are required; index tuning and backfills are best-effort.
        $ok = true;
        foreach (['createTables' => true, 'addColumns' => true, 'fixIndexes' => false, 'backfill' => false] as $step => $required) {
            try {
                self::$step($db);
            } catch (Throwable $e) {
                // Never take the page down over provisioning.
                error_log('[mail] schema ' . $step . ' failed: ' . $e->getMessage());
                if ($required) $ok = false;
            }
        }
        if ($marker !== null) {
            if ($ok) { @file_put_contents($marker, date('c')); @unlink($failed); }
            else @touch($failed);
        }
    }

    private static function markerPath(): ?string
    {
        $dir = MailPaths::storageDir();
        if ($dir === null) return null;
        return $dir . '/.schema-' . self::SCHEMA_VERSION;
    }

    /** CREATE TABLE IF NOT EXISTS for every mail table. */
    private static function createTables(PDO $db): void
    {
        $tables = [
            "CREATE TABLE IF NOT EXISTS `email_accounts` (
              `account_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `user_id` int(10) NOT NULL,
              `account_name` varchar(120) NOT NULL,
              `display_name` varchar(190) DEFAULT NULL,
              `email_address` varchar(190) NOT NULL,
              `imap_server` varchar(255) DEFAULT NULL,
              `imap_port` int(11) DEFAULT 993,
              `imap_encryption` enum('ssl','tls','none') DEFAULT 'ssl',
              `pop3_server` varchar(255) DEFAULT NULL,
              `pop3_port` int(11) DEFAULT 995,
              `pop3_encryption` enum('ssl','tls','none') DEFAULT 'ssl',
              `smtp_server` varchar(255) DEFAULT NULL,
              `smtp_port` int(11) DEFAULT 587,
              `smtp_encryption` enum('ssl','tls','none') DEFAULT 'tls',
              `username` varchar(190) DEFAULT NULL,
              `password_encrypted` text DEFAULT NULL,
              `oauth_provider` varchar(64) DEFAULT NULL,
              `oauth_token` text DEFAULT NULL,
              `oauth_refresh_token` text DEFAULT NULL,
              `tls_verify` tinyint(1) NOT NULL DEFAULT 1,
              `save_sent_copy` tinyint(1) NOT NULL DEFAULT 1,
              `default_signature_id` int(10) DEFAULT NULL,
              `folder_map_json` text DEFAULT NULL,
              `sync_lock_until` datetime DEFAULT NULL,
              `last_sync_at` datetime DEFAULT NULL,
              `last_error` text DEFAULT NULL,
              `is_active` tinyint(1) DEFAULT 1,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`account_id`),
              KEY `idx_company_user` (`company_id`,`user_id`),
              KEY `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_threads` (
              `thread_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `account_id` bigint(20) DEFAULT NULL,
              `assigned_user_id` int(11) DEFAULT NULL,
              `status` enum('open','pending','closed') NOT NULL DEFAULT 'open',
              `first_incoming_at` datetime DEFAULT NULL,
              `first_response_at` datetime DEFAULT NULL,
              `last_agent_reply_at` datetime DEFAULT NULL,
              `subject` varchar(500) NOT NULL DEFAULT '',
              `subject_norm` varchar(500) NOT NULL DEFAULT '',
              `last_message_at` datetime NOT NULL,
              `is_closed` tinyint(1) DEFAULT 0,
              `sla_due_at` datetime DEFAULT NULL,
              `sla_notified_at` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`thread_id`),
              KEY `idx_company` (`company_id`),
              KEY `idx_last_message` (`last_message_at`),
              KEY `idx_sla` (`sla_due_at`),
              KEY `idx_threads_account` (`account_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `emails` (
              `email_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `account_id` bigint(20) NOT NULL,
              `thread_id` bigint(20) DEFAULT NULL,
              `imap_uid` bigint(20) DEFAULT NULL,
              `imap_folder` varchar(255) DEFAULT NULL,
              `folder` varchar(255) DEFAULT 'INBOX',
              `prev_folder` varchar(64) DEFAULT NULL,
              `direction` enum('incoming','outgoing') DEFAULT 'incoming',
              `sender` varchar(500) DEFAULT NULL,
              `sender_name` varchar(255) DEFAULT NULL,
              `reply_to` varchar(500) DEFAULT NULL,
              `to_recipients` text DEFAULT NULL,
              `cc_recipients` text DEFAULT NULL,
              `bcc_recipients` text DEFAULT NULL,
              `subject` varchar(500) DEFAULT NULL,
              `body_html` mediumtext DEFAULT NULL,
              `body_text` mediumtext DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `is_read` tinyint(1) DEFAULT 0,
              `is_starred` tinyint(1) DEFAULT 0,
              `has_attachments` tinyint(1) DEFAULT 0,
              `message_id` varchar(255) DEFAULT NULL,
              `in_reply_to` varchar(255) DEFAULT NULL,
              `ref_message_ids` text DEFAULT NULL,
              `list_unsubscribe` varchar(1000) DEFAULT NULL,
              `size_bytes` int(11) DEFAULT NULL,
              `snoozed_until` datetime DEFAULT NULL,
              `scheduled_at` datetime DEFAULT NULL,
              `send_status` varchar(16) DEFAULT NULL,
              `send_error` varchar(500) DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`email_id`),
              UNIQUE KEY `uq_emails_account_msgid` (`account_id`,`message_id`(191)),
              UNIQUE KEY `uq_emails_imap` (`account_id`,`imap_folder`(191),`imap_uid`),
              KEY `idx_company` (`company_id`),
              KEY `idx_account_folder` (`account_id`,`folder`),
              KEY `idx_thread` (`thread_id`),
              KEY `idx_message_id` (`message_id`(191)),
              KEY `idx_sent_at` (`sent_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_attachments` (
              `attachment_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `email_id` bigint(20) NOT NULL,
              `file_name` varchar(255) NOT NULL,
              `file_path` varchar(512) NOT NULL,
              `mime_type` varchar(100) DEFAULT NULL,
              `file_size` bigint(20) DEFAULT NULL,
              `content_id` varchar(255) DEFAULT NULL,
              `is_inline` tinyint(1) NOT NULL DEFAULT 0,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`attachment_id`),
              KEY `idx_email` (`email_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_links` (
              `link_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) DEFAULT NULL,
              `email_id` bigint(20) NOT NULL,
              `linked_type` enum('project','board','item','quote','invoice','credit_note','rfq','po','supplier','customer','calendar_event') NOT NULL,
              `linked_id` bigint(20) NOT NULL,
              `created_by` int(10) NOT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`link_id`),
              KEY `idx_email` (`email_id`),
              KEY `idx_linked` (`linked_type`,`linked_id`),
              KEY `idx_email_links_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_rules` (
              `rule_id` int(10) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `user_id` int(10) DEFAULT NULL,
              `name` varchar(120) NOT NULL,
              `priority` int(11) DEFAULT 100,
              `conditions_json` longtext DEFAULT NULL,
              `actions_json` longtext DEFAULT NULL,
              `stop_processing` tinyint(1) DEFAULT 0,
              `is_active` tinyint(1) DEFAULT 1,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`rule_id`),
              KEY `idx_company` (`company_id`),
              KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_send_log` (
              `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `email_id` bigint(20) NOT NULL,
              `status` enum('sent','failed','bounced') DEFAULT 'sent',
              `message` text DEFAULT NULL,
              `sent_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`log_id`),
              KEY `idx_email` (`email_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_signatures` (
              `signature_id` int(10) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `user_id` int(10) DEFAULT NULL,
              `name` varchar(120) NOT NULL,
              `content_html` text DEFAULT NULL,
              `is_default` tinyint(1) DEFAULT 0,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`signature_id`),
              KEY `idx_company_user` (`company_id`,`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_sync_log` (
              `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
              `account_id` bigint(20) NOT NULL,
              `folder` varchar(255) DEFAULT NULL,
              `status` enum('success','error') DEFAULT 'success',
              `message` text DEFAULT NULL,
              `messages_synced` int(11) DEFAULT 0,
              `started_at` datetime DEFAULT current_timestamp(),
              `completed_at` datetime DEFAULT NULL,
              PRIMARY KEY (`log_id`),
              KEY `idx_account` (`account_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_tags` (
              `tag_id` int(10) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `name` varchar(64) NOT NULL,
              `color` varchar(20) DEFAULT '#7b1e2b',
              `color_hex` varchar(7) DEFAULT NULL,
              PRIMARY KEY (`tag_id`),
              UNIQUE KEY `uq_company_name` (`company_id`,`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_tag_map` (
              `email_id` bigint(20) NOT NULL,
              `tag_id` int(10) NOT NULL,
              PRIMARY KEY (`email_id`,`tag_id`),
              KEY `idx_tag` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `email_templates` (
              `template_id` int(10) NOT NULL AUTO_INCREMENT,
              `company_id` int(10) NOT NULL,
              `name` varchar(120) NOT NULL,
              `subject` varchar(500) DEFAULT NULL,
              `body_html` mediumtext DEFAULT NULL,
              `category` varchar(64) DEFAULT NULL,
              `created_by` int(10) NOT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`template_id`),
              KEY `idx_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `user_mail_prefs` (
              `user_id` int(10) NOT NULL,
              `company_id` int(10) NOT NULL,
              `sla_default_hours` int(11) DEFAULT 24,
              `signature_id` int(10) DEFAULT NULL,
              `block_external_images` tinyint(1) DEFAULT 1,
              `notification_enabled` tinyint(1) DEFAULT 1,
              `notify_channels_json` longtext DEFAULT NULL,
              `quiet_hours_json` longtext DEFAULT NULL,
              `settings_json` longtext DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`user_id`),
              KEY `idx_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `mail_saved_views` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `name` varchar(100) NOT NULL,
              `filters_json` text NOT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_company_user` (`company_id`,`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // IMAP sync cursor per server folder.
            "CREATE TABLE IF NOT EXISTS `email_folder_state` (
              `account_id` bigint(20) NOT NULL,
              `folder` varchar(255) NOT NULL,
              `uidvalidity` bigint(20) DEFAULT NULL,
              `last_uid` bigint(20) NOT NULL DEFAULT 0,
              `initial_done` tinyint(1) NOT NULL DEFAULT 0,
              `last_synced_at` datetime DEFAULT NULL,
              PRIMARY KEY (`account_id`,`folder`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Pending server-side changes (flags, moves, deletes, Sent appends),
            // applied at the start of the next sync so the UI never waits on IMAP.
            "CREATE TABLE IF NOT EXISTS `email_imap_ops` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // People you correspond with (recipient autocomplete).
            "CREATE TABLE IF NOT EXISTS `email_contacts` (
              `user_id` int(10) NOT NULL,
              `email` varchar(190) NOT NULL,
              `company_id` int(10) NOT NULL,
              `name` varchar(255) DEFAULT NULL,
              `use_count` int(11) NOT NULL DEFAULT 0,
              `last_used_at` datetime DEFAULT NULL,
              PRIMARY KEY (`user_id`,`email`),
              KEY `idx_contacts_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $sql) {
            $db->exec($sql);
        }
    }

    /** Columns added on top of the production dump, per table. */
    private static function requiredColumns(): array
    {
        return [
            'email_accounts' => [
                'display_name'         => "varchar(190) DEFAULT NULL",
                'tls_verify'           => "tinyint(1) NOT NULL DEFAULT 1",
                'save_sent_copy'       => "tinyint(1) NOT NULL DEFAULT 1",
                'default_signature_id' => "int(10) DEFAULT NULL",
                'folder_map_json'      => "text DEFAULT NULL",
                'sync_lock_until'      => "datetime DEFAULT NULL",
                'last_sync_at'         => "datetime DEFAULT NULL",
                'last_error'           => "text DEFAULT NULL",
            ],
            'email_threads' => [
                'account_id'          => "bigint(20) DEFAULT NULL",
                'first_response_at'   => "datetime DEFAULT NULL",
                'last_agent_reply_at' => "datetime DEFAULT NULL",
                'sla_due_at'          => "datetime DEFAULT NULL",
                'sla_notified_at'     => "datetime DEFAULT NULL",
            ],
            'emails' => [
                'imap_folder'      => "varchar(255) DEFAULT NULL",
                'prev_folder'      => "varchar(64) DEFAULT NULL",
                'sender_name'      => "varchar(255) DEFAULT NULL",
                'reply_to'         => "varchar(500) DEFAULT NULL",
                'is_starred'       => "tinyint(1) DEFAULT 0",
                'has_attachments'  => "tinyint(1) DEFAULT 0",
                'message_id'       => "varchar(255) DEFAULT NULL",
                'in_reply_to'      => "varchar(255) DEFAULT NULL",
                'ref_message_ids'  => "text DEFAULT NULL",
                'list_unsubscribe' => "varchar(1000) DEFAULT NULL",
                'size_bytes'       => "int(11) DEFAULT NULL",
                'snoozed_until'    => "datetime DEFAULT NULL",
                'scheduled_at'     => "datetime DEFAULT NULL",
                'send_status'      => "varchar(16) DEFAULT NULL",
                'send_error'       => "varchar(500) DEFAULT NULL",
            ],
            'email_attachments' => [
                'file_size'  => "bigint(20) DEFAULT NULL",
                'content_id' => "varchar(255) DEFAULT NULL",
                'is_inline'  => "tinyint(1) NOT NULL DEFAULT 0",
            ],
            'email_links' => [
                'company_id' => "int(10) DEFAULT NULL",
            ],
            'email_tags' => [
                'color' => "varchar(20) DEFAULT '#7b1e2b'",
            ],
            'user_mail_prefs' => [
                'signature_id'  => "int(10) DEFAULT NULL",
                'settings_json' => "longtext DEFAULT NULL",
            ],
        ];
    }

    private static function addColumns(PDO $db): void
    {
        $required = self::requiredColumns();
        $existing = self::columnMap($db, array_keys($required));
        foreach ($required as $table => $cols) {
            if (!isset($existing[$table])) continue; // table missing entirely (create failed) — skip
            foreach ($cols as $col => $def) {
                if (isset($existing[$table][$col])) continue;
                $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            }
        }
    }

    private static function fixIndexes(PDO $db): void
    {
        $idx = self::indexMap($db, ['emails', 'email_links', 'email_threads']);
        $db = new class($db) {
            public function __construct(private PDO $db) {}
            public function exec(string $sql): void
            {
                try { $this->db->exec($sql); } catch (Throwable $e) { error_log('[mail] schema index: ' . $e->getMessage()); }
            }
        };

        // A global UNIQUE(message_id) meant a newsletter (or a CC) delivered to
        // two mailboxes was stored for only one of them. Scope it per account.
        $emails = $idx['emails'] ?? [];
        if (isset($emails['uq_emails_message_id']) && $emails['uq_emails_message_id']['unique']
            && $emails['uq_emails_message_id']['cols'] === ['message_id']) {
            $db->exec("ALTER TABLE `emails` DROP INDEX `uq_emails_message_id`");
        }
        if (!isset($emails['uq_emails_account_msgid'])) {
            $db->exec("ALTER TABLE `emails` ADD UNIQUE KEY `uq_emails_account_msgid` (`account_id`,`message_id`(191))");
        }
        // The server copy is identified by (server folder, UID). The old unique key
        // used the *local* folder, which collides once messages move between
        // folders (UIDs are only unique per server folder).
        if (isset($emails['uq_account_folder_uid'])) {
            $db->exec("ALTER TABLE `emails` DROP INDEX `uq_account_folder_uid`");
        }
        if (!isset($emails['uq_emails_imap'])) {
            $db->exec("ALTER TABLE `emails` ADD UNIQUE KEY `uq_emails_imap` (`account_id`,`imap_folder`(191),`imap_uid`)");
        }
        if (!isset($emails['idx_account_folder'])) {
            $db->exec("ALTER TABLE `emails` ADD KEY `idx_account_folder` (`account_id`,`folder`(191))");
        }
        if (!isset($emails['idx_thread'])) {
            $db->exec("ALTER TABLE `emails` ADD KEY `idx_thread` (`thread_id`)");
        }
        if (!isset(($idx['email_links'] ?? [])['idx_email_links_company'])) {
            $db->exec("ALTER TABLE `email_links` ADD KEY `idx_email_links_company` (`company_id`)");
        }
        if (!isset(($idx['email_threads'] ?? [])['idx_threads_account'])) {
            $db->exec("ALTER TABLE `email_threads` ADD KEY `idx_threads_account` (`account_id`)");
        }
    }

    private static function backfill(PDO $db): void
    {
        // Older writers (QI mailer) insert links without company_id.
        $db->exec("UPDATE email_links l JOIN emails e ON e.email_id = l.email_id
                      SET l.company_id = e.company_id
                    WHERE l.company_id IS NULL");
        // Threads created before account_id existed.
        $db->exec("UPDATE email_threads t
                      JOIN (SELECT thread_id, MIN(account_id) AS account_id FROM emails
                             WHERE thread_id IS NOT NULL AND thread_id > 0 GROUP BY thread_id) x
                        ON x.thread_id = t.thread_id
                      SET t.account_id = x.account_id
                    WHERE t.account_id IS NULL");
    }

    /** @return array<string, array<string, true>> table => column => true */
    private static function columnMap(PDO $db, array $tables): array
    {
        $in = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
        $stmt->execute($tables);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['TABLE_NAME']][$r['COLUMN_NAME']] = true;
        }
        return $map;
    }

    /** @return array<string, array<string, array{unique:bool, cols:string[]}>> */
    private static function indexMap(PDO $db, array $tables): array
    {
        $in = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
                                FROM information_schema.STATISTICS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
                            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");
        $stmt->execute($tables);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $r['TABLE_NAME'];
            $i = $r['INDEX_NAME'];
            if (!isset($map[$t][$i])) {
                $map[$t][$i] = ['unique' => (int)$r['NON_UNIQUE'] === 0, 'cols' => []];
            }
            $map[$t][$i]['cols'][] = $r['COLUMN_NAME'];
        }
        return $map;
    }
}
