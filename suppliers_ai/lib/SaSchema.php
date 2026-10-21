<?php
// /suppliers_ai/lib/SaSchema.php — what Suppliers AI adds to the database,
// provisioned on first use (mirrored in Migrations/2026-10-24-suppliers-ai-rebuild.sql):
//
//   ai_queries.location / filters_json / results / external / ai_used / sources_json
//       where was searched, with which filters, how many were found, whether it
//       reached Google or OpenAI (those count against the hourly and daily ceilings)
//   ai_candidates.source / source_ref / rating / rating_count / maps_url / reasons_json / contact_name / blocked
//       where a result came from (crm, google), Google's place id and rating,
//       the reasons it was ranked where it was, and whether policy blocks it
//   ai_actions.action  enum → varchar(30) (the old bulk RFQ wrote 'rfq_bulk',
//       which the enum turned into ''), plus request_id
//   sai_requests            a request for quotes: what, where, when, requirements, budget (never sent), status,
//                           and when the background tick last looked for replies (it takes them in turn)
//   sai_request_suppliers   who was asked, how, when they replied, their quote, the decision
//   sai_events              what happened on a request, for its timeline

final class SaSchema
{
    public const VERSION = '2026-10-24-2';
    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;
        $marker = self::marker($db);
        if ($marker !== null && is_file($marker)) return;
        try {
            foreach (self::tables() as $sql) $db->exec($sql);
            self::columns($db, 'ai_queries', [
                'location' => "ADD COLUMN `location` varchar(160) DEFAULT NULL AFTER `q_text`",
                'filters_json' => "ADD COLUMN `filters_json` text DEFAULT NULL AFTER `parsed_json`",
                'results' => "ADD COLUMN `results` int(11) NOT NULL DEFAULT 0 AFTER `took_ms`",
                'external' => "ADD COLUMN `external` tinyint(1) NOT NULL DEFAULT 0 AFTER `results`",
                'ai_used' => "ADD COLUMN `ai_used` tinyint(1) NOT NULL DEFAULT 0 AFTER `external`",
                'sources_json' => "ADD COLUMN `sources_json` text DEFAULT NULL AFTER `ai_used`",
            ]);
            self::columns($db, 'ai_candidates', [
                'source' => "ADD COLUMN `source` varchar(20) DEFAULT NULL AFTER `account_id`",
                'source_ref' => "ADD COLUMN `source_ref` varchar(190) DEFAULT NULL AFTER `source`",
                'rating' => "ADD COLUMN `rating` decimal(2,1) DEFAULT NULL AFTER `distance_km`",
                'rating_count' => "ADD COLUMN `rating_count` int(11) DEFAULT NULL AFTER `rating`",
                'maps_url' => "ADD COLUMN `maps_url` varchar(500) DEFAULT NULL AFTER `rating_count`",
                'reasons_json' => "ADD COLUMN `reasons_json` text DEFAULT NULL AFTER `explanation`",
                'contact_name' => "ADD COLUMN `contact_name` varchar(160) DEFAULT NULL AFTER `reasons_json`",
                'blocked' => "ADD COLUMN `blocked` tinyint(1) NOT NULL DEFAULT 0 AFTER `contact_name`",
            ]);
            self::columns($db, 'sai_requests', [
                'requirements' => "ADD COLUMN `requirements` text DEFAULT NULL AFTER `category`",
                'replies_checked_at' => "ADD COLUMN `replies_checked_at` datetime DEFAULT NULL AFTER `due_notified_at`",
            ]);
            self::columns($db, 'ai_actions', [
                'request_id' => "ADD COLUMN `request_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `candidate_id`",
            ]);
            $st = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_actions' AND COLUMN_NAME = 'action'");
            if ($st && strtolower((string)$st->fetchColumn()) === 'enum') {
                $db->exec("ALTER TABLE `ai_actions` MODIFY `action` varchar(30) NOT NULL");
            }
            self::index($db, 'ai_queries', 'idx_sai_external', '(`company_id`, `external`, `created_at`)');
            self::index($db, 'ai_candidates', 'idx_sai_query', '(`query_id`)');
            self::index($db, 'ai_actions', 'idx_sai_action', '(`company_id`, `action`, `created_at`)');
            if ($marker !== null) @file_put_contents($marker, date('c'));
        } catch (Throwable $e) {
            error_log('[suppliers_ai] schema: ' . $e->getMessage());
        }
    }

    /** @return string[] */
    public static function tables(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `sai_requests` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` int(10) UNSIGNED NOT NULL,
              `title` varchar(190) NOT NULL,
              `details` text DEFAULT NULL,
              `quantity` varchar(80) DEFAULT NULL,
              `location` varchar(160) DEFAULT NULL,
              `category` varchar(40) DEFAULT NULL,
              `requirements` text DEFAULT NULL,
              `needed_by` date DEFAULT NULL,
              `quote_by` date DEFAULT NULL,
              `budget_cents` bigint(20) DEFAULT NULL,
              `status` varchar(12) NOT NULL DEFAULT 'draft',
              `query_id` bigint(20) UNSIGNED DEFAULT NULL,
              `awarded_id` bigint(20) UNSIGNED DEFAULT NULL,
              `po_id` bigint(20) UNSIGNED DEFAULT NULL,
              `created_by` int(10) UNSIGNED NOT NULL,
              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT NULL,
              `closed_at` datetime DEFAULT NULL,
              `deleted_at` datetime DEFAULT NULL,
              `due_notified_at` datetime DEFAULT NULL,
              `replies_checked_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_sai_req_company` (`company_id`, `deleted_at`, `status`),
              KEY `idx_sai_req_owner` (`company_id`, `created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `sai_request_suppliers` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` int(10) UNSIGNED NOT NULL,
              `request_id` bigint(20) UNSIGNED NOT NULL,
              `account_id` bigint(20) UNSIGNED NOT NULL,
              `candidate_id` bigint(20) UNSIGNED DEFAULT NULL,
              `name` varchar(255) NOT NULL,
              `channel` varchar(12) DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `sent_by` int(10) UNSIGNED DEFAULT NULL,
              `sent_to` varchar(500) DEFAULT NULL,
              `email_id` bigint(20) DEFAULT NULL,
              `thread_id` bigint(20) DEFAULT NULL,
              `replied_at` datetime DEFAULT NULL,
              `reply_email_id` bigint(20) DEFAULT NULL,
              `reply_notified_at` datetime DEFAULT NULL,
              `declined_at` datetime DEFAULT NULL,
              `quote_cents` bigint(20) DEFAULT NULL,
              `quote_vat` varchar(5) DEFAULT NULL,
              `lead_days` int(11) DEFAULT NULL,
              `valid_until` date DEFAULT NULL,
              `quote_notes` varchar(500) DEFAULT NULL,
              `quoted_at` datetime DEFAULT NULL,
              `quoted_by` int(10) UNSIGNED DEFAULT NULL,
              `decision` varchar(12) DEFAULT NULL,
              `decided_at` datetime DEFAULT NULL,
              `reminded_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_sai_rs` (`request_id`, `account_id`),
              KEY `idx_sai_rs_account` (`company_id`, `account_id`),
              KEY `idx_sai_rs_thread` (`thread_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `sai_events` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` int(10) UNSIGNED NOT NULL,
              `request_id` bigint(20) UNSIGNED NOT NULL,
              `recipient_id` bigint(20) UNSIGNED DEFAULT NULL,
              `user_id` int(10) UNSIGNED DEFAULT NULL,
              `action` varchar(30) NOT NULL,
              `payload_json` text DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_sai_ev_request` (`request_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    private static function marker(PDO $db): ?string
    {
        require_once __DIR__ . '/SaKit.php';
        $dir = SaKit::storageDir();
        if ($dir === null || !is_writable($dir)) return null;
        try { $name = (string)$db->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $name = ''; }
        return $dir . '/.schema-' . self::VERSION . '-' . substr(sha1($name), 0, 10);
    }

    private static function columns(PDO $db, string $table, array $want): void
    {
        $st = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]);
        $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        if (!$have) return; // table missing in this install
        $add = [];
        foreach ($want as $col => $sql) if (!isset($have[$col])) $add[] = $sql;
        if ($add) $db->exec("ALTER TABLE `$table` " . implode(', ', $add));
    }

    private static function index(PDO $db, string $table, string $name, string $cols): void
    {
        $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        $st->execute([$table, $name]);
        if (!$st->fetchColumn()) $db->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
    }
}
