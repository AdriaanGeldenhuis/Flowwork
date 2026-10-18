<?php
// qi/lib/QiSchema.php
//
// Self-provisioning for the Quotes & Invoices additions, the same way Projects
// and Calendar do it. ensure() makes every environment converge:
//   - creates qi_designs (the document designer) and qi_activity (the
//     document timeline),
//   - adds the new document columns (reference, notes to the customer, public
//     invoice link, sent / viewed times, recurring link, Yoco checkout),
//     credit-note line accounts, recurring currency / anchor day / auto-send /
//     last scheduled attempt, and the numbering + default settings on
//     qi_settings,
//   - gives quotes that never had one a public link token.
// It never drops or retypes an existing column. Mirrors
// Migrations/2026-10-18-quotes-invoices-overhaul.sql (which also creates the
// headings table that LineHeadings provisions). Runs once per SCHEMA_VERSION
// (a marker file under storage/qi/).

final class QiSchema
{
    public const SCHEMA_VERSION = '2026-10-18-2';

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
        try { $locked = (bool)$db->query("SELECT GET_LOCK('flowwork_qi_schema', 20)")->fetchColumn(); } catch (Throwable $e) { $canLock = false; }
        if ($canLock && !$locked) return;
        try {
            clearstatcache();
            if ($marker !== null && is_file($marker)) return;
            $ok = true;
            foreach (['createTables' => true, 'addColumns' => true, 'addIndexes' => false, 'backfill' => false] as $step => $required) {
                try {
                    self::$step($db);
                } catch (Throwable $e) {
                    error_log('[qi] schema ' . $step . ' failed: ' . $e->getMessage());
                    if ($required) $ok = false;
                }
            }
            if ($marker !== null) {
                if ($ok) { @file_put_contents($marker, date('c')); @unlink($failed); }
                else @touch($failed);
            }
        } finally {
            if ($locked) { try { $db->query("SELECT RELEASE_LOCK('flowwork_qi_schema')"); } catch (Throwable $e) { } }
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
        $dir = dirname(__DIR__, 2) . '/storage/qi';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
        return $dir . '/.schema-' . self::SCHEMA_VERSION;
    }

    private static function createTables(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `qi_designs` (
            `company_id` int(10) unsigned NOT NULL,
            `design_json` longtext NOT NULL,
            `previous_json` longtext DEFAULT NULL,
            `updated_by` int(10) unsigned DEFAULT NULL,
            `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `qi_activity` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `company_id` int(10) unsigned NOT NULL,
            `doc_type` varchar(16) NOT NULL,
            `doc_id` int(10) unsigned NOT NULL,
            `action` varchar(40) NOT NULL,
            `detail` varchar(500) DEFAULT NULL,
            `user_id` int(10) unsigned DEFAULT NULL,
            `actor` varchar(190) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_qi_activity_doc` (`company_id`, `doc_type`, `doc_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function addColumns(PDO $db): void
    {
        $add = [
            'quotes' => [
                'reference' => "varchar(120) DEFAULT NULL",
                'customer_notes' => "text DEFAULT NULL",
                'sent_at'   => "datetime DEFAULT NULL",
                'viewed_at' => "datetime DEFAULT NULL",
            ],
            'invoices' => [
                'reference'            => "varchar(120) DEFAULT NULL",
                'customer_notes'       => "text DEFAULT NULL",
                'public_token'         => "varchar(64) DEFAULT NULL",
                'sent_at'              => "datetime DEFAULT NULL",
                'viewed_at'            => "datetime DEFAULT NULL",
                'recurring_invoice_id' => "int(10) unsigned DEFAULT NULL",
                'yoco_checkout_id'     => "varchar(100) DEFAULT NULL",
            ],
            'credit_notes' => [
                'reference' => "varchar(120) DEFAULT NULL",
            ],
            'credit_note_lines' => [
                'gl_account_id'     => "bigint(20) unsigned DEFAULT NULL",
                'inventory_item_id' => "bigint(20) DEFAULT NULL",
            ],
            'recurring_invoices' => [
                'currency'           => "char(3) DEFAULT NULL",
                'anchor_day'         => "tinyint(3) unsigned DEFAULT NULL",
                'auto_send'          => "tinyint(1) NOT NULL DEFAULT 0",
                'payment_terms_days' => "int(11) DEFAULT NULL",
                'last_attempt_at'    => "datetime DEFAULT NULL",
            ],
            'qi_settings' => [
                'quote_prefix'        => "varchar(20) NOT NULL DEFAULT 'Q'",
                'invoice_prefix'      => "varchar(20) NOT NULL DEFAULT 'INV'",
                'credit_prefix'       => "varchar(20) NOT NULL DEFAULT 'CN'",
                'number_year'         => "tinyint(1) NOT NULL DEFAULT 1",
                'number_digits'       => "tinyint(3) unsigned NOT NULL DEFAULT 4",
                'number_sep'          => "varchar(3) NOT NULL DEFAULT '-'",
                'quote_validity_days' => "int(11) NOT NULL DEFAULT 14",
                'default_notes'       => "text DEFAULT NULL",
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

    private static function addIndexes(PDO $db): void
    {
        $want = [
            'invoices' => ['uq_invoices_public_token' => 'UNIQUE KEY `uq_invoices_public_token` (`public_token`)',
                           'idx_invoices_recurring' => 'KEY `idx_invoices_recurring` (`company_id`, `recurring_invoice_id`)'],
            'quotes'   => ['idx_quotes_public_token' => 'KEY `idx_quotes_public_token` (`public_token`)'],
        ];
        // an empty token is "no link yet"; empty strings would collide in the unique key
        try { $db->exec("UPDATE invoices SET public_token = NULL, updated_at = updated_at WHERE public_token = ''"); } catch (Throwable $e) { }
        foreach ($want as $table => $indexes) {
            $have = [];
            foreach ($db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['Key_name']] = true;
            foreach ($indexes as $name => $def) {
                if (!isset($have[$name])) {
                    try { $db->exec("ALTER TABLE `$table` ADD $def"); } catch (Throwable $e) { error_log("[qi] index $name: " . $e->getMessage()); }
                }
            }
        }
    }

    private static function backfill(PDO $db): void
    {
        // Every quote gets a public link token (older code paths created quotes without one).
        // updated_at is kept as it was: this is not a change to the document, and a
        // bulk bump would make every old quote look edited today (lists, Drive sweep).
        $ids = $db->query("SELECT id FROM quotes WHERE public_token IS NULL OR public_token = '' LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN);
        $st = $db->prepare("UPDATE quotes SET public_token = ?, updated_at = updated_at WHERE id = ? AND (public_token IS NULL OR public_token = '')");
        foreach ($ids as $id) $st->execute([bin2hex(random_bytes(16)), (int)$id]);

        // Recurring templates remember the day of the month they bill on
        $db->exec("UPDATE recurring_invoices SET anchor_day = DAY(next_run_date), updated_at = updated_at
                    WHERE anchor_day IS NULL AND next_run_date IS NOT NULL AND next_run_date > '1000-01-01'");
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
