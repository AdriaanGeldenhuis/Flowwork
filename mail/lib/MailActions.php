<?php
// mail/lib/MailActions.php
//
// Mailbox mutations. The local database is updated immediately (instant UI);
// the matching server-side change is queued in email_imap_ops and applied at
// the start of the next sync, so phones/other clients stay consistent.

class MailActions
{
    public const ACTIONS = [
        'read', 'unread', 'star', 'unstar', 'archive', 'inbox', 'trash', 'restore',
        'spam', 'not_spam', 'delete_forever', 'snooze', 'unsnooze',
    ];

    /**
     * @param array $rows full emails rows (already ownership-checked)
     * @return int number of messages affected
     */
    public static function apply(PDO $db, array $rows, string $action, array $args = []): int
    {
        if (!in_array($action, self::ACTIONS, true) || !$rows) return 0;

        $maps = [];
        $mapFor = function (int $accountId) use ($db, &$maps): array {
            if (!isset($maps[$accountId])) {
                $st = $db->prepare("SELECT folder_map_json FROM email_accounts WHERE account_id = ?");
                $st->execute([$accountId]);
                $m = json_decode((string)$st->fetchColumn(), true);
                $maps[$accountId] = is_array($m) ? $m : [];
            }
            return $maps[$accountId];
        };

        $n = 0;
        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            switch ($action) {
                case 'read':
                case 'unread':
                    $val = $action === 'read' ? 1 : 0;
                    $upd = $db->prepare("UPDATE emails SET is_read = ? WHERE email_id = ?");
                    foreach ($rows as $r) {
                        if ((int)$r['is_read'] === $val) continue;
                        $upd->execute([$val, $r['email_id']]);
                        self::queueFlag($db, $r, $val ? '+' : '-', '\\Seen');
                        $n++;
                    }
                    break;

                case 'star':
                case 'unstar':
                    $val = $action === 'star' ? 1 : 0;
                    // Starring a conversation stars its latest message (Gmail-style);
                    // unstarring clears every star in it.
                    $targets = $val ? [end($rows)] : $rows;
                    $upd = $db->prepare("UPDATE emails SET is_starred = ? WHERE email_id = ?");
                    foreach ($targets as $r) {
                        if ((int)$r['is_starred'] === $val) continue;
                        $upd->execute([$val, $r['email_id']]);
                        self::queueFlag($db, $r, $val ? '+' : '-', '\\Flagged');
                        $n++;
                    }
                    break;

                case 'archive':
                    foreach ($rows as $r) {
                        if ($r['folder'] !== 'INBOX') continue;
                        self::moveLocal($db, $r, 'Archive');
                        $map = $mapFor((int)$r['account_id']);
                        $dest = $map['archive'] ?? ($map['all'] ?? null);
                        if ($dest) self::queueMove($db, $r, $dest);
                        $n++;
                    }
                    break;

                case 'inbox':
                case 'not_spam':
                    $from = $action === 'inbox' ? ['Archive', 'Spam', 'Trash'] : ['Spam'];
                    foreach ($rows as $r) {
                        if (!in_array($r['folder'], $from, true) || $r['direction'] === 'outgoing') continue;
                        self::moveLocal($db, $r, 'INBOX');
                        $map = $mapFor((int)$r['account_id']);
                        if (!empty($r['imap_folder']) && $r['imap_folder'] !== 'INBOX') {
                            // Gmail: "All Mail" holds every message; copy (re-label) instead of moving out of it.
                            $isAllMail = !empty($map['all']) && $r['imap_folder'] === $map['all'];
                            self::queueMove($db, $r, 'INBOX', $isAllMail ? 'copy' : 'move');
                        }
                        $n++;
                    }
                    break;

                case 'trash':
                    foreach ($rows as $r) {
                        if (in_array($r['folder'], ['Trash', 'Outbox'], true)) continue;
                        if ($r['folder'] === 'Drafts') {
                            MailStore::deleteEmails($db, [(int)$r['email_id']]);
                            $n++;
                            continue;
                        }
                        self::moveLocal($db, $r, 'Trash', $r['folder']);
                        $map = $mapFor((int)$r['account_id']);
                        if (!empty($map['trash'])) self::queueMove($db, $r, $map['trash']);
                        $n++;
                    }
                    break;

                case 'restore':
                    foreach ($rows as $r) {
                        if ($r['folder'] !== 'Trash') continue;
                        $back = $r['prev_folder'] ?: ($r['direction'] === 'outgoing' ? 'Sent' : 'INBOX');
                        if (!in_array($back, MailRepo::LOCAL_FOLDERS, true) || in_array($back, ['Trash', 'Outbox', 'Drafts'], true)) {
                            $back = $r['direction'] === 'outgoing' ? 'Sent' : 'INBOX';
                        }
                        self::moveLocal($db, $r, $back);
                        $map = $mapFor((int)$r['account_id']);
                        if (!empty($r['imap_folder']) && !empty($map['trash']) && $r['imap_folder'] === $map['trash']) {
                            $dest = $back === 'Sent' ? ($map['sent'] ?? null) : ($back === 'Archive' ? ($map['archive'] ?? 'INBOX') : 'INBOX');
                            if ($dest) self::queueMove($db, $r, $dest);
                        }
                        $n++;
                    }
                    break;

                case 'spam':
                    foreach ($rows as $r) {
                        if (in_array($r['folder'], ['Spam', 'Trash', 'Drafts', 'Outbox', 'Sent'], true)) continue;
                        self::moveLocal($db, $r, 'Spam', $r['folder']);
                        $map = $mapFor((int)$r['account_id']);
                        if (!empty($map['junk'])) self::queueMove($db, $r, $map['junk']);
                        $n++;
                    }
                    break;

                case 'delete_forever':
                    $ids = [];
                    foreach ($rows as $r) {
                        if (!in_array($r['folder'], ['Trash', 'Spam', 'Drafts'], true)) continue;
                        if (!empty($r['imap_folder']) && !empty($r['imap_uid'])) {
                            self::queueOp($db, $r, 'delete');
                        }
                        $ids[] = (int)$r['email_id'];
                    }
                    MailStore::deleteEmails($db, $ids);
                    $n = count($ids);
                    break;

                case 'snooze':
                    $until = (string)($args['until'] ?? '');
                    if ($until === '') break;
                    $upd = $db->prepare("UPDATE emails SET snoozed_until = ? WHERE email_id = ? AND folder = 'INBOX'");
                    foreach ($rows as $r) {
                        if ($r['folder'] !== 'INBOX') continue;
                        $upd->execute([$until, $r['email_id']]);
                        $n++;
                    }
                    break;

                case 'unsnooze':
                    $upd = $db->prepare("UPDATE emails SET snoozed_until = NULL WHERE email_id = ?");
                    foreach ($rows as $r) {
                        if ($r['snoozed_until'] === null) continue;
                        $upd->execute([$r['email_id']]);
                        $n++;
                    }
                    break;
            }
            if ($ownTx) $db->commit();
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $n;
    }

    private static function moveLocal(PDO $db, array $r, string $folder, ?string $prev = null): void
    {
        $db->prepare("UPDATE emails SET folder = ?, prev_folder = ?, snoozed_until = NULL WHERE email_id = ?")
           ->execute([$folder, $prev, $r['email_id']]);
    }

    private static function queueFlag(PDO $db, array $r, string $mode, string $flag): void
    {
        if (empty($r['imap_folder']) || empty($r['imap_uid'])) return;
        self::queueOp($db, $r, ($mode === '+' ? 'flag_add' : 'flag_remove'), $flag);
    }

    private static function queueMove(PDO $db, array $r, string $dest, string $mode = 'move'): void
    {
        if (empty($r['imap_folder']) || empty($r['imap_uid'])) return;
        if ($r['imap_folder'] === $dest) return;
        self::queueOp($db, $r, $mode, $dest);
    }

    public static function queueOp(PDO $db, array $r, string $op, ?string $arg = null): void
    {
        $db->prepare("INSERT INTO email_imap_ops (account_id, email_id, imap_folder, imap_uid, op, arg, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, NOW())")
           ->execute([(int)$r['account_id'], (int)$r['email_id'], $r['imap_folder'] ?? null,
                      isset($r['imap_uid']) ? (int)$r['imap_uid'] : null, $op, $arg]);
    }

    /** Apply/remove a label on messages (labels are local). */
    public static function label(PDO $db, array $rows, int $tagId, bool $on): int
    {
        $n = 0;
        $sql = $on ? "INSERT IGNORE INTO email_tag_map (email_id, tag_id) VALUES (?, ?)"
                   : "DELETE FROM email_tag_map WHERE email_id = ? AND tag_id = ?";
        $st = $db->prepare($sql);
        foreach ($rows as $r) {
            $st->execute([(int)$r['email_id'], $tagId]);
            $n += $st->rowCount();
        }
        return $n;
    }
}
