<?php
// /shopping/lib/ShSchema.php — the columns Shopping adds, provisioned on first
// use (mirrored in Migrations/2026-10-23-shopping-rebuild.sql):
//
//   shopping_lists.needed_by / project_id / notes   when, for which project, a line about it
//   shopping_lists.store_id                         the store the list is planned for
//   shopping_lists.template_id                      the template it was made from
//   shopping_lists.done_at / deleted_at             finished; deleted (restorable)
//   shopping_items.category                         the aisle ("Building & hardware", "Groceries" …)
//   shopping_items.est_price_cents                  the price expected, per unit, VAT included
//   shopping_items.paid_cents                       what the line cost in the shop
//   shopping_items.store_id / bought_at / bought_by / bought_name   where, when and by whom (a guest's name via a link)
//   shopping_items.added_by / po_id                 who added it; the purchase order it went on
//   shopping_price_history.item_name / unit / qty / store_name / list_id / item_id / user_id   what was paid, kept even when the store goes
//   shopping_stores.created_by
//   shopping_preferences.price_prompt / keep_awake / group_by / default_purpose / home_store_id
//   shopping_share_tokens.revoked_at / last_used_at
//   Lists shared "by link" in the old module were visible to the whole company:
//   they become team lists (the same people see them).

final class ShSchema
{
    public const VERSION = '2026-10-23-1';
    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;
        $marker = self::marker($db);
        if ($marker !== null && is_file($marker)) return;
        try {
            self::columns($db, 'shopping_lists', [
                'needed_by' => "ADD COLUMN `needed_by` date DEFAULT NULL AFTER `budget_cents`",
                'project_id' => "ADD COLUMN `project_id` int(11) DEFAULT NULL AFTER `needed_by`",
                'notes' => "ADD COLUMN `notes` varchar(500) DEFAULT NULL AFTER `project_id`",
                'store_id' => "ADD COLUMN `store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `notes`",
                'template_id' => "ADD COLUMN `template_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `store_id`",
                'done_at' => "ADD COLUMN `done_at` datetime DEFAULT NULL AFTER `status`",
                'deleted_at' => "ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `done_at`",
            ]);
            self::index($db, 'shopping_lists', 'idx_company_status', '(`company_id`, `deleted_at`, `status`)');
            self::columns($db, 'shopping_items', [
                'category' => "ADD COLUMN `category` varchar(20) DEFAULT NULL AFTER `category_id`",
                'est_price_cents' => "ADD COLUMN `est_price_cents` bigint(20) DEFAULT NULL AFTER `category`",
                'paid_cents' => "ADD COLUMN `paid_cents` bigint(20) DEFAULT NULL AFTER `est_price_cents`",
                'store_id' => "ADD COLUMN `store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `paid_cents`",
                'bought_at' => "ADD COLUMN `bought_at` datetime DEFAULT NULL AFTER `store_id`",
                'bought_by' => "ADD COLUMN `bought_by` int(11) DEFAULT NULL AFTER `bought_at`",
                'bought_name' => "ADD COLUMN `bought_name` varchar(80) DEFAULT NULL AFTER `bought_by`",
                'added_by' => "ADD COLUMN `added_by` int(11) DEFAULT NULL AFTER `bought_name`",
                'po_id' => "ADD COLUMN `po_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `added_by`",
            ]);
            self::index($db, 'shopping_items', 'idx_bought', '(`list_id`, `status`, `bought_at`)');
            self::columns($db, 'shopping_price_history', [
                'item_name' => "ADD COLUMN `item_name` varchar(255) DEFAULT NULL AFTER `item_fingerprint`",
                'unit' => "ADD COLUMN `unit` varchar(20) DEFAULT NULL AFTER `item_name`",
                'qty' => "ADD COLUMN `qty` decimal(15,3) DEFAULT NULL AFTER `unit`",
                'store_name' => "ADD COLUMN `store_name` varchar(255) DEFAULT NULL AFTER `store_id`",
                'list_id' => "ADD COLUMN `list_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `store_name`",
                'item_id' => "ADD COLUMN `item_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `list_id`",
                'user_id' => "ADD COLUMN `user_id` int(11) DEFAULT NULL AFTER `item_id`",
            ]);
            self::index($db, 'shopping_price_history', 'idx_item', '(`item_id`)');
            self::columns($db, 'shopping_stores', [
                'created_by' => "ADD COLUMN `created_by` int(11) DEFAULT NULL AFTER `delivery_options_json`",
            ]);
            self::columns($db, 'shopping_preferences', [
                'price_prompt' => "ADD COLUMN `price_prompt` tinyint(1) NOT NULL DEFAULT 1 AFTER `route_mode`",
                'keep_awake' => "ADD COLUMN `keep_awake` tinyint(1) NOT NULL DEFAULT 1 AFTER `price_prompt`",
                'group_by' => "ADD COLUMN `group_by` varchar(10) NOT NULL DEFAULT 'aisle' AFTER `keep_awake`",
                'default_purpose' => "ADD COLUMN `default_purpose` varchar(20) NOT NULL DEFAULT 'general' AFTER `group_by`",
                'home_store_id' => "ADD COLUMN `home_store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `default_purpose`",
            ]);
            self::columns($db, 'shopping_share_tokens', [
                'revoked_at' => "ADD COLUMN `revoked_at` datetime DEFAULT NULL AFTER `expires_at`",
                'last_used_at' => "ADD COLUMN `last_used_at` datetime DEFAULT NULL AFTER `revoked_at`",
            ]);
            $db->exec("UPDATE shopping_lists SET shared_mode = 'team' WHERE shared_mode = 'token'");
            if ($marker !== null) @file_put_contents($marker, date('c'));
        } catch (Throwable $e) {
            error_log('[shopping] schema: ' . $e->getMessage());
        }
    }

    private static function marker(PDO $db): ?string
    {
        $dir = ShKit::storageDir();
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

    private static function hasIndex(PDO $db, string $table, string $name): bool
    {
        $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        $st->execute([$table, $name]);
        return (bool)$st->fetchColumn();
    }

    private static function index(PDO $db, string $table, string $name, string $cols): void
    {
        if (!self::hasIndex($db, $table, $name)) $db->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
    }
}
