<?php
// crm/lib/CrmSchema.php
//
// Self-provisioning for the CRM, the same way Mail, Calendar, Projects and
// Quotes & Invoices do it. ensure() makes every environment converge:
//   - new tables: crm_tasks (follow-ups and to-dos with reminders),
//     crm_pipeline_stages (the company's own deal stages), crm_custom_fields +
//     crm_custom_values (fields the company adds to customers, suppliers,
//     contacts and deals), crm_prefs (the company's look and wording, and each
//     person's layout) and crm_notify_log (reminders already sent),
//   - new columns: an account owner; contact mobile and notes; activity linked
//     to a deal and pinned; deal contact, notes, card order and creator;
//     company-specific industries and regions; the original name of an
//     uploaded compliance document,
//   - deal stages become free text so a company can define its own (the
//     terminal 'won' and 'lost' keys stay fixed, so every module that reads
//     open deals keeps working), and activity gains 'meeting' and 'whatsapp',
//   - old interaction follow-ups (next_action_at) become tasks once.
// It never drops a column or table. Mirrors Migrations/2026-10-19-crm-overhaul.sql.
// Runs once per SCHEMA_VERSION (a marker file under storage/crm/).

final class CrmSchema
{
    public const SCHEMA_VERSION = '2026-10-19-2';

    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;

        $marker = self::markerPath();
        if ($marker !== null && is_file($marker)) return;
        $failed = $marker !== null ? $marker . '.failed' : null;
        if ($failed !== null && is_file($failed) && filemtime($failed) > time() - 600) return;

        $locked = false;
        $canLock = true;
        try { $locked = (bool)$db->query("SELECT GET_LOCK('flowwork_crm_schema', 20)")->fetchColumn(); } catch (Throwable $e) { $canLock = false; }
        if ($canLock && !$locked) return;
        try {
            clearstatcache();
            if ($marker !== null && is_file($marker)) return;
            $ok = true;
            foreach (['createTables' => true, 'addColumns' => true, 'retype' => true, 'addIndexes' => false, 'backfill' => false, 'protectFiles' => false] as $step => $required) {
                try {
                    self::$step($db);
                } catch (Throwable $e) {
                    error_log('[crm] schema ' . $step . ' failed: ' . $e->getMessage());
                    if ($required) $ok = false;
                }
            }
            if ($marker !== null) {
                if ($ok) { @file_put_contents($marker, date('c')); @unlink($failed); }
                else @touch($failed);
            }
        } finally {
            if ($locked) { try { $db->query("SELECT RELEASE_LOCK('flowwork_crm_schema')"); } catch (Throwable $e) { } }
        }
    }

    /** For tests: forget that ensure() already ran in this process (and drop the marker). */
    public static function reset(): void
    {
        self::$done = false;
        $m = self::markerPath();
        if ($m !== null) @unlink($m);
    }

    private static function markerPath(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/crm';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
        return $dir . '/.schema-' . self::SCHEMA_VERSION;
    }

    private static function createTables(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `crm_tasks` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `crm_custom_fields` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `crm_custom_values` (
            `field_id` int(10) unsigned NOT NULL,
            `entity_id` bigint(20) unsigned NOT NULL,
            `company_id` int(10) unsigned NOT NULL,
            `value` text DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`field_id`, `entity_id`),
            KEY `idx_crm_cv_entity` (`company_id`, `entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `crm_notify_log` (
            `company_id` int(10) unsigned NOT NULL,
            `kind` varchar(30) NOT NULL,
            `ref_id` bigint(20) unsigned NOT NULL,
            `mark` varchar(30) NOT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`company_id`, `kind`, `ref_id`, `mark`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `crm_prefs` (
            `company_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL DEFAULT 0,
            `prefs_json` mediumtext NOT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`company_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function addColumns(PDO $db): void
    {
        $add = [
            'crm_accounts' => [
                'owner_id' => "int(10) unsigned DEFAULT NULL",
            ],
            'crm_contacts' => [
                'mobile' => "varchar(64) DEFAULT NULL",
                'notes'  => "text DEFAULT NULL",
            ],
            'crm_interactions' => [
                'opportunity_id' => "int(10) unsigned DEFAULT NULL",
                'pinned'         => "tinyint(1) NOT NULL DEFAULT 0",
                'updated_at'     => "datetime DEFAULT NULL",
            ],
            'crm_opportunities' => [
                'contact_id' => "bigint(20) unsigned DEFAULT NULL",
                'notes'      => "text DEFAULT NULL",
                'sort_order' => "int(11) NOT NULL DEFAULT 0",
                'created_by' => "int(10) unsigned DEFAULT NULL",
            ],
            'crm_industries' => [
                'company_id' => "int(10) unsigned DEFAULT NULL",
            ],
            'crm_regions' => [
                'company_id' => "int(10) unsigned DEFAULT NULL",
            ],
            'crm_compliance_docs' => [
                'original_name' => "varchar(255) DEFAULT NULL",
            ],
        ];
        foreach ($add as $table => $cols) {
            $have = self::columns($db, $table);
            if (!$have) continue; // table not present on this install
            foreach ($cols as $col => $def) {
                if (!isset($have[strtolower($col)])) {
                    $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                }
            }
        }
    }

    /** Deal stages become free text; activity learns meetings and WhatsApp. Values are kept. */
    private static function retype(PDO $db): void
    {
        $type = self::columnType($db, 'crm_opportunities', 'stage');
        if ($type !== null && str_starts_with(strtolower($type), 'enum')) {
            $db->exec("ALTER TABLE `crm_opportunities` MODIFY `stage` varchar(40) NOT NULL DEFAULT 'prospect'");
        }
        $type = self::columnType($db, 'crm_interactions', 'type');
        if ($type !== null && str_starts_with(strtolower($type), 'enum') && !str_contains($type, "'whatsapp'")) {
            $db->exec("ALTER TABLE `crm_interactions` MODIFY `type` enum('email','call','visit','note','issue','meeting','whatsapp') NOT NULL");
        }
    }

    private static function addIndexes(PDO $db): void
    {
        $want = [
            'crm_opportunities' => ['idx_crmop_company_account' => 'KEY `idx_crmop_company_account` (`company_id`, `account_id`)'],
            'crm_accounts'      => ['idx_crm_accounts_owner' => 'KEY `idx_crm_accounts_owner` (`company_id`, `owner_id`)'],
            'crm_interactions'  => ['idx_crm_interactions_opp' => 'KEY `idx_crm_interactions_opp` (`company_id`, `opportunity_id`)'],
        ];
        foreach ($want as $table => $indexes) {
            $have = [];
            try {
                foreach ($db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Key_name']] = true;
            } catch (Throwable $e) {
                continue;
            }
            foreach ($indexes as $name => $def) {
                if (!isset($have[$name])) {
                    try { $db->exec("ALTER TABLE `$table` ADD $def"); } catch (Throwable $e) { error_log("[crm] index $name: " . $e->getMessage()); }
                }
            }
        }
    }

    /**
     * Private files are never served directly: compliance documents (the
     * CRM's folder and older supplier-portal uploads under
     * uploads/company/<id>/compliance) and the CRM's import scratch files.
     * The deploy does not copy /uploads or /storage, so the guards are
     * written here on the server itself.
     */
    private static function protectFiles(PDO $db): void
    {
        $root = dirname(__DIR__, 2);
        $deny = "# Private files: served only through the CRM, which checks the company.\n"
              . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n";
        $dirs = array_merge([$root . '/uploads/compliance', $root . '/storage/crm'], glob($root . '/uploads/company/*/compliance', GLOB_ONLYDIR) ?: []);
        foreach ($dirs as $dir) {
            if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', $deny);
        }
    }

    /** Follow-ups that lived on interactions (next_action_at) become tasks, once each. */
    private static function backfill(PDO $db): void
    {
        $db->exec("INSERT IGNORE INTO crm_tasks (company_id, account_id, contact_id, title, due_at, assigned_to, created_by,
                                                 source_interaction_id, created_at)
                   SELECT i.company_id, i.account_id, i.contact_id,
                          LEFT(CONCAT('Follow up: ', COALESCE(NULLIF(TRIM(i.subject), ''), i.type)), 190),
                          i.next_action_at, i.created_by, i.created_by, i.id, i.created_at
                     FROM crm_interactions i
                    WHERE i.next_action_at IS NOT NULL");
    }

    /** @return array<string, true> lower-cased column names, empty when the table is missing */
    public static function columns(PDO $db, string $table): array
    {
        try {
            $out = [];
            foreach ($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[strtolower($r['Field'])] = true;
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function columnType(PDO $db, string $table, string $column): ?string
    {
        try {
            $st = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            $t = $st->fetchColumn();
            return $t !== false ? (string)$t : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
