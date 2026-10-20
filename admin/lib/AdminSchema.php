<?php
// /admin/lib/AdminSchema.php — the few columns the admin console adds,
// provisioned on first use (mirrored in Migrations/2026-10-20-admin-overhaul.sql).
//   invites.custom_message  the personal note sent with an invitation
//   invites.last_sent_at    when the invitation email last went out
//   invites.send_count      how many times it was sent

final class AdminSchema
{
    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;
        try {
            $st = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invites'");
            $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
            $add = [];
            if (!isset($have['custom_message'])) $add[] = "ADD COLUMN `custom_message` text DEFAULT NULL";
            if (!isset($have['last_sent_at'])) $add[] = "ADD COLUMN `last_sent_at` datetime DEFAULT NULL";
            if (!isset($have['send_count'])) $add[] = "ADD COLUMN `send_count` int(11) NOT NULL DEFAULT 0";
            if ($add) $db->exec("ALTER TABLE `invites` " . implode(', ', $add));
        } catch (Throwable $e) {
            error_log('[admin] schema: ' . $e->getMessage());
        }
    }
}
