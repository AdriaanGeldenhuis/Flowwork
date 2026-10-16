<?php
// calendar/lib/CalendarSchema.php
//
// Self-provisioning for the Calendar schema. The calendar tables were created
// outside the tracked migrations, so environments can differ. ensure() makes
// every environment converge on the same shape:
//   - creates any missing table (fresh installs),
//   - converts the tables from latin1 to utf8mb4 (latin1 rejected emoji, "✓"
//     and any non-Latin-1 text, which also made the old invoice/board syncs fail),
//   - adds the columns the Calendar relies on (ICS UID, series links,
//     recurring-reminder bookkeeping, per-user preferences),
//   - widens calendar_event_links.linked_type with the CRM opportunity and pay
//     run targets (CRM follow-ups used values the enum rejected).
// It never drops or retypes an existing column. Mirrors
// Migrations/2026-10-17-calendar-overhaul.sql. Runs once per SCHEMA_VERSION (a
// marker file under storage/calendar/), so the cost is a single file_exists()
// per request after the first run.

class CalendarSchema
{
    public const SCHEMA_VERSION = '2026-10-17-1';

    public const LINK_TYPES = ['project', 'board', 'item', 'quote', 'invoice', 'rfq', 'po', 'email',
                               'crm_account', 'crm_interaction', 'crm_opportunity', 'pay_run'];

    private const TABLES = ['calendars', 'calendar_events', 'calendar_event_participants', 'calendar_event_reminders',
                            'calendar_event_links', 'calendar_event_attachments', 'calendar_settings'];

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

        $ok = true;
        foreach (['createTables' => true, 'convertCharset' => true, 'addColumns' => true,
                  'extendEnums' => true, 'addIndexes' => false] as $step => $required) {
            try {
                self::$step($db);
            } catch (Throwable $e) {
                // Never take the page down over provisioning.
                error_log('[calendar] schema ' . $step . ' failed: ' . $e->getMessage());
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
        $dir = CalendarPaths::storageDir();
        return $dir === null ? null : $dir . '/.schema-' . self::SCHEMA_VERSION;
    }

    private static function linkEnum(): string
    {
        return "enum('" . implode("','", self::LINK_TYPES) . "')";
    }

    private static function createTables(PDO $db): void
    {
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE IF NOT EXISTS `calendars` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `calendar_type` enum('personal','team','project','resource') NOT NULL DEFAULT 'personal',
              `name` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `color` varchar(7) DEFAULT '#06b6d4',
              `owner_id` int(11) DEFAULT NULL,
              `project_id` int(11) DEFAULT NULL,
              `sharing_json` longtext DEFAULT NULL COMMENT 'JSON array of {user_id, role: view/edit}',
              `is_active` tinyint(1) DEFAULT 1,
              `ics_token` varchar(64) DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `ics_token` (`ics_token`),
              KEY `idx_company` (`company_id`),
              KEY `idx_owner` (`owner_id`),
              KEY `idx_project` (`project_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_events` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `calendar_id` bigint(20) NOT NULL,
              `title` varchar(500) NOT NULL,
              `description` longtext DEFAULT NULL,
              `location` varchar(255) DEFAULT NULL,
              `lat` decimal(10,7) DEFAULT NULL,
              `lng` decimal(10,7) DEFAULT NULL,
              `color` varchar(7) DEFAULT NULL,
              `start_datetime` datetime NOT NULL,
              `end_datetime` datetime NOT NULL,
              `all_day` tinyint(1) DEFAULT 0,
              `recurrence` varchar(500) DEFAULT NULL COMMENT 'RRULE string',
              `exdates_json` longtext DEFAULT NULL COMMENT 'Exception dates array',
              `visibility` enum('default','busy','private') DEFAULT 'default',
              `ics_uid` varchar(255) DEFAULT NULL,
              `series_id` bigint(20) DEFAULT NULL,
              `recurrence_id` datetime DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `updated_by` int(11) DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_calendar` (`calendar_id`),
              KEY `idx_company_time` (`company_id`,`start_datetime`,`end_datetime`),
              KEY `idx_created_by` (`created_by`),
              CONSTRAINT `calendar_events_ibfk_2` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_event_participants` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `event_id` bigint(20) NOT NULL,
              `user_id` int(11) NOT NULL,
              `role` enum('organizer','required','optional') DEFAULT 'required',
              `response` enum('pending','accepted','declined','tentative') DEFAULT 'pending',
              `response_at` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `unique_participant` (`event_id`,`user_id`),
              KEY `idx_user` (`user_id`),
              CONSTRAINT `calendar_event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_event_reminders` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `event_id` bigint(20) NOT NULL,
              `user_id` int(11) NOT NULL,
              `minutes_before` int(11) NOT NULL,
              `channel` enum('in_app','email') DEFAULT 'in_app',
              `sent_at` datetime DEFAULT NULL,
              `snoozed_until` datetime DEFAULT NULL,
              `last_occurrence` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_event` (`event_id`),
              KEY `idx_user` (`user_id`),
              CONSTRAINT `calendar_event_reminders_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_event_links` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `event_id` bigint(20) NOT NULL,
              `linked_type` " . self::linkEnum() . " NOT NULL,
              `linked_id` bigint(20) NOT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_event` (`event_id`),
              KEY `idx_link` (`linked_type`,`linked_id`),
              CONSTRAINT `calendar_event_links_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_event_attachments` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `event_id` bigint(20) NOT NULL,
              `file_name` varchar(255) NOT NULL,
              `file_path` varchar(512) NOT NULL,
              `file_size` bigint(20) DEFAULT NULL,
              `mime_type` varchar(100) DEFAULT NULL,
              `uploaded_by` int(11) NOT NULL,
              `uploaded_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_event` (`event_id`),
              CONSTRAINT `calendar_event_attachments_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `calendar_settings` (
              `id` bigint(20) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `timezone` varchar(64) DEFAULT 'Africa/Johannesburg',
              `week_start` tinyint(4) DEFAULT 1 COMMENT '0=Sun,1=Mon',
              `work_hours_start` time DEFAULT '08:00:00',
              `work_hours_end` time DEFAULT '17:00:00',
              `default_reminder_minutes` int(11) DEFAULT 15,
              `default_view` varchar(20) DEFAULT 'week',
              `enable_invoice_due` tinyint(1) NOT NULL DEFAULT 1,
              `enable_project_dates` tinyint(1) NOT NULL DEFAULT 1,
              `prefs_json` longtext DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `unique_user_settings` (`company_id`,`user_id`)
            ) $tail");
    }

    private static function convertCharset(PDO $db): void
    {
        $in = implode(',', array_fill(0, count(self::TABLES), '?'));
        $st = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)
                               AND TABLE_COLLATION NOT LIKE 'utf8mb4%'");
        $st->execute(self::TABLES);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $db->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    private static function requiredColumns(): array
    {
        return [
            'calendar_events' => [
                'ics_uid'       => "varchar(255) DEFAULT NULL",
                'series_id'     => "bigint(20) DEFAULT NULL",
                'recurrence_id' => "datetime DEFAULT NULL",
                'updated_by'    => "int(11) DEFAULT NULL",
                'exdates_json'  => "longtext DEFAULT NULL",
                'visibility'    => "enum('default','busy','private') DEFAULT 'default'",
            ],
            'calendar_event_reminders' => [
                'snoozed_until'   => "datetime DEFAULT NULL",
                'last_occurrence' => "datetime DEFAULT NULL",
            ],
            'calendar_settings' => [
                'enable_invoice_due'   => "tinyint(1) NOT NULL DEFAULT 1",
                'enable_project_dates' => "tinyint(1) NOT NULL DEFAULT 1",
                'prefs_json'           => "longtext DEFAULT NULL",
            ],
            'calendars' => [
                'sharing_json' => "longtext DEFAULT NULL",
                'is_active'    => "tinyint(1) DEFAULT 1",
                'ics_token'    => "varchar(64) DEFAULT NULL",
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

    private static function extendEnums(PDO $db): void
    {
        $st = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_event_links' AND COLUMN_NAME = 'linked_type'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        if ($type === '') return;
        preg_match_all("/'([^']*)'/", $type, $m);
        $have = $m[1] ?? [];
        $missing = array_diff(self::LINK_TYPES, $have);
        if (!$missing) return;
        // Keep any value an older writer may have added, then append ours.
        $all = array_values(array_unique(array_merge($have, self::LINK_TYPES)));
        $db->exec("ALTER TABLE `calendar_event_links` MODIFY `linked_type` enum('" . implode("','", $all) . "') NOT NULL");
    }

    private static function addIndexes(PDO $db): void
    {
        $want = [
            'calendar_events' => [
                'idx_cal_start' => '(`calendar_id`, `start_datetime`)',
                'idx_series'    => '(`series_id`)',
                'idx_ics_uid'   => '(`calendar_id`, `ics_uid`(191))',
            ],
            'calendar_event_reminders' => [
                'idx_pending' => '(`sent_at`, `event_id`)',
            ],
        ];
        $have = self::indexMap($db, array_keys($want));
        foreach ($want as $table => $indexes) {
            foreach ($indexes as $name => $cols) {
                if (isset($have[$table][$name])) continue;
                $db->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
            }
        }
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

    /** @return array<string, array<string, true>> table => index name => true */
    private static function indexMap(PDO $db, array $tables): array
    {
        $in = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare("SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
        $stmt->execute($tables);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['TABLE_NAME']][$r['INDEX_NAME']] = true;
        }
        return $map;
    }
}
