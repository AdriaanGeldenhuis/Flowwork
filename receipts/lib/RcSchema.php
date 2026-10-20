<?php
// /receipts/lib/RcSchema.php — the columns Receipts adds, provisioned on first
// use (mirrored in Migrations/2026-10-22-receipts-rebuild.sql):
//
//   receipt_file.original_name        the name the file had on the phone or computer
//   receipt_file.sha1                 the same file sent in twice is caught
//   receipt_file.note                 what the person who sent it in said ("cement for the Smith job")
//   receipt_file.read_engine/_error/_at   how the slip was read, and why not
//   receipt_file.thumb_path           a small preview for the lists
//   receipt_file.po_id                the purchase order it is for
//   receipt_file.paid_method/_date/_bank_account_id   paid on the spot: Finance records the payment on posting
//   receipt_file.dismissed_at/_by/dismiss_reason       set aside (not a bill)
//   receipt_file.form_json            the review form as typed (VAT-inclusive prices, the slip's total)
//   receipt_settings.ai_model, default_due_days
//   receipt_ocr: one reading per receipt (older duplicate rows removed, then unique);
//                readings the old engine made up are cleared (clearInventedReadings)

final class RcSchema
{
    public const VERSION = '2026-10-22-1';
    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;
        $marker = self::marker($db);
        if ($marker !== null && is_file($marker)) return;
        try {
            self::columns($db, 'receipt_file', [
                'original_name' => "ADD COLUMN `original_name` varchar(255) DEFAULT NULL AFTER `path`",
                'sha1' => "ADD COLUMN `sha1` char(40) DEFAULT NULL AFTER `size_bytes`",
                'note' => "ADD COLUMN `note` varchar(500) DEFAULT NULL AFTER `ocr_status`",
                'read_engine' => "ADD COLUMN `read_engine` varchar(20) DEFAULT NULL AFTER `note`",
                'read_error' => "ADD COLUMN `read_error` varchar(255) DEFAULT NULL AFTER `read_engine`",
                'read_at' => "ADD COLUMN `read_at` datetime DEFAULT NULL AFTER `read_error`",
                'thumb_path' => "ADD COLUMN `thumb_path` varchar(255) DEFAULT NULL AFTER `read_at`",
                'po_id' => "ADD COLUMN `po_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `thumb_path`",
                'paid_method' => "ADD COLUMN `paid_method` varchar(10) DEFAULT NULL AFTER `po_id`",
                'paid_date' => "ADD COLUMN `paid_date` date DEFAULT NULL AFTER `paid_method`",
                'paid_bank_account_id' => "ADD COLUMN `paid_bank_account_id` int(10) UNSIGNED DEFAULT NULL AFTER `paid_date`",
                'dismissed_at' => "ADD COLUMN `dismissed_at` datetime DEFAULT NULL AFTER `paid_bank_account_id`",
                'dismissed_by' => "ADD COLUMN `dismissed_by` int(11) DEFAULT NULL AFTER `dismissed_at`",
                'dismiss_reason' => "ADD COLUMN `dismiss_reason` varchar(255) DEFAULT NULL AFTER `dismissed_by`",
                'form_json' => "ADD COLUMN `form_json` mediumtext DEFAULT NULL AFTER `dismiss_reason`",
            ]);
            self::index($db, 'receipt_file', 'idx_company_sha1', '(`company_id`, `sha1`)');
            self::index($db, 'receipt_file', 'idx_bill', '(`bill_id`)');
            self::columns($db, 'receipt_settings', [
                'ai_model' => "ADD COLUMN `ai_model` varchar(40) DEFAULT 'gpt-4o-mini' AFTER `ocr_provider`",
                'default_due_days' => "ADD COLUMN `default_due_days` smallint(5) UNSIGNED DEFAULT 30 AFTER `auto_split_lines`",
            ]);
            // One reading per receipt: keep the newest, then make it unique so a re-read replaces it.
            if (!self::hasIndex($db, 'receipt_ocr', 'uq_file')) {
                $db->exec("DELETE o1 FROM receipt_ocr o1 JOIN receipt_ocr o2 ON o1.file_id = o2.file_id AND o1.ocr_id < o2.ocr_id");
                $db->exec("ALTER TABLE `receipt_ocr` ADD UNIQUE KEY `uq_file` (`file_id`)");
            }
            self::clearInventedReadings($db);
            if ($marker !== null) @file_put_contents($marker, date('c'));
        } catch (Throwable $e) {
            error_log('[receipts] schema: ' . $e->getMessage());
        }
    }

    /**
     * The old reader made up a supplier ("Unknown Supplier"), an invoice number
     * and amounts whenever it could not read a slip, with confidence 0. Those
     * readings are cleared (on receipts not yet made into a bill) so nobody
     * books invented figures; the slip is then typed in.
     */
    public static function clearInventedReadings(PDO $db): void
    {
        $db->exec("UPDATE receipt_file rf JOIN receipt_ocr ro ON ro.file_id = rf.file_id
                      SET rf.ocr_status = 'failed', rf.read_error = 'The old reader could not read this slip — type the details in.'
                    WHERE ro.vendor_name = 'Unknown Supplier' AND COALESCE(ro.confidence_score, 0) = 0 AND rf.bill_id IS NULL");
        $db->exec("UPDATE receipt_ocr ro JOIN receipt_file rf ON rf.file_id = ro.file_id
                      SET ro.vendor_name = NULL, ro.vendor_vat = NULL, ro.invoice_number = NULL, ro.invoice_date = NULL,
                          ro.subtotal = NULL, ro.tax = NULL, ro.total = NULL, ro.line_items_json = '[]'
                    WHERE ro.vendor_name = 'Unknown Supplier' AND COALESCE(ro.confidence_score, 0) = 0 AND rf.bill_id IS NULL");
    }

    private static function marker(PDO $db): ?string
    {
        $dir = RcKit::storageDir();
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
