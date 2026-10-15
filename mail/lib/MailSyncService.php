<?php
// mail/lib/MailSyncService.php
//
// IMAP synchronisation, per account:
//   1. Claim a lock (cron and the browser can both trigger syncs).
//   2. Discover special folders (Sent/Trash/Junk/Archive) via SPECIAL-USE
//      flags with name fallbacks.
//   3. Apply queued server-side ops (flags, moves, deletes, Sent appends).
//   4. INBOX and Sent: first run imports the last 30 days (max 200, newest
//      first); afterwards only UIDs above the stored cursor. UIDVALIDITY
//      changes reset the cursor safely.
//   5. Reconcile \Seen / \Flagged and messages removed elsewhere for the most
//      recent window, so reading on a phone marks it read here too.
// Work is time-boxed so a web request never runs into max_execution_time;
// the cursor is saved per message, so the next run resumes.

class MailSyncService
{
    public const INITIAL_DAYS = 30;
    public const INITIAL_MAX = 200;
    public const INCREMENTAL_MAX = 400;
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;
    public const FLAG_WINDOW = 300;

    /**
     * @param array{time_budget?:int} $opts
     * @return array{ok:bool, skipped?:bool, new:int, error?:string}
     */
    public static function syncAccount(PDO $db, array $acc, array $opts = []): array
    {
        $accountId = (int)$acc['account_id'];
        if ((int)($acc['is_active'] ?? 1) !== 1 || trim((string)($acc['imap_server'] ?? '')) === '') {
            return ['ok' => true, 'skipped' => true, 'new' => 0];
        }
        $budget = max(5, (int)($opts['time_budget'] ?? 20));
        $deadline = microtime(true) + $budget;

        $lock = $db->prepare("UPDATE email_accounts SET sync_lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                               WHERE account_id = ? AND (sync_lock_until IS NULL OR sync_lock_until < NOW())");
        $lock->execute([$budget + 120, $accountId]);
        if ($lock->rowCount() === 0) return ['ok' => true, 'skipped' => true, 'new' => 0];

        $logId = null;
        try {
            $db->prepare("INSERT INTO email_sync_log (account_id, folder, status, started_at) VALUES (?, 'ALL', 'success', NOW())")
               ->execute([$accountId]);
            $logId = (int)$db->lastInsertId();
        } catch (Throwable $e) { /* log table optional */ }

        $client = null;
        $new = 0;
        $incoming = [];
        try {
            $client = self::connect($acc);
            $map = self::detectFolders($db, $client, $acc);
            $acc['folder_map_json'] = json_encode($map);
            self::processOps($db, $client, $acc, $map);

            $targets = ['INBOX' => 'INBOX'];
            if (!empty($map['sent'])) $targets['Sent'] = $map['sent'];
            foreach ($targets as $local => $server) {
                if (microtime(true) > $deadline) break;
                $new += self::syncFolder($db, $client, $acc, $local, $server, $deadline, $incoming);
            }
            $client->logout();

            $db->prepare("UPDATE email_accounts SET last_sync_at = NOW(), last_error = NULL, sync_lock_until = NULL WHERE account_id = ?")
               ->execute([$accountId]);
            if ($logId) {
                $db->prepare("UPDATE email_sync_log SET messages_synced = ?, completed_at = NOW() WHERE log_id = ?")
                   ->execute([$new, $logId]);
            }
            self::notify($db, $acc, $incoming);
            return ['ok' => true, 'new' => $new];
        } catch (Throwable $e) {
            if ($client) $client->close();
            $msg = mb_substr($e->getMessage(), 0, 500);
            error_log('[mail] sync account ' . $accountId . ': ' . $msg);
            try {
                $db->prepare("UPDATE email_accounts SET last_error = ?, sync_lock_until = NULL WHERE account_id = ?")
                   ->execute([$msg, $accountId]);
                if ($logId) {
                    $db->prepare("UPDATE email_sync_log SET status = 'error', message = ?, messages_synced = ?, completed_at = NOW() WHERE log_id = ?")
                       ->execute([$msg, $new, $logId]);
                }
            } catch (Throwable $e2) { /* ignore */ }
            return ['ok' => false, 'new' => $new, 'error' => $msg];
        }
    }

    /** Sync a user's active accounts (browser-triggered), honouring a minimum interval. */
    public static function syncUser(PDO $db, int $companyId, int $userId, bool $force = false, int $budget = 20): array
    {
        $accounts = MailRepo::accounts($db, $companyId, $userId, true);
        $out = ['new' => 0, 'errors' => [], 'synced' => 0];
        $deadline = microtime(true) + $budget;
        foreach ($accounts as $acc) {
            $remaining = (int)floor($deadline - microtime(true));
            if ($remaining < 4) break;
            $last = $acc['last_sync_at'] ? strtotime($acc['last_sync_at']) : 0;
            if (!$force && $last && time() - $last < 60) continue;
            $r = self::syncAccount($db, $acc, ['time_budget' => $remaining]);
            if (!empty($r['skipped'])) continue;
            $out['synced']++;
            $out['new'] += $r['new'];
            if (!$r['ok']) $out['errors'][] = ['account_id' => (int)$acc['account_id'], 'account' => $acc['account_name'], 'error' => $r['error']];
        }
        return $out;
    }

    /** Backwards-compatible signature used by the previous worker. */
    public static function syncImapAccount(PDO $DB, array $account, int $companyId): int
    {
        $r = self::syncAccount($DB, $account, ['time_budget' => 240]);
        return (int)$r['new'];
    }

    public static function connect(array $acc): ImapClient
    {
        $c = new ImapClient(
            trim((string)$acc['imap_server']),
            (int)($acc['imap_port'] ?: 993),
            (string)($acc['imap_encryption'] ?: 'ssl'),
            (int)($acc['tls_verify'] ?? 1) === 1
        );
        $c->connect();
        $user = trim((string)($acc['username'] ?? '')) ?: (string)$acc['email_address'];
        $c->login($user, SecureVault::decrypt($acc['password_encrypted'] ?? ''));
        return $c;
    }

    /** Map special folders: sent, trash, junk, archive, drafts, all (+ gmail flag). */
    public static function detectFolders(PDO $db, ImapClient $c, array $acc): array
    {
        $folders = $c->listFolders();
        $map = ['gmail' => $c->hasCap('X-GM-EXT-1')];
        $byFlag = ['\\sent' => 'sent', '\\trash' => 'trash', '\\junk' => 'junk', '\\archive' => 'archive', '\\drafts' => 'drafts', '\\all' => 'all'];
        foreach ($folders as $f) {
            foreach ($f['flags'] as $fl) {
                if (isset($byFlag[$fl]) && empty($map[$byFlag[$fl]])) $map[$byFlag[$fl]] = $f['name'];
            }
        }
        $names = [
            'sent'    => ['sent', 'sent items', 'sent mail', 'sent messages', 'gesendet', 'gesendete elemente', 'envoyés', 'verzonden'],
            'trash'   => ['trash', 'deleted items', 'deleted messages', 'deleted', 'bin', 'papierkorb', 'corbeille'],
            'junk'    => ['junk', 'spam', 'junk e-mail', 'junk email', 'junk mail', 'bulk mail'],
            'archive' => ['archive', 'archives', 'archived'],
            'drafts'  => ['drafts', 'draft'],
        ];
        foreach ($names as $key => $candidates) {
            if (!empty($map[$key])) continue;
            foreach ($folders as $f) {
                $leaf = $f['display'];
                if ($f['delim'] !== '' && str_contains($leaf, $f['delim'])) {
                    $leaf = substr($leaf, strrpos($leaf, $f['delim']) + strlen($f['delim']));
                }
                if (in_array(mb_strtolower(trim($leaf)), $candidates, true)) { $map[$key] = $f['name']; break; }
            }
        }
        $json = json_encode($map);
        if ($json !== ($acc['folder_map_json'] ?? null)) {
            $db->prepare("UPDATE email_accounts SET folder_map_json = ? WHERE account_id = ?")->execute([$json, (int)$acc['account_id']]);
        }
        return $map;
    }

    private static function processOps(PDO $db, ImapClient $c, array $acc, array $map): void
    {
        $accountId = (int)$acc['account_id'];
        $st = $db->prepare("SELECT * FROM email_imap_ops WHERE account_id = ? ORDER BY op_id LIMIT 500");
        $st->execute([$accountId]);
        $ops = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$ops) return;

        $del = $db->prepare("DELETE FROM email_imap_ops WHERE op_id = ?");
        $fail = $db->prepare("UPDATE email_imap_ops SET attempts = attempts + 1, last_error = ? WHERE op_id = ?");

        foreach ($ops as $op) {
            $folder = (string)($op['imap_folder'] ?? '');
            $uid = (int)($op['imap_uid'] ?? 0);
            try {
                switch ($op['op']) {
                    case 'flag_add':
                    case 'flag_remove':
                        if ($folder === '' || !$uid) break;
                        $c->ensureSelected($folder);
                        $c->uidStore([$uid], $op['op'] === 'flag_add' ? '+' : '-', [(string)$op['arg']]);
                        break;

                    case 'move':
                    case 'copy':
                        if ($folder === '' || !$uid || empty($op['arg'])) break;
                        $c->ensureSelected($folder);
                        if ($op['op'] === 'move') {
                            $newMap = $c->uidMove([$uid], (string)$op['arg']);
                        } else {
                            $res = $c->command(['UID COPY ' . $uid . ' ', '"' . addcslashes((string)$op['arg'], "\"\\") . '"']);
                            $newMap = [];
                            foreach ($res['lines'] as $l) {
                                if (preg_match('/\[COPYUID \d+ (\d+) (\d+)\]/i', $l, $m)) $newMap[(int)$m[1]] = (int)$m[2];
                            }
                            if (preg_match('/\[COPYUID \d+ (\d+) (\d+)\]/i', $res['text'], $m)) $newMap[(int)$m[1]] = (int)$m[2];
                        }
                        $newUid = $newMap[$uid] ?? null;
                        if ($op['email_id']) {
                            // Unbind when the new UID is unknown; the next sync of that
                            // folder re-binds it by Message-ID.
                            $db->prepare("UPDATE emails SET imap_folder = ?, imap_uid = ?
                                           WHERE email_id = ? AND imap_folder = ? AND imap_uid = ?")
                               ->execute([$newUid ? (string)$op['arg'] : null, $newUid, (int)$op['email_id'], $folder, $uid]);
                        }
                        break;

                    case 'delete':
                        if ($folder === '' || !$uid) break;
                        $c->ensureSelected($folder);
                        $c->uidDelete([$uid]);
                        break;

                    case 'append':
                        $path = MailPaths::resolveStored((string)$op['arg']);
                        if ($folder === '') $folder = (string)($map['sent'] ?? '');
                        if ($path !== null && $folder !== '' && empty($map['gmail'])) {
                            $newUid = $c->append($folder, (string)file_get_contents($path), ['\\Seen']);
                            if ($op['email_id'] && $newUid) {
                                $db->prepare("UPDATE emails SET imap_folder = ?, imap_uid = ? WHERE email_id = ? AND imap_uid IS NULL")
                                   ->execute([$folder, $newUid, (int)$op['email_id']]);
                            }
                            @unlink($path);
                        }
                        break;
                }
                $del->execute([$op['op_id']]);
            } catch (ImapCommandException $e) {
                // The server rejected this op (message gone, folder missing…): retry a few times, then drop it.
                if ((int)$op['attempts'] >= 4) {
                    $del->execute([$op['op_id']]);
                    if ($op['op'] === 'append' && ($p = MailPaths::resolveStored((string)$op['arg']))) @unlink($p);
                } else {
                    $fail->execute([mb_substr($e->getMessage(), 0, 500), $op['op_id']]);
                }
            }
        }
    }

    private static function syncFolder(PDO $db, ImapClient $c, array $acc, string $local, string $server,
                                       float $deadline, array &$incoming): int
    {
        $accountId = (int)$acc['account_id'];
        $info = $c->select($server);

        $st = $db->prepare("SELECT * FROM email_folder_state WHERE account_id = ? AND folder = ?");
        $st->execute([$accountId, $server]);
        $state = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($state && $info['uidvalidity'] && (int)$state['uidvalidity'] !== $info['uidvalidity']) {
            // Server renumbered the folder: old UIDs are meaningless now.
            $db->prepare("UPDATE emails SET imap_uid = NULL, imap_folder = NULL WHERE account_id = ? AND imap_folder = ?")
               ->execute([$accountId, $server]);
            $db->prepare("DELETE FROM email_imap_ops WHERE account_id = ? AND imap_folder = ?")->execute([$accountId, $server]);
            $db->prepare("DELETE FROM email_folder_state WHERE account_id = ? AND folder = ?")->execute([$accountId, $server]);
            $state = null;
        }
        if (!$state) {
            $db->prepare("INSERT INTO email_folder_state (account_id, folder, uidvalidity, last_uid, initial_done) VALUES (?, ?, ?, 0, 0)")
               ->execute([$accountId, $server, $info['uidvalidity'] ?: null]);
            $state = ['last_uid' => 0, 'initial_done' => 0];
        }
        $saveState = $db->prepare("UPDATE email_folder_state SET last_uid = GREATEST(last_uid, ?), initial_done = ?, last_synced_at = NOW()
                                    WHERE account_id = ? AND folder = ?");

        if ($info['exists'] === 0) {
            $saveState->execute([max(0, $info['uidnext'] - 1), 1, $accountId, $server]);
            return 0;
        }

        $new = 0;
        if ((int)$state['initial_done'] !== 1) {
            $uids = $c->uidSearch('SINCE ' . date('d-M-Y', strtotime('-' . self::INITIAL_DAYS . ' days')));
            $uids = array_slice($uids, -self::INITIAL_MAX);
            $have = self::existingUids($db, $accountId, $server, $uids);
            $todo = array_values(array_diff($uids, $have));
            rsort($todo); // newest first so the inbox fills from the top
            $done = self::fetchAndIngest($db, $c, $acc, $local, $server, $todo, $deadline, $incoming, null);
            $new += $done['new'];
            if ($done['complete']) {
                $max = $uids ? max($uids) : 0;
                if ($info['uidnext'] > 0) $max = max($max, $info['uidnext'] - 1);
                if (!$max) {
                    $last = $c->uidFetch('*', 'UID');
                    $max = $last ? max(array_keys($last)) : 0;
                }
                $saveState->execute([$max, 1, $accountId, $server]);
            }
        } else {
            $last = (int)$state['last_uid'];
            $uids = array_values(array_filter($c->uidSearch('UID ' . ($last + 1) . ':*'), fn($u) => $u > $last));
            $uids = array_slice($uids, 0, self::INCREMENTAL_MAX);
            if ($uids) {
                $done = self::fetchAndIngest($db, $c, $acc, $local, $server, $uids, $deadline, $incoming,
                    fn(int $uid) => $saveState->execute([$uid, 1, $accountId, $server]));
                $new += $done['new'];
            } else {
                $saveState->execute([$last, 1, $accountId, $server]);
            }
            if (microtime(true) < $deadline) self::reconcile($db, $c, $accountId, $local, $server);
        }
        return $new;
    }

    private static function existingUids(PDO $db, int $accountId, string $server, array $uids): array
    {
        if (!$uids) return [];
        $have = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT imap_uid FROM emails WHERE account_id = ? AND imap_folder = ? AND imap_uid IN ($in)");
            $st->execute(array_merge([$accountId, $server], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $have[] = (int)$u;
        }
        return $have;
    }

    /** @return array{new:int, complete:bool} */
    private static function fetchAndIngest(PDO $db, ImapClient $c, array $acc, string $local, string $server,
                                           array $uids, float $deadline, array &$incoming, ?callable $onUid): array
    {
        $new = 0;
        foreach (array_chunk($uids, 25) as $batch) {
            if (microtime(true) > $deadline) return ['new' => $new, 'complete' => false];
            $meta = $c->uidFetch($batch, 'UID FLAGS INTERNALDATE RFC822.SIZE');

            // Group bodies into ~8 MB fetches.
            $groups = [];
            $cur = [];
            $curSize = 0;
            foreach ($batch as $uid) {
                if (!isset($meta[$uid])) { if ($onUid) $onUid($uid); continue; } // expunged meanwhile
                $size = (int)($meta[$uid]['RFC822.SIZE'] ?? 0);
                if ($size > self::MAX_MESSAGE_BYTES) { $groups[] = ['big' => [$uid]]; continue; }
                if ($cur && $curSize + $size > 8 * 1024 * 1024) { $groups[] = ['ok' => $cur]; $cur = []; $curSize = 0; }
                $cur[] = $uid;
                $curSize += $size;
            }
            if ($cur) $groups[] = ['ok' => $cur];

            foreach ($groups as $g) {
                if (microtime(true) > $deadline) return ['new' => $new, 'complete' => false];
                $headersOnly = isset($g['big']);
                $list = $g['big'] ?? $g['ok'];
                $bodies = $c->uidFetch($list, $headersOnly ? 'UID BODY.PEEK[HEADER]' : 'UID BODY.PEEK[]');
                // Process in the requested order (newest-first on the initial import).
                foreach ($list as $uid) {
                    $item = $bodies[$uid] ?? null;
                    $raw = $item ? (string)($item[$headersOnly ? 'BODY[HEADER]' : 'BODY[]'] ?? '') : '';
                    if ($raw !== '') {
                        $flags = is_array($meta[$uid]['FLAGS'] ?? null) ? $meta[$uid]['FLAGS'] : [];
                        try {
                            $emailId = MailStore::ingest($db, $acc, $local, $server, $uid, $flags,
                                                         $meta[$uid]['INTERNALDATE'] ?? null, $raw, $headersOnly);
                        } catch (Throwable $e) {
                            // Lost DB connection: stop. Anything else is a bad message — skip it
                            // rather than blocking the mailbox on it forever.
                            if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [2006, 2013], true)) throw $e;
                            error_log('[mail] could not store UID ' . $uid . ' in ' . $server . ': ' . $e->getMessage());
                            $emailId = null;
                        }
                        if ($emailId) {
                            $new++;
                            if ($local === 'INBOX') {
                                try {
                                    RulesEngine::applyToEmail($db, $emailId);
                                } catch (Throwable $e) {
                                    error_log('[mail] rules failed for ' . $emailId . ': ' . $e->getMessage());
                                }
                                $incoming[] = $emailId;
                            }
                        }
                    }
                    if ($onUid) $onUid($uid);
                }
            }
        }
        return ['new' => $new, 'complete' => true];
    }

    /** Mirror \Seen/\Flagged changes and removals made in other clients. */
    private static function reconcile(PDO $db, ImapClient $c, int $accountId, string $local, string $server): void
    {
        $st = $db->prepare("SELECT email_id, imap_uid, is_read, is_starred, folder FROM emails
                             WHERE account_id = ? AND imap_folder = ? AND imap_uid IS NOT NULL
                          ORDER BY imap_uid DESC LIMIT " . self::FLAG_WINDOW);
        $st->execute([$accountId, $server]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;

        $minUid = min(array_map(fn($r) => (int)$r['imap_uid'], $rows));
        $remote = $c->uidFetch($minUid . ':*', 'UID FLAGS');

        $pending = [];
        $ids = array_map(fn($r) => (int)$r['email_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $p = $db->prepare("SELECT DISTINCT email_id FROM email_imap_ops WHERE email_id IN ($in)");
        $p->execute($ids);
        foreach ($p->fetchAll(PDO::FETCH_COLUMN) as $id) $pending[(int)$id] = true;

        $upd = $db->prepare("UPDATE emails SET is_read = ?, is_starred = ? WHERE email_id = ?");
        $gone = $db->prepare("UPDATE emails SET imap_uid = NULL, imap_folder = NULL,
                                     folder = CASE WHEN folder = 'INBOX' THEN 'Archive' ELSE folder END
                               WHERE email_id = ?");
        foreach ($rows as $r) {
            $id = (int)$r['email_id'];
            if (isset($pending[$id])) continue;
            $uid = (int)$r['imap_uid'];
            if (!isset($remote[$uid])) {
                // Moved or deleted in another client. Never delete locally — archive it.
                $gone->execute([$id]);
                continue;
            }
            $flags = array_map('strtolower', array_map('strval', is_array($remote[$uid]['FLAGS'] ?? null) ? $remote[$uid]['FLAGS'] : []));
            $seen = in_array('\\seen', $flags, true) ? 1 : 0;
            $flagged = in_array('\\flagged', $flags, true) ? 1 : 0;
            if ($local === 'Sent') $seen = 1;
            if ($seen !== (int)$r['is_read'] || $flagged !== (int)$r['is_starred']) {
                $upd->execute([$seen, $flagged, $id]);
            }
        }
    }

    private static function notify(PDO $db, array $acc, array $emailIds): void
    {
        if (!$emailIds) return;
        try {
            $prefs = MailRepo::prefs($db, (int)$acc['company_id'], (int)$acc['user_id']);
            if (!$prefs['notification_enabled']) return;
            $in = implode(',', array_fill(0, count($emailIds), '?'));
            $st = $db->prepare("SELECT email_id, thread_id, sender, sender_name, subject FROM emails
                                 WHERE email_id IN ($in) AND folder = 'INBOX' AND is_read = 0 ORDER BY email_id DESC");
            $st->execute($emailIds);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return;
            $first = $rows[0];
            $n = count($rows);
            $who = MailRepo::displayName($first['sender_name'], $first['sender']);
            $title = $n === 1 ? 'New email from ' . $who : $n . ' new emails';
            $message = $n === 1 ? (string)$first['subject'] : 'Latest from ' . $who . ': ' . $first['subject'];
            $url = '/mail/?open=' . rawurlencode(MailRepo::keyFor($first));
            $db->prepare("INSERT INTO notifications (company_id, user_id, type, title, message, url, created_at, is_read)
                          VALUES (?, ?, 'mail_new', ?, ?, ?, NOW(), 0)")
               ->execute([(int)$acc['company_id'], (int)$acc['user_id'], mb_substr($title, 0, 255), mb_substr($message, 0, 500), $url]);
        } catch (Throwable $e) {
            // notifications table is optional on some installs
        }
    }
}
