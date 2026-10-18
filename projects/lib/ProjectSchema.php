<?php
// projects/lib/ProjectSchema.php
//
// Self-provisioning for the Projects schema, the same way the Calendar does it.
// The project tables were created outside the tracked migrations, so
// environments differ. ensure() makes every environment converge:
//   - creates any missing table (fresh installs),
//   - adds the columns the new boards use (project colour and visibility,
//     comment replies, per-column files, reminder bookkeeping …),
//   - widens board_columns.type with the new column types,
//   - adds the hot-path indexes and the one-row-per-cell key,
//   - fills board_item_people and the items' own fields (status, assignee,
//     dates …) from the cell values once, so Calendar, Home and My work see
//     the boards that were filled in before (the old boards' own fields are
//     copied into cells only once ever).
// It never drops or retypes an existing column. Mirrors
// Migrations/2026-10-18-projects-overhaul.sql. Runs once per SCHEMA_VERSION
// (a marker file under storage/projects/).

final class ProjectSchema
{
    public const SCHEMA_VERSION = '2026-10-18-3';

    public const COLUMN_TYPES = ['status', 'people', 'date', 'timeline', 'text', 'longtext', 'number', 'dropdown', 'checkbox',
                                 'tags', 'link', 'email', 'phone', 'formula', 'progress', 'priority', 'supplier', 'files',
                                 'rating', 'created', 'updated'];

    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;

        $marker = self::markerPath();
        if ($marker !== null && is_file($marker)) return;
        $failed = $marker !== null ? $marker . '.failed' : null;
        if ($failed !== null && is_file($failed) && filemtime($failed) > time() - 600) return;

        // One request at a time: the first one after a deploy does the work (the
        // backfill can take a while on big boards); others wait for it, then go on.
        $locked = false;
        $canLock = true;
        try { $locked = (bool)$db->query("SELECT GET_LOCK('flowwork_projects_schema', 20)")->fetchColumn(); } catch (Throwable $e) { $canLock = false; /* run without */ }
        if ($canLock && !$locked) return; // still busy elsewhere after 20 s; its tables already exist
        try {
            clearstatcache();
            if ($marker !== null && is_file($marker)) return; // finished while this request waited
            if ($failed !== null && is_file($failed) && filemtime($failed) > time() - 600) return; // … or failed: wait as above

            $ok = true;
            foreach (['createTables' => true, 'addColumns' => true, 'extendEnums' => true, 'addIndexes' => false, 'backfill' => false] as $step => $required) {
                try {
                    self::$step($db);
                } catch (Throwable $e) {
                    error_log('[projects] schema ' . $step . ' failed: ' . $e->getMessage());
                    if ($required) $ok = false;
                }
            }
            if ($marker !== null) {
                if ($ok) { @file_put_contents($marker, date('c')); @unlink($failed); }
                else @touch($failed);
            }
        } finally {
            if ($locked) { try { $db->query("SELECT RELEASE_LOCK('flowwork_projects_schema')"); } catch (Throwable $e) { /* released when the connection closes */ } }
        }
    }

    /** For tests: forget that ensure() already ran in this process. */
    public static function reset(): void { self::$done = false; }

    private static function markerPath(): ?string
    {
        $dir = ProjectPaths::storageDir();
        return $dir === null ? null : $dir . '/.schema-' . self::SCHEMA_VERSION;
    }

    private static function typeEnum(): string
    {
        return "enum('" . implode("','", self::COLUMN_TYPES) . "')";
    }

    private static function createTables(PDO $db): void
    {
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE IF NOT EXISTS `projects` (
              `project_id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `project_number` varchar(50) DEFAULT NULL,
              `name` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `client` varchar(255) DEFAULT NULL,
              `client_id` int(11) DEFAULT NULL,
              `site_address` text DEFAULT NULL,
              `status` enum('draft','active','on_hold','completed','archived','cancelled') DEFAULT 'draft',
              `archived` tinyint(1) DEFAULT 0,
              `start_date` date DEFAULT NULL,
              `end_date` date DEFAULT NULL,
              `budget` decimal(15,2) DEFAULT 0.00,
              `actual_cost` decimal(15,2) DEFAULT 0.00,
              `progress_percent` tinyint(3) DEFAULT 0,
              `project_manager_id` int(11) DEFAULT NULL,
              `owner_id` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`project_id`),
              KEY `idx_company_status` (`company_id`,`status`),
              KEY `idx_company_archived` (`company_id`,`archived`),
              KEY `idx_client` (`client_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `project_boards` (
              `board_id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `project_id` int(11) NOT NULL,
              `board_type` enum('schedule','costing','procurement','quality','safety','general') DEFAULT 'general',
              `title` varchar(255) NOT NULL,
              `default_view` varchar(20) DEFAULT 'table',
              `description` text DEFAULT NULL,
              `sort_order` int(11) DEFAULT 0,
              `archived` tinyint(1) DEFAULT 0,
              `is_active` tinyint(1) DEFAULT 1,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`board_id`),
              KEY `idx_company_project` (`company_id`,`project_id`),
              KEY `idx_project_order` (`project_id`,`sort_order`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `project_members` (
              `member_id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `project_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `role` enum('owner','manager','member','viewer') DEFAULT 'member',
              `can_edit` tinyint(1) DEFAULT 1,
              `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`member_id`),
              UNIQUE KEY `uniq_project_user` (`project_id`,`user_id`),
              KEY `idx_company` (`company_id`),
              KEY `idx_user` (`user_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_groups` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `name` varchar(255) NOT NULL DEFAULT 'New Group',
              `position` int(11) DEFAULT 0,
              `is_locked` tinyint(1) DEFAULT 0,
              `collapsed` tinyint(1) DEFAULT 0,
              `color` varchar(7) DEFAULT '#8b5cf6',
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_board_position` (`board_id`,`position`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_columns` (
              `column_id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `name` varchar(100) NOT NULL,
              `type` " . self::typeEnum() . " NOT NULL DEFAULT 'text',
              `config` text DEFAULT NULL,
              `supplier_id` int(10) DEFAULT NULL,
              `position` int(11) DEFAULT 0,
              `sort_order` int(11) DEFAULT 0,
              `visible` tinyint(1) DEFAULT 1,
              `width` int(11) DEFAULT 150,
              `color` varchar(7) DEFAULT NULL,
              `required` tinyint(1) DEFAULT 0,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`column_id`),
              KEY `idx_columns_board_pos` (`board_id`,`company_id`,`position`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_items` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `group_id` int(11) NOT NULL,
              `title` varchar(500) NOT NULL DEFAULT 'New Item',
              `description` text DEFAULT NULL,
              `position` int(11) DEFAULT 0,
              `status_label` varchar(100) DEFAULT NULL,
              `assigned_to` int(11) DEFAULT NULL,
              `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
              `progress` int(11) DEFAULT 0,
              `due_date` date DEFAULT NULL,
              `start_date` date DEFAULT NULL,
              `end_date` date DEFAULT NULL,
              `tags` varchar(255) DEFAULT NULL,
              `archived` tinyint(1) DEFAULT 0,
              `subitem_count` int(11) DEFAULT 0,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_items_board_group` (`board_id`,`group_id`,`position`,`company_id`,`archived`),
              KEY `idx_company_board_group` (`company_id`,`board_id`,`group_id`),
              KEY `idx_assigned` (`assigned_to`),
              KEY `idx_due` (`due_date`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_item_values` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `item_id` int(11) NOT NULL,
              `column_id` int(11) NOT NULL,
              `value` text DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_item_col` (`item_id`,`column_id`),
              KEY `idx_column` (`column_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_subitems` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `parent_item_id` int(11) NOT NULL,
              `board_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `title` varchar(255) NOT NULL,
              `status_label` varchar(50) DEFAULT NULL,
              `assigned_to` int(11) DEFAULT NULL,
              `due_date` date DEFAULT NULL,
              `priority` varchar(20) DEFAULT NULL,
              `completed` tinyint(1) DEFAULT 0,
              `position` int(11) DEFAULT 0,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_parent` (`parent_item_id`),
              KEY `idx_board` (`board_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_item_comments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `item_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `comment` text NOT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_item` (`item_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_item_attachments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `item_id` int(11) NOT NULL,
              `file_name` varchar(255) NOT NULL,
              `file_path` varchar(512) NOT NULL,
              `file_size` int(11) DEFAULT NULL,
              `mime_type` varchar(100) DEFAULT NULL,
              `uploaded_by` int(11) NOT NULL,
              `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_item` (`item_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_audit_log` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `company_id` int(11) NOT NULL,
              `board_id` int(11) DEFAULT NULL,
              `item_id` int(11) DEFAULT NULL,
              `user_id` int(11) NOT NULL,
              `action` varchar(100) NOT NULL,
              `details` longtext DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_board_id` (`board_id`,`id`),
              KEY `idx_item` (`item_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_saved_views` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `user_id` int(11) DEFAULT NULL,
              `name` varchar(100) NOT NULL,
              `view_type` varchar(20) NOT NULL,
              `filters` longtext DEFAULT NULL,
              `sorts` longtext DEFAULT NULL,
              `visible_columns` longtext DEFAULT NULL,
              `is_shared` tinyint(1) DEFAULT 0,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_board` (`board_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_automations` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `name` varchar(255) NOT NULL,
              `trigger_type` varchar(50) NOT NULL,
              `trigger_config` longtext DEFAULT NULL,
              `action_type` varchar(50) NOT NULL,
              `action_config` longtext DEFAULT NULL,
              `is_active` tinyint(1) DEFAULT 1,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_board` (`board_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_automation_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `automation_id` int(11) NOT NULL,
              `item_id` int(11) DEFAULT NULL,
              `executed_at` datetime DEFAULT current_timestamp(),
              `success` tinyint(1) DEFAULT NULL,
              `error_message` text DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_automation` (`automation_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_dependencies` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `predecessor_item` int(11) NOT NULL,
              `successor_item` int(11) NOT NULL,
              `dependency_type` enum('FS','SS','FF','SF') DEFAULT 'FS',
              `lag_days` int(11) DEFAULT 0,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_board` (`board_id`),
              KEY `idx_predecessor` (`predecessor_item`),
              KEY `idx_successor` (`successor_item`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_watchers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `item_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `item_user` (`item_id`,`user_id`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_guests` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `email` varchar(255) NOT NULL,
              `token` varchar(64) NOT NULL,
              `password_hash` varchar(255) DEFAULT NULL,
              `password_set_at` datetime DEFAULT NULL,
              `status` enum('pending','active','expired') DEFAULT 'pending',
              `invited_by` int(11) NOT NULL,
              `invited_at` datetime NOT NULL,
              `confirmed_at` datetime DEFAULT NULL,
              `expires_at` datetime DEFAULT NULL,
              `last_access_at` datetime DEFAULT NULL,
              `access_count` int(11) DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `token` (`token`),
              UNIQUE KEY `unique_board_email` (`board_id`,`email`)
            ) $tail");
        $db->exec("CREATE TABLE IF NOT EXISTS `board_guest_activity` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `guest_id` int(11) NOT NULL,
              `action` varchar(100) DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `user_agent` text DEFAULT NULL,
              `timestamp` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_guest` (`guest_id`)
            ) $tail");
        // New: who is on which item (every person in a people column), for My
        // work, the Calendar, workload and reminders.
        $db->exec("CREATE TABLE IF NOT EXISTS `board_item_people` (
              `item_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `board_id` int(11) NOT NULL,
              PRIMARY KEY (`item_id`,`user_id`),
              KEY `idx_user` (`company_id`,`user_id`),
              KEY `idx_board` (`board_id`)
            ) $tail");
        // New: per-user state — starred and recently opened projects and boards, board preferences.
        $db->exec("CREATE TABLE IF NOT EXISTS `project_user_state` (
              `company_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `kind` enum('project','board') NOT NULL,
              `ref_id` int(11) NOT NULL,
              `starred` tinyint(1) NOT NULL DEFAULT 0,
              `last_opened_at` datetime DEFAULT NULL,
              `prefs` text DEFAULT NULL,
              PRIMARY KEY (`user_id`,`kind`,`ref_id`),
              KEY `idx_company_user` (`company_id`,`user_id`,`last_opened_at`)
            ) $tail");
    }

    private static function columns(PDO $db, string $table): array
    {
        $st = $db->query("SHOW COLUMNS FROM `$table`");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $out[$c['Field']] = $c;
        return $out;
    }

    private static function addColumns(PDO $db): void
    {
        $add = [
            'projects' => [
                'color'      => "varchar(7) DEFAULT NULL AFTER `name`",
                'visibility' => "enum('members','company') NOT NULL DEFAULT 'members' AFTER `status`",
            ],
            'project_boards' => [
                'created_by' => "int(11) DEFAULT NULL",
                'item_noun'  => "varchar(40) DEFAULT NULL",
                'formulas_on' => "date DEFAULT NULL", // the day formulas that read the clock were last computed
            ],
            'board_columns' => [
                'description' => "varchar(255) DEFAULT NULL",
            ],
            'board_items' => [
                'reminded_on' => "date DEFAULT NULL",
            ],
            'board_item_comments' => [
                'parent_id' => "int(11) DEFAULT NULL AFTER `item_id`",
                'edited_at' => "datetime DEFAULT NULL",
            ],
            'board_item_attachments' => [
                'column_id' => "int(11) DEFAULT NULL AFTER `item_id`",
            ],
            'board_saved_views' => [
                'config' => "longtext DEFAULT NULL",
            ],
        ];
        foreach ($add as $table => $cols) {
            $have = self::columns($db, $table);
            foreach ($cols as $name => $def) {
                if (!isset($have[$name])) $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $def");
            }
        }
    }

    private static function extendEnums(PDO $db): void
    {
        $have = self::columns($db, 'board_columns');
        $type = strtolower((string)($have['type']['Type'] ?? ''));
        foreach (self::COLUMN_TYPES as $t) {
            if (!str_contains($type, "'" . $t . "'")) {
                $db->exec("ALTER TABLE `board_columns` MODIFY `type` " . self::typeEnum() . " NOT NULL DEFAULT 'text'");
                break;
            }
        }
    }

    private static function indexes(PDO $db, string $table): array
    {
        $out = [];
        foreach ($db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['Key_name']]['cols'][(int)$r['Seq_in_index']] = $r['Column_name'];
            $out[$r['Key_name']]['unique'] = !(int)$r['Non_unique'];
        }
        return $out;
    }

    /**
     * Is there an index (a unique one, when asked) whose columns are exactly $cols, in order?
     * A plain index that starts with $cols serves as well.
     */
    private static function hasIndexOn(array $indexes, array $cols, bool $unique): bool
    {
        foreach ($indexes as $ix) {
            $c = $ix['cols'];
            ksort($c);
            if (array_values($c) === $cols && (!$unique || $ix['unique'])) return true;
            if (!$unique && array_slice(array_values($c), 0, count($cols)) === $cols) return true;
        }
        return false;
    }

    private static function addIndexes(PDO $db): void
    {
        $want = [
            'board_item_values' => [['uniq_item_col', ['item_id', 'column_id'], true]],
            'board_items' => [['idx_company_assigned', ['company_id', 'assigned_to'], false],
                              ['idx_company_due', ['company_id', 'due_date'], false],
                              ['idx_due', ['due_date'], false], // the reminders' tick, across companies
                              ['idx_board_company_archived', ['board_id', 'company_id', 'archived'], false]],
            'board_item_comments' => [['idx_item', ['item_id'], false]],
            'board_item_attachments' => [['idx_item', ['item_id'], false]],
            'board_subitems' => [['idx_parent', ['parent_item_id'], false]],
            'board_audit_log' => [['idx_board_id', ['board_id', 'id'], false]],
            'board_groups' => [['idx_board_position', ['board_id', 'position'], false]],
            // Other modules' tables that Projects reads by item, project or board (item panel, Money tab).
            'quote_lines' => [['idx_project_item', ['project_item_id'], false]],
            'invoice_lines' => [['idx_project_item', ['project_item_id'], false]],
            'ap_bill_lines' => [['idx_project_item', ['project_item_id'], false]],
            'timesheet_entries' => [['idx_company_item', ['company_id', 'item_id'], false],
                                    ['idx_ts_project', ['project_id'], false],
                                    ['idx_ts_board', ['board_id'], false]],
            'journal_lines' => [['idx_jl_board', ['board_id'], false]],
        ];
        foreach ($want as $table => $list) {
            try {
                $have = self::indexes($db, $table);
                foreach ($list as [$name, $cols, $unique]) {
                    if (self::hasIndexOn($have, $cols, $unique)) continue;
                    if ($unique && $table === 'board_item_values') {
                        // Keep the newest row of each duplicated cell, then add the key.
                        $db->exec("DELETE t1 FROM board_item_values t1 JOIN board_item_values t2
                                     ON t1.item_id = t2.item_id AND t1.column_id = t2.column_id AND t1.id < t2.id");
                    }
                    $db->exec("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE KEY' : 'KEY') . " `$name` (`" . implode('`,`', $cols) . "`)");
                }
            } catch (Throwable $e) {
                // A module that is not installed (or an older shape of its table): the rest still go ahead.
                error_log('[projects] schema index on ' . $table . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Once per version, for every board: recompute formulas with the new engine
     * (names now match loosely), then fill board_item_people and the items' own
     * fields. Once ever (its own marker), before that: copy item fields the old
     * boards showed into cells — after the first run those fields are mirrors,
     * and copying them again would turn computed dates into typed ones.
     */
    private static function backfill(PDO $db): void
    {
        @set_time_limit(300);
        $legacy = self::legacyPending();
        $boards = $db->query("SELECT board_id, company_id FROM project_boards")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($boards as $b) {
            try {
                if ($legacy) BoardRepo::migrateLegacyFields($db, (int)$b['company_id'], (int)$b['board_id']);
                BoardRepo::computeFormulas($db, (int)$b['company_id'], (int)$b['board_id']);
                BoardRepo::refreshMirrors($db, (int)$b['company_id'], (int)$b['board_id']);
            } catch (Throwable $e) {
                error_log('[projects] backfill board ' . $b['board_id'] . ': ' . $e->getMessage());
            }
        }
        $dir = ProjectPaths::storageDir();
        if ($dir !== null && !is_file($dir . '/.legacy-fields-migrated')) @file_put_contents($dir . '/.legacy-fields-migrated', date('c'));
    }

    /** Has the old boards' field copy still to run? (Not when it ran, or when an earlier version's backfill did.) */
    private static function legacyPending(): bool
    {
        $dir = ProjectPaths::storageDir();
        if ($dir === null) return true;
        if (is_file($dir . '/.legacy-fields-migrated')) return false;
        foreach (glob($dir . '/.schema-*') ?: [] as $f) {
            if (!str_ends_with($f, '.failed') && basename($f) !== '.schema-' . self::SCHEMA_VERSION) return false;
        }
        return true;
    }
}
