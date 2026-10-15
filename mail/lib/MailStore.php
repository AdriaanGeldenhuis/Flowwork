<?php
// mail/lib/MailStore.php
//
// Write-side persistence: ingest raw messages from IMAP, thread them, store
// attachments on disk (storage/mail/...), remember correspondents, and delete
// messages together with their files.

class MailStore
{
    public const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /**
     * Ingest one raw message. Returns the new email_id, or null when it was a
     * duplicate (already stored for this account).
     *
     * @param array $flags IMAP flags (e.g. ['\Seen'])
     */
    public static function ingest(PDO $db, array $account, string $localFolder, ?string $serverFolder, ?int $uid,
                                  array $flags, ?string $internalDate, string $raw, bool $headersOnly = false): ?int
    {
        $accountId = (int)$account['account_id'];
        $companyId = (int)$account['company_id'];
        $p = MimeParser::parse($raw);

        $flagsLower = array_map('strtolower', array_map('strval', $flags));
        $isRead = in_array('\\seen', $flagsLower, true) ? 1 : 0;
        $isStarred = in_array('\\flagged', $flagsLower, true) ? 1 : 0;

        if ($uid && $serverFolder) {
            $st = $db->prepare("SELECT 1 FROM emails WHERE account_id = ? AND imap_folder = ? AND imap_uid = ? LIMIT 1");
            $st->execute([$accountId, $serverFolder, $uid]);
            if ($st->fetchColumn()) return null;
        }

        // Duplicate (same Message-ID already stored for this account)?
        if ($p['message_id']) {
            $st = $db->prepare("SELECT email_id, imap_uid, imap_folder FROM emails WHERE account_id = ? AND message_id = ? LIMIT 1");
            $st->execute([$accountId, $p['message_id']]);
            if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
                // e.g. a message we sent (stored locally first) showing up in the server's
                // Sent folder: bind it to its server copy so later moves/flags apply there.
                if ($uid && $serverFolder && (empty($dup['imap_uid']) || empty($dup['imap_folder']))) {
                    $db->prepare("UPDATE emails SET imap_uid = ?, imap_folder = ? WHERE email_id = ?")
                       ->execute([$uid, $serverFolder, $dup['email_id']]);
                }
                return null;
            }
        }

        // Rows written by the previous sync (no imap_folder, no Message-ID, raw MIME
        // stored as text): re-parse in place instead of creating a duplicate — but
        // only when the subject proves it is the same message (UIDs may have been
        // reassigned since).
        if ($uid && $serverFolder) {
            $st = $db->prepare("SELECT email_id, subject FROM emails
                                 WHERE account_id = ? AND imap_uid = ? AND imap_folder IS NULL LIMIT 1");
            $st->execute([$accountId, $uid]);
            if ($legacy = $st->fetch(PDO::FETCH_ASSOC)) {
                if (self::sameSubject((string)$legacy['subject'], $p['subject'])) {
                    self::repairLegacy($db, $account, (int)$legacy['email_id'], $p, $serverFolder, $uid, $headersOnly);
                    return null;
                }
                // A different message now owns that UID: the old binding is meaningless.
                $db->prepare("UPDATE emails SET imap_uid = NULL WHERE email_id = ?")->execute([(int)$legacy['email_id']]);
            }
        }

        // Mail sent from Flowwork before this version was stored without a
        // Message-ID; when the provider filed its own copy in Sent (Gmail, 365),
        // bind that copy to the existing row instead of listing it twice.
        if ($uid && $serverFolder && $localFolder === 'Sent') {
            $ts0 = $p['date'] ?? ($internalDate ? strtotime($internalDate) : null);
            $firstTo = $p['to'][0]['email'] ?? '';
            if ($ts0 && $firstTo !== '') {
                $st = $db->prepare("SELECT email_id FROM emails
                                     WHERE account_id = ? AND direction = 'outgoing' AND message_id IS NULL AND imap_folder IS NULL
                                       AND folder IN ('Sent','Archive','Trash') AND subject = ? AND to_recipients LIKE ?
                                       AND sent_at BETWEEN ? AND ? LIMIT 1");
                $st->execute([$accountId, mb_substr($p['subject'], 0, 500), '%' . MailRepo::likeEscape($firstTo) . '%',
                              date('Y-m-d H:i:s', $ts0 - 3 * 3600), date('Y-m-d H:i:s', $ts0 + 3 * 3600)]);
                if ($localId = (int)$st->fetchColumn()) {
                    try {
                        $db->prepare("UPDATE emails SET imap_folder = ?, imap_uid = ?, message_id = ? WHERE email_id = ?")
                           ->execute([$serverFolder, $uid, $p['message_id'], $localId]);
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') throw $e;
                    }
                    return null;
                }
            }
        }

        $fromEmail = $p['from']['email'] ?? '';
        $fromName = $p['from']['name'] ?? '';
        $myAddress = strtolower((string)$account['email_address']);
        $outgoing = $localFolder === 'Sent' || ($fromEmail !== '' && $fromEmail === $myAddress);
        if ($outgoing) $isRead = 1;

        $ts = $p['date'] ?? null;
        if (!$ts && $internalDate) $ts = strtotime($internalDate) ?: null;
        if (!$ts || $ts > time() + 86400) $ts = $internalDate ? (strtotime($internalDate) ?: time()) : time();
        $sentAt = date('Y-m-d H:i:s', $ts);

        $html = $p['html'];
        $text = $p['text'];
        if ($text === '' && $html !== '') $text = HtmlSanitizer::toText($html);
        if ($headersOnly) {
            $text = '[This message is larger than the 25 MB sync limit. Open it in your mail provider to see its contents.]';
            $html = '';
        }

        $attachments = $headersOnly ? [] : $p['attachments'];
        $hasFileAttachments = false;
        foreach ($attachments as $a) if (!$a['inline']) { $hasFileAttachments = true; break; }

        $subject = mb_substr($p['subject'], 0, 500);
        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            $threadId = self::findOrCreateThread($db, $account, $subject, $p['message_id'], $p['in_reply_to'], $p['references'], $sentAt, !$outgoing);

            $ins = $db->prepare("INSERT INTO emails
                (company_id, account_id, thread_id, imap_uid, imap_folder, folder, direction, sender, sender_name, reply_to,
                 to_recipients, cc_recipients, subject, body_html, body_text, sent_at, is_read, is_starred,
                 has_attachments, message_id, in_reply_to, ref_message_ids, list_unsubscribe, size_bytes, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $ins->execute([
                $companyId, $accountId, $threadId, $uid, $serverFolder, $localFolder,
                $outgoing ? 'outgoing' : 'incoming',
                mb_substr($fromEmail, 0, 500), mb_substr($fromName, 0, 255) ?: null,
                $p['reply_to'] ? mb_substr(MimeParser::formatAddressList($p['reply_to']), 0, 500) : null,
                MimeParser::formatAddressList($p['to']), MimeParser::formatAddressList($p['cc']),
                $subject, $html, $text, $sentAt, $isRead, $isStarred, $hasFileAttachments ? 1 : 0,
                $p['message_id'], $p['in_reply_to'], $p['references'] ? implode(' ', array_slice($p['references'], -30)) : null,
                $p['list_unsubscribe'] ? mb_substr($p['list_unsubscribe'], 0, 1000) : null, strlen($raw),
            ]);
            $emailId = (int)$db->lastInsertId();

            foreach ($attachments as $a) {
                if ($a['size'] > self::MAX_ATTACHMENT_BYTES) continue;
                self::storeAttachment($db, $companyId, $accountId, $emailId, $a['filename'], $a['mime'], $a['content'], $a['content_id'], $a['inline']);
            }

            // Keep CRM links on a conversation: a new reply inherits its thread's links.
            $db->prepare("INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at)
                          SELECT DISTINCT ?, ?, l.linked_type, l.linked_id, 0, NOW()
                            FROM email_links l JOIN emails e ON e.email_id = l.email_id
                           WHERE e.thread_id = ? AND e.account_id = ? AND e.email_id <> ?")
               ->execute([$companyId, $emailId, $threadId, $accountId, $emailId]);

            if ($ownTx) $db->commit();
        } catch (PDOException $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            if ($e->getCode() === '23000') return null; // lost a race with a parallel sync
            throw $e;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $people = $outgoing ? array_merge($p['to'], $p['cc']) : ($p['from'] ? [$p['from']] : []);
        self::rememberContacts($db, $companyId, (int)$account['user_id'], $people, false);
        return $emailId;
    }

    /** @return string 'repaired' | 'merged' */
    public static function repairLegacy(PDO $db, array $account, int $emailId, array $p, string $serverFolder,
                                        int $uid, bool $headersOnly): string
    {
        $html = $headersOnly ? '' : $p['html'];
        $text = $headersOnly ? '' : $p['text'];
        if ($text === '' && $html !== '') $text = HtmlSanitizer::toText($html);
        $hasFiles = false;
        foreach ($p['attachments'] as $a) if (!$a['inline']) { $hasFiles = true; break; }

        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            $db->prepare("UPDATE emails SET imap_folder = ?, imap_uid = ?, message_id = COALESCE(message_id, ?),
                                 sender = ?, sender_name = ?, to_recipients = ?, cc_recipients = ?, subject = ?,
                                 body_html = ?, body_text = ?, in_reply_to = ?, ref_message_ids = ?, has_attachments = ?
                           WHERE email_id = ?")
               ->execute([$serverFolder, $uid, $p['message_id'], $p['from']['email'] ?? '', $p['from']['name'] ?? null,
                          MimeParser::formatAddressList($p['to']), MimeParser::formatAddressList($p['cc']),
                          mb_substr($p['subject'], 0, 500), $html, $text, $p['in_reply_to'],
                          $p['references'] ? implode(' ', array_slice($p['references'], -30)) : null,
                          $hasFiles ? 1 : 0, $emailId]);
            $st = $db->prepare("SELECT COUNT(*) FROM email_attachments WHERE email_id = ?");
            $st->execute([$emailId]);
            if (!$headersOnly && (int)$st->fetchColumn() === 0) {
                foreach ($p['attachments'] as $a) {
                    if ($a['size'] > self::MAX_ATTACHMENT_BYTES) continue;
                    self::storeAttachment($db, (int)$account['company_id'], (int)$account['account_id'], $emailId,
                                          $a['filename'], $a['mime'], $a['content'], $a['content_id'], $a['inline']);
                }
            }
            if ($ownTx) $db->commit();
            return 'repaired';
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            if (!($e instanceof PDOException) || $e->getCode() !== '23000') throw $e;
        }
        // A proper copy of this message already exists (same server UID or
        // Message-ID): fold the legacy row's labels/links into it and drop it.
        $st = $db->prepare("SELECT email_id FROM emails WHERE account_id = ? AND email_id <> ?
                               AND ((imap_folder = ? AND imap_uid = ?) OR (message_id IS NOT NULL AND message_id = ?)) LIMIT 1");
        $st->execute([(int)$account['account_id'], $emailId, $serverFolder, $uid, (string)$p['message_id']]);
        $keep = (int)$st->fetchColumn();
        if ($keep) {
            self::mergeInto($db, $emailId, $keep);
            return 'merged';
        }
        // Nothing to merge with — just stop treating it as bound to that UID.
        $db->prepare("UPDATE emails SET imap_uid = NULL WHERE email_id = ?")->execute([$emailId]);
        return 'merged';
    }

    /** Move labels and links from a duplicate row onto the row we keep, then delete the duplicate. */
    public static function mergeInto(PDO $db, int $fromId, int $toId): void
    {
        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            $db->prepare("INSERT IGNORE INTO email_tag_map (email_id, tag_id) SELECT ?, tag_id FROM email_tag_map WHERE email_id = ?")
               ->execute([$toId, $fromId]);
            $db->prepare("INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at)
                          SELECT l.company_id, ?, l.linked_type, l.linked_id, l.created_by, l.created_at FROM email_links l
                           WHERE l.email_id = ? AND NOT EXISTS (SELECT 1 FROM email_links x WHERE x.email_id = ?
                                 AND x.linked_type = l.linked_type AND x.linked_id = l.linked_id)")
               ->execute([$toId, $fromId, $toId]);
            $db->prepare("UPDATE emails t JOIN emails f ON f.email_id = ? SET t.is_starred = GREATEST(t.is_starred, f.is_starred) WHERE t.email_id = ?")
               ->execute([$fromId, $toId]);
            self::deleteEmails($db, [$fromId]);
            if ($ownTx) $db->commit();
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function sameSubject(string $a, string $b): bool
    {
        return MimeParser::normalizeSubject($a) === MimeParser::normalizeSubject($b);
    }

    /**
     * Thread by References/In-Reply-To within the same mailbox, falling back to
     * the normalised subject for "Re:/Fwd:" messages from the last 60 days.
     */
    public static function findOrCreateThread(PDO $db, array $account, string $subject, ?string $messageId,
                                              ?string $inReplyTo, array $references, string $date, bool $incoming): int
    {
        $accountId = (int)$account['account_id'];
        $threadId = 0;

        $ids = array_values(array_unique(array_filter(array_merge($references, [$inReplyTo]))));
        if ($ids) {
            $ids = array_slice($ids, -30);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT thread_id FROM emails
                                 WHERE account_id = ? AND message_id IN ($in) AND thread_id > 0
                              ORDER BY email_id DESC LIMIT 1");
            $st->execute(array_merge([$accountId], $ids));
            $threadId = (int)$st->fetchColumn();
        }
        // Someone replying to a message whose own reply we already hold.
        if (!$threadId && $messageId) {
            $st = $db->prepare("SELECT thread_id FROM emails WHERE account_id = ? AND thread_id > 0
                                   AND (in_reply_to = ? OR ref_message_ids LIKE ?) LIMIT 1");
            $st->execute([$accountId, $messageId, '%' . MailRepo::likeEscape($messageId) . '%']);
            $threadId = (int)$st->fetchColumn();
        }
        $norm = mb_substr(MimeParser::normalizeSubject($subject), 0, 500);
        if (!$threadId && $norm !== '' && MimeParser::isReplySubject($subject)) {
            $st = $db->prepare("SELECT thread_id FROM email_threads
                                 WHERE account_id = ? AND subject_norm = ? AND last_message_at >= ?
                              ORDER BY last_message_at DESC LIMIT 1");
            $st->execute([$accountId, $norm, date('Y-m-d H:i:s', strtotime($date) - 60 * 86400)]);
            $threadId = (int)$st->fetchColumn();
        }

        if ($threadId) {
            $db->prepare("UPDATE email_threads SET last_message_at = GREATEST(last_message_at, ?),
                                 first_incoming_at = CASE WHEN ? = 1 AND first_incoming_at IS NULL THEN ? ELSE first_incoming_at END
                           WHERE thread_id = ?")
               ->execute([$date, $incoming ? 1 : 0, $date, $threadId]);
            return $threadId;
        }

        $db->prepare("INSERT INTO email_threads (company_id, account_id, subject, subject_norm, last_message_at, first_incoming_at, status, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())")
           ->execute([(int)$account['company_id'], $accountId, mb_substr($subject, 0, 500), $norm, $date, $incoming ? $date : null]);
        return (int)$db->lastInsertId();
    }

    /** Write bytes to storage/mail/<company>/<account>/<Ym>/<random>.bin and record the row. */
    public static function storeAttachment(PDO $db, int $companyId, int $accountId, int $emailId, string $filename,
                                           string $mime, string $content, ?string $contentId = null, bool $inline = false): int
    {
        $rel = self::writeFile($companyId, $accountId, $content);
        $db->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, mime_type, file_size, content_id, is_inline, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$emailId, MimeParser::safeFilename($filename), $rel, mb_substr($mime ?: 'application/octet-stream', 0, 100),
                      strlen($content), $contentId ? mb_substr($contentId, 0, 255) : null, $inline ? 1 : 0]);
        return (int)$db->lastInsertId();
    }

    public static function writeFile(int $companyId, int $accountId, string $content): string
    {
        $base = MailPaths::storageDir();
        if ($base === null) throw new RuntimeException('Mail storage is not writable');
        $sub = $companyId . '/' . $accountId . '/' . date('Ym');
        $dir = $base . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create attachment directory');
        }
        $name = bin2hex(random_bytes(16)) . '.bin';
        if (@file_put_contents($dir . '/' . $name, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write attachment');
        }
        return 'storage/mail/' . $sub . '/' . $name;
    }

    /** Copy an existing attachment row to another email (forwarding). */
    public static function copyAttachment(PDO $db, array $att, int $companyId, int $accountId, int $toEmailId): ?int
    {
        $path = MailPaths::resolveStored((string)$att['file_path']);
        if ($path === null) return null;
        $content = (string)file_get_contents($path);
        return self::storeAttachment($db, $companyId, $accountId, $toEmailId, $att['file_name'], (string)$att['mime_type'],
                                     $content, $att['content_id'] ?? null, (bool)($att['is_inline'] ?? false));
    }

    /** Hard-delete emails with their attachments, labels and links; prune empty threads. */
    public static function deleteEmails(PDO $db, array $emailIds): void
    {
        $emailIds = array_values(array_unique(array_filter(array_map('intval', $emailIds))));
        if (!$emailIds) return;
        $in = implode(',', array_fill(0, count($emailIds), '?'));

        $st = $db->prepare("SELECT file_path FROM email_attachments WHERE email_id IN ($in)");
        $st->execute($emailIds);
        $files = $st->fetchAll(PDO::FETCH_COLUMN);

        $st = $db->prepare("SELECT DISTINCT thread_id FROM emails WHERE email_id IN ($in) AND thread_id > 0");
        $st->execute($emailIds);
        $threads = $st->fetchAll(PDO::FETCH_COLUMN);

        foreach (['email_attachments', 'email_tag_map', 'email_links'] as $t) {
            $db->prepare("DELETE FROM $t WHERE email_id IN ($in)")->execute($emailIds);
        }
        $db->prepare("DELETE FROM emails WHERE email_id IN ($in)")->execute($emailIds);
        if ($threads) {
            $tin = implode(',', array_fill(0, count($threads), '?'));
            $db->prepare("DELETE t FROM email_threads t LEFT JOIN emails e ON e.thread_id = t.thread_id
                           WHERE t.thread_id IN ($tin) AND e.email_id IS NULL")->execute($threads);
        }
        foreach ($files as $f) {
            $p = MailPaths::resolveStored((string)$f);
            if ($p !== null && str_contains($p, DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR)) @unlink($p);
        }
    }

    /** Upsert correspondents for recipient autocomplete. */
    public static function rememberContacts(PDO $db, int $companyId, int $userId, array $addresses, bool $sent): void
    {
        if (!$addresses || $userId <= 0) return;
        try {
            $st = $db->prepare("INSERT INTO email_contacts (user_id, email, company_id, name, use_count, last_used_at)
                                VALUES (?, ?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE
                                    name = COALESCE(NULLIF(VALUES(name), ''), name),
                                    use_count = use_count + VALUES(use_count),
                                    last_used_at = NOW()");
            foreach (array_slice($addresses, 0, 50) as $a) {
                $email = strtolower(trim((string)($a['email'] ?? '')));
                if ($email === '' || strlen($email) > 190 || str_contains($email, 'noreply') || str_contains($email, 'no-reply')) continue;
                $st->execute([$userId, $email, $companyId, mb_substr((string)($a['name'] ?? ''), 0, 255), $sent ? 3 : 1]);
            }
        } catch (Throwable $e) {
            error_log('[mail] contacts upsert failed: ' . $e->getMessage());
        }
    }
}
