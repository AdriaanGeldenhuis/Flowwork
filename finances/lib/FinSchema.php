<?php
// finances/lib/FinSchema.php
//
// Self-provisioning for the Finance app, the same way Mail, Calendar,
// Projects, Quotes & Invoices and the CRM do it. ensure() makes every
// environment converge:
//   - fin_prefs: the company's look, wording, parts and rules (user_id 0)
//     and each person's own layout,
//   - bank_reconciliations (history of closed reconciliations; part of the
//     original schema, created only where it is missing),
//   - fin_attachments: supporting documents on journals, bills, assets and
//     bank lines (files live in storage/finance/<company>/, never served
//     directly),
//   - an index on audit_log by entity (each journal shows its own trail),
//   - deny-all guards on the private folders (the deploy does not copy
//     /uploads or /storage, so they are written here on the server).
// It never drops or rewrites a column, a table or a posted row.
// Mirrors Migrations/2026-10-19-finance-overhaul.sql.
// Runs once per SCHEMA_VERSION (a marker file under storage/finance/).

final class FinSchema
{
    public const SCHEMA_VERSION = '2026-10-19-2';

    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;

        $marker = self::markerPath($db);
        if ($marker !== null && is_file($marker)) return;
        $failed = $marker !== null ? $marker . '.failed' : null;
        if ($failed !== null && is_file($failed) && filemtime($failed) > time() - 600) return;

        $locked = false;
        $canLock = true;
        try { $locked = (bool)$db->query("SELECT GET_LOCK('flowwork_fin_schema', 20)")->fetchColumn(); } catch (Throwable $e) { $canLock = false; }
        if ($canLock && !$locked) return;
        try {
            clearstatcache();
            if ($marker !== null && is_file($marker)) return;
            $ok = true;
            foreach (['createTables' => true, 'addIndexes' => false, 'protectFiles' => false] as $step => $required) {
                try {
                    self::$step($db);
                } catch (Throwable $e) {
                    error_log('[finance] schema ' . $step . ' failed: ' . $e->getMessage());
                    if ($required) $ok = false;
                }
            }
            if ($marker !== null) {
                if ($ok) { @file_put_contents($marker, date('c')); @unlink($failed); }
                else @touch($failed);
            }
        } finally {
            if ($locked) { try { $db->query("SELECT RELEASE_LOCK('flowwork_fin_schema')"); } catch (Throwable $e) { } }
        }
    }

    /** For tests: forget that ensure() already ran in this process (and drop the marker). */
    public static function reset(): void
    {
        self::$done = false;
        $dir = self::storageDir();
        if ($dir !== null) foreach (glob($dir . '/.schema-' . self::SCHEMA_VERSION . '*') ?: [] as $m) @unlink($m);
    }

    /** storage/finance (private; created on demand). */
    public static function storageDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/finance';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
        return $dir;
    }

    /** One marker per schema version and database (a restored or second database is provisioned too). */
    private static function markerPath(PDO $db): ?string
    {
        $dir = self::storageDir();
        if ($dir === null) return null;
        try { $name = (string)$db->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $name = ''; }
        return $dir . '/.schema-' . self::SCHEMA_VERSION . '-' . substr(sha1($name), 0, 10);
    }

    private static function createTables(PDO $db): void
    {
        // Reconciliation history (in the original schema; created here for installs without it).
        $db->exec("CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `fin_prefs` (
            `company_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL DEFAULT 0,
            `prefs_json` mediumtext NOT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`company_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `fin_attachments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** The journal audit trail is read per journal: index audit_log by entity (online ALTER on InnoDB). */
    private static function addIndexes(PDO $db): void
    {
        $have = [];
        foreach ($db->query("SHOW INDEX FROM `audit_log`")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Key_name']] = true;
        if (!isset($have['idx_audit_entity'])) {
            $db->exec("ALTER TABLE `audit_log` ADD KEY `idx_audit_entity` (`company_id`, `entity_type`, `entity_id`)");
        }
    }

    /** Private folders are never served directly (bank statements, attachments, rate-limit files). */
    private static function protectFiles(PDO $db): void
    {
        $root = dirname(__DIR__, 2);
        $deny = "# Private files: served only through Finance, which checks the company.\n"
              . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n";
        $dirs = [$root . '/storage/finance', $root . '/uploads/bank_statements', $root . '/storage/bank_statements'];
        foreach ($dirs as $dir) {
            if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', $deny);
        }
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
}
