<?php
// mail/lib/MailOutbox.php
//
// Outgoing mail lifecycle:
//   Drafts (send_status=draft) → Outbox (queued, scheduled_at = now + undo
//   delay, or a chosen send time) → Sent.  Undo/cancel returns it to Drafts.
// Due messages are sent by the browser (outbox flush after the undo window)
// and by the cron worker as a safety net. The queued→sending transition is an
// atomic UPDATE, so a message is never sent twice. After sending, a copy is
// queued for APPEND to the server's Sent folder (unless the provider files it
// itself, e.g. Gmail).

class MailOutbox
{
    public const MAX_TOTAL_ATTACHMENTS = 25 * 1024 * 1024;

    /** @return array{email_id:int, thread_key:?string} */
    public static function saveDraft(PDO $db, int $companyId, int $userId, array $d): array
    {
        $account = MailRepo::account($db, $companyId, $userId, (int)($d['account_id'] ?? 0));
        if (!$account) throw new MailUserError('Choose an account to send from');

        $to  = MimeBuilder::normalizeList(self::listInput($d['to'] ?? []));
        $cc  = MimeBuilder::normalizeList(self::listInput($d['cc'] ?? []));
        $bcc = MimeBuilder::normalizeList(self::listInput($d['bcc'] ?? []));
        $subject = mb_substr(MimeBuilder::clean((string)($d['subject'] ?? '')), 0, 500);
        $html = HtmlSanitizer::sanitize((string)($d['body_html'] ?? ''), ['block_remote' => false, 'allow_local' => true, 'for_editor' => true])['html'];
        if (strlen($html) > 5 * 1024 * 1024) throw new MailUserError('The message body is too large');
        $text = HtmlSanitizer::toText($html);

        $threadId = null;
        if (!empty($d['thread_key'])) {
            $rows = MailRepo::emailsForKeys($db, $companyId, $userId, [(string)$d['thread_key']]);
            foreach ($rows as $r) if ((int)$r['thread_id'] > 0) { $threadId = (int)$r['thread_id']; break; }
        }
        $inReplyTo = MimeParser::cleanMessageId((string)($d['in_reply_to'] ?? '')) ?: null;
        $refs = trim(preg_replace('/[^\x21-\x7E ]/', '', (string)($d['references'] ?? '')));

        $draftId = (int)($d['draft_id'] ?? 0);
        if ($draftId > 0) {
            $row = MailRepo::email($db, $companyId, $userId, $draftId);
            if (!$row || !in_array($row['folder'], ['Drafts', 'Outbox'], true) || $row['send_status'] === 'sending') {
                throw new MailUserError('This draft can no longer be edited');
            }
            if ($row['folder'] === 'Outbox' && $row['send_status'] !== 'failed') {
                throw new MailUserError('This message is already queued to send');
            }
            $db->prepare("UPDATE emails SET account_id = ?, folder = 'Drafts', send_status = 'draft', scheduled_at = NULL,
                                 sender = ?, sender_name = ?, to_recipients = ?, cc_recipients = ?, bcc_recipients = ?,
                                 subject = ?, body_html = ?, body_text = ?, sent_at = NOW(),
                                 thread_id = COALESCE(?, thread_id), in_reply_to = COALESCE(?, in_reply_to),
                                 ref_message_ids = COALESCE(NULLIF(?, ''), ref_message_ids)
                           WHERE email_id = ?")
               ->execute([(int)$account['account_id'], $account['email_address'], self::fromName($db, $account),
                          MimeParser::formatAddressList($to), MimeParser::formatAddressList($cc), MimeParser::formatAddressList($bcc),
                          $subject, $html, $text, $threadId, $inReplyTo, $refs, $draftId]);
            // Attachments follow the draft to whichever account it is sent from.
            return ['email_id' => $draftId, 'thread_key' => $threadId ? 't' . $threadId : null];
        }

        $db->prepare("INSERT INTO emails (company_id, account_id, thread_id, folder, direction, sender, sender_name,
                             to_recipients, cc_recipients, bcc_recipients, subject, body_html, body_text, sent_at,
                             is_read, in_reply_to, ref_message_ids, send_status, created_at)
                      VALUES (?, ?, ?, 'Drafts', 'outgoing', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, 'draft', NOW())")
           ->execute([$companyId, (int)$account['account_id'], $threadId, $account['email_address'], self::fromName($db, $account),
                      MimeParser::formatAddressList($to), MimeParser::formatAddressList($cc), MimeParser::formatAddressList($bcc),
                      $subject, $html, $text, $inReplyTo, $refs !== '' ? $refs : null]);
        return ['email_id' => (int)$db->lastInsertId(), 'thread_key' => $threadId ? 't' . $threadId : null];
    }

    /** Move a saved draft to the Outbox. Sends immediately when there is no delay. */
    public static function queue(PDO $db, int $companyId, int $userId, int $draftId, ?int $sendAt, int $undoSeconds): array
    {
        $row = MailRepo::email($db, $companyId, $userId, $draftId);
        if (!$row || $row['folder'] !== 'Drafts') throw new MailUserError('Draft not found');
        $account = MailRepo::account($db, $companyId, $userId, (int)$row['account_id']);
        if (!$account || (int)$account['is_active'] !== 1) throw new MailUserError('The sending account is inactive');
        if (trim((string)$account['smtp_server']) === '') throw new MailUserError('This account has no outgoing (SMTP) server configured');

        $recipients = array_merge(
            MimeParser::parseAddressList((string)$row['to_recipients']),
            MimeParser::parseAddressList((string)$row['cc_recipients']),
            MimeParser::parseAddressList((string)$row['bcc_recipients'])
        );
        if (!$recipients) throw new MailUserError('Add at least one recipient');
        if (count($recipients) > 100) throw new MailUserError('A message can have at most 100 recipients');

        $st = $db->prepare("SELECT COALESCE(SUM(file_size), 0) FROM email_attachments WHERE email_id = ?");
        $st->execute([$draftId]);
        if ((int)$st->fetchColumn() > self::MAX_TOTAL_ATTACHMENTS) throw new MailUserError('Attachments exceed 25 MB in total');

        if ($sendAt !== null) {
            if ($sendAt < time() - 60) throw new MailUserError('The scheduled time is in the past');
            if ($sendAt > time() + 366 * 86400) throw new MailUserError('You can schedule up to a year ahead');
        }
        $when = $sendAt ?? (time() + max(0, $undoSeconds));
        $db->prepare("UPDATE emails SET folder = 'Outbox', send_status = 'queued', scheduled_at = ?, send_error = NULL
                       WHERE email_id = ? AND folder = 'Drafts'")
           ->execute([date('Y-m-d H:i:s', $when), $draftId]);

        if ($sendAt === null && $undoSeconds <= 0) {
            $r = self::sendOne($db, $draftId);
            return ['email_id' => $draftId, 'sent' => $r['ok'], 'error' => $r['error'] ?? null];
        }
        return ['email_id' => $draftId, 'queued' => true, 'send_at' => date('c', $when)];
    }

    public static function cancel(PDO $db, int $companyId, int $userId, int $emailId): bool
    {
        $st = $db->prepare("UPDATE emails e JOIN email_accounts a ON a.account_id = e.account_id
                               SET e.folder = 'Drafts', e.send_status = 'draft', e.scheduled_at = NULL, e.send_error = NULL
                             WHERE e.email_id = ? AND a.company_id = ? AND a.user_id = ?
                               AND e.folder = 'Outbox' AND e.send_status IN ('queued', 'failed')");
        $st->execute([$emailId, $companyId, $userId]);
        return $st->rowCount() === 1;
    }

    /** Send everything due (optionally for one user). */
    public static function flushDue(PDO $db, ?int $companyId = null, ?int $userId = null, int $limit = 10): array
    {
        // A send that crashed mid-way: surface it rather than risk a duplicate.
        $db->exec("UPDATE emails SET send_status = 'failed',
                          send_error = 'Sending was interrupted. Check your Sent folder before retrying.'
                    WHERE folder = 'Outbox' AND send_status = 'sending' AND scheduled_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

        $sql = "SELECT e.email_id FROM emails e JOIN email_accounts a ON a.account_id = e.account_id
                 WHERE e.folder = 'Outbox' AND e.send_status = 'queued' AND e.scheduled_at <= NOW()";
        $params = [];
        if ($userId !== null) { $sql .= " AND a.user_id = ? AND a.company_id = ?"; $params = [$userId, (int)$companyId]; }
        $sql .= " ORDER BY e.scheduled_at LIMIT " . max(1, $limit);
        $st = $db->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = self::sendOne($db, (int)$id);
        }
        return $out;
    }

    public static function sendOne(PDO $db, int $emailId): array
    {
        // scheduled_at becomes the claim time, so the stale-send sweep in flushDue
        // measures from when sending began, not from an old scheduled time.
        $claim = $db->prepare("UPDATE emails SET send_status = 'sending', scheduled_at = NOW()
                                WHERE email_id = ? AND folder = 'Outbox' AND send_status = 'queued'");
        $claim->execute([$emailId]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'skipped' => true, 'error' => 'Not queued'];

        $st = $db->prepare("SELECT * FROM emails WHERE email_id = ?");
        $st->execute([$emailId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT * FROM email_accounts WHERE account_id = ?");
        $st->execute([(int)$row['account_id']]);
        $account = $st->fetch(PDO::FETCH_ASSOC);

        try {
            $atts = [];
            $st = $db->prepare("SELECT * FROM email_attachments WHERE email_id = ? ORDER BY attachment_id");
            $st->execute([$emailId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $path = MailPaths::resolveStored((string)$a['file_path']);
                if ($path === null) throw new RuntimeException('Attachment "' . $a['file_name'] . '" is missing');
                $atts[] = ['filename' => $a['file_name'], 'mime' => $a['mime_type'], 'content' => (string)file_get_contents($path),
                           'cid' => $a['content_id'], 'inline' => (int)$a['is_inline'] === 1];
            }

            [$bodyHtml, $inlineAtts] = self::embedImages($db, (int)$account['user_id'], (string)$row['body_html'], $emailId, (int)$row['thread_id']);
            $atts = array_merge($atts, $inlineAtts);

            $res = SmtpSender::send([
                'host' => $account['smtp_server'], 'port' => (int)$account['smtp_port'],
                'encryption' => $account['smtp_encryption'], 'tls_verify' => (int)($account['tls_verify'] ?? 1) === 1,
                'username' => trim((string)$account['username']) ?: $account['email_address'],
                'password' => SecureVault::decrypt($account['password_encrypted'] ?? ''),
                'from' => $account['email_address'], 'from_name' => self::fromName($db, $account),
                'to' => [(string)$row['to_recipients']], 'cc' => [(string)$row['cc_recipients']], 'bcc' => [(string)$row['bcc_recipients']],
                'subject' => (string)$row['subject'], 'html' => $bodyHtml, 'text' => (string)$row['body_text'],
                'attachments' => $atts, 'in_reply_to' => $row['in_reply_to'], 'references' => $row['ref_message_ids'],
                'public_only' => true,
            ]);
            if (!$res['ok']) throw new RuntimeException($res['error'] ?? 'Send failed');
            SecureVault::upgrade($db, (int)$account['account_id'], $account['password_encrypted'] ?? null);
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 500);
            $db->prepare("UPDATE emails SET send_status = 'failed', send_error = ? WHERE email_id = ?")->execute([$msg, $emailId]);
            self::log($db, $emailId, 'failed', $msg);
            return ['ok' => false, 'error' => $msg];
        }

        $now = date('Y-m-d H:i:s');
        try {
            $db->prepare("UPDATE emails SET folder = 'Sent', send_status = 'sent', send_error = NULL, scheduled_at = NULL,
                                 sent_at = ?, message_id = ?, is_read = 1, size_bytes = ?
                           WHERE email_id = ?")
               ->execute([$now, $res['message_id'], strlen($res['raw'] ?? ''), $emailId]);
        } catch (PDOException $e) {
            // Message-ID clash is practically impossible; never report a sent mail as failed.
            $db->prepare("UPDATE emails SET folder = 'Sent', send_status = 'sent', send_error = NULL, scheduled_at = NULL, sent_at = ?, is_read = 1 WHERE email_id = ?")
               ->execute([$now, $emailId]);
        }

        // Thread bookkeeping.
        if ((int)$row['thread_id'] > 0) {
            $db->prepare("UPDATE email_threads SET last_message_at = GREATEST(last_message_at, ?), last_agent_reply_at = ?,
                                 first_response_at = COALESCE(first_response_at, ?)
                           WHERE thread_id = ?")
               ->execute([$now, $now, $now, (int)$row['thread_id']]);
        } else {
            $tid = MailStore::findOrCreateThread($db, $account, (string)$row['subject'], $res['message_id'],
                $row['in_reply_to'], $row['ref_message_ids'] ? preg_split('/\s+/', $row['ref_message_ids']) : [], $now, false);
            $db->prepare("UPDATE emails SET thread_id = ? WHERE email_id = ?")->execute([$tid, $emailId]);
            $db->prepare("UPDATE email_threads SET last_agent_reply_at = ? WHERE thread_id = ?")->execute([$now, $tid]);
        }

        // File a copy in the server's Sent folder.
        $map = MailRepo::folderMap($account);
        if ((int)($account['save_sent_copy'] ?? 1) === 1 && empty($map['gmail']) && trim((string)$account['imap_server']) !== ''
            && !empty($res['raw'])) {
            try {
                $base = MailPaths::storageDir();
                if ($base !== null) {
                    $dir = $base . '/spool';
                    if (!is_dir($dir)) @mkdir($dir, 0750, true);
                    $name = $emailId . '-' . bin2hex(random_bytes(6)) . '.eml';
                    if (@file_put_contents($dir . '/' . $name, $res['raw']) !== false) {
                        MailActions::queueOp($db, ['account_id' => (int)$account['account_id'], 'email_id' => $emailId,
                            'imap_folder' => $map['sent'] ?? null, 'imap_uid' => null], 'append', 'storage/mail/spool/' . $name);
                    }
                }
            } catch (Throwable $e) {
                error_log('[mail] could not queue Sent copy: ' . $e->getMessage());
            }
        }

        $recipients = array_merge(MimeParser::parseAddressList((string)$row['to_recipients']),
                                  MimeParser::parseAddressList((string)$row['cc_recipients']),
                                  MimeParser::parseAddressList((string)$row['bcc_recipients']));
        MailStore::rememberContacts($db, (int)$account['company_id'], (int)$account['user_id'], $recipients, true);
        self::log($db, $emailId, 'sent', !empty($res['rejected']) ? 'Rejected: ' . implode('; ', $res['rejected']) : null);
        return ['ok' => true, 'rejected' => $res['rejected'] ?? []];
    }

    /**
     * Images in the body that only work inside Flowwork — quoted/forwarded inline
     * images (/mail/api/attachment.php?id=N) and pasted data: URIs — become cid:
     * parts so recipients see them.
     * @return array{0:string,1:array}
     */
    /**
     * Turn local attachment images and data: images into inline MIME parts.
     * Only images that belong to this draft or to its own conversation are
     * embedded, so a crafted <img src> can never mail out another attachment.
     */
    public static function embedImages(PDO $db, int $ownerUserId, string $html, int $draftId = 0, int $threadId = 0): array
    {
        $atts = [];
        $n = 0;
        $html = preg_replace_callback('#(<img\b[^>]*?\bsrc=)(["\'])(.*?)\2#is', function ($m) use ($db, $ownerUserId, $draftId, $threadId, &$atts, &$n) {
            $src = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = null;
            $mime = 'image/png';
            $name = 'image';
            if (preg_match('#^/mail/api/attachment\.php\?id=(\d+)(&inline=1)?$#', $src, $mm)) {
                $st = $db->prepare("SELECT ea.file_name, ea.file_path, ea.mime_type FROM email_attachments ea
                                      JOIN emails e ON e.email_id = ea.email_id
                                      JOIN email_accounts a ON a.account_id = e.account_id
                                     WHERE ea.attachment_id = ? AND a.user_id = ?
                                       AND ea.mime_type LIKE 'image/%'
                                       AND (e.email_id = ? OR (? > 0 AND e.thread_id = ?))");
                $st->execute([(int)$mm[1], $ownerUserId, $draftId, $threadId, $threadId]);
                if ($a = $st->fetch(PDO::FETCH_ASSOC)) {
                    $path = MailPaths::resolveStored((string)$a['file_path']);
                    if ($path !== null) {
                        $content = (string)file_get_contents($path);
                        $mime = $a['mime_type'] ?: $mime;
                        $name = $a['file_name'];
                    }
                }
            } elseif (preg_match('#^data:(image/(?:png|jpe?g|gif|webp));base64,(.+)$#is', $src, $mm)) {
                $content = base64_decode(preg_replace('/\s+/', '', $mm[2]), true) ?: null;
                $mime = strtolower($mm[1]);
                $name = 'image.' . (str_contains($mime, 'jp') ? 'jpg' : substr($mime, 6));
            }
            if ($content === null || strlen($content) > 10 * 1024 * 1024) return $m[0];
            $cid = 'img' . (++$n) . '.' . bin2hex(random_bytes(6)) . '@flowwork';
            $atts[] = ['filename' => $name, 'mime' => $mime, 'content' => $content, 'cid' => $cid, 'inline' => true];
            return $m[1] . $m[2] . 'cid:' . $cid . $m[2];
        }, $html) ?? $html;
        return [$html, $atts];
    }

    public static function fromName(PDO $db, array $account): string
    {
        $name = trim((string)($account['display_name'] ?? ''));
        if ($name !== '') return $name;
        try {
            $st = $db->prepare("SELECT TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) FROM users WHERE id = ?");
            $st->execute([(int)$account['user_id']]);
            return trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function listInput($v): array
    {
        if (is_array($v)) return $v;
        return [(string)$v];
    }

    private static function log(PDO $db, int $emailId, string $status, ?string $message): void
    {
        try {
            $db->prepare("INSERT INTO email_send_log (email_id, status, message, sent_at) VALUES (?, ?, ?, NOW())")
               ->execute([$emailId, $status, $message]);
        } catch (Throwable $e) { /* optional */ }
    }
}

/** An error whose message is safe and useful to show to the user. */
class MailUserError extends RuntimeException {}
