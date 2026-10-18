<?php
// mail/lib/MailRepo.php
//
// Read-side queries. Every query is scoped to mail accounts the current user
// owns in the active company (email_accounts.company_id + user_id), so a
// thread/email id from another user can never be read or changed.
//
// Conversation keys: "t<thread_id>" for threaded mail, "e<email_id>" for a
// message without a thread (thread_id NULL/0, e.g. sent from CRM/QI).

class MailRepo
{
    public const VIEWS = ['inbox', 'starred', 'snoozed', 'drafts', 'outbox', 'sent', 'archive', 'spam', 'trash', 'all'];
    public const LOCAL_FOLDERS = ['INBOX', 'Sent', 'Drafts', 'Outbox', 'Archive', 'Spam', 'Trash'];
    /** email_links.linked_type values owned by the Projects module. */
    public const PROJECT_LINK_TYPES = ['project', 'board', 'item'];

    public const PREF_DEFAULTS = [
        'undo_send_seconds' => 10,
        'density'           => 'comfortable',  // comfortable | compact
        'reading_pane'      => 'right',        // right | off
        'default_reply'     => 'reply',        // reply | reply_all
        'smart_labels'      => true,
        'sla_enabled'       => false,
        'sync_minutes'      => 3,
        'show_snippets'     => true,
    ];

    // ---------------------------------------------------------------- accounts

    public static function accounts(PDO $db, int $companyId, int $userId, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM email_accounts WHERE company_id = ? AND user_id = ?"
             . ($activeOnly ? " AND is_active = 1" : "") . " ORDER BY account_name, account_id";
        $st = $db->prepare($sql);
        $st->execute([$companyId, $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function account(PDO $db, int $companyId, int $userId, int $accountId): ?array
    {
        $st = $db->prepare("SELECT * FROM email_accounts WHERE account_id = ? AND company_id = ? AND user_id = ?");
        $st->execute([$accountId, $companyId, $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Public (non-secret) shape of an account for the UI. */
    public static function publicAccount(array $a): array
    {
        return [
            'account_id'      => (int)$a['account_id'],
            'account_name'    => $a['account_name'],
            'display_name'    => $a['display_name'] ?? '',
            'email_address'   => $a['email_address'],
            'imap_server'     => $a['imap_server'] ?? '',
            'imap_port'       => (int)($a['imap_port'] ?? 993),
            'imap_encryption' => $a['imap_encryption'] ?? 'ssl',
            'smtp_server'     => $a['smtp_server'] ?? '',
            'smtp_port'       => (int)($a['smtp_port'] ?? 587),
            'smtp_encryption' => $a['smtp_encryption'] ?? 'tls',
            'username'        => $a['username'] ?? '',
            'has_password'    => !empty($a['password_encrypted']),
            'tls_verify'      => (int)($a['tls_verify'] ?? 1) === 1,
            'save_sent_copy'  => (int)($a['save_sent_copy'] ?? 1) === 1,
            'default_signature_id' => isset($a['default_signature_id']) ? (int)$a['default_signature_id'] : null,
            'is_active'       => (int)($a['is_active'] ?? 1) === 1,
            'last_sync_at'    => self::iso($a['last_sync_at'] ?? null),
            'last_error'      => $a['last_error'] ?? null,
        ];
    }

    public static function folderMap(array $account): array
    {
        $m = json_decode((string)($account['folder_map_json'] ?? ''), true);
        return is_array($m) ? $m : [];
    }

    // ------------------------------------------------------------------- prefs

    public static function prefs(PDO $db, int $companyId, int $userId): array
    {
        $row = null;
        try {
            $st = $db->prepare("SELECT * FROM user_mail_prefs WHERE user_id = ?");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $row = null;
        }
        $settings = self::PREF_DEFAULTS;
        if ($row && !empty($row['settings_json'])) {
            $s = json_decode($row['settings_json'], true);
            if (is_array($s)) $settings = array_merge($settings, array_intersect_key($s, self::PREF_DEFAULTS));
        }
        return [
            'block_external_images' => $row ? (int)$row['block_external_images'] === 1 : true,
            'notification_enabled'  => $row ? (int)$row['notification_enabled'] === 1 : true,
            'sla_default_hours'     => $row ? max(1, (int)$row['sla_default_hours']) : 24,
            'signature_id'          => $row && $row['signature_id'] !== null ? (int)$row['signature_id'] : null,
        ] + $settings;
    }

    // -------------------------------------------------------------- utilities

    public static function iso(?string $dt): ?string
    {
        if (!$dt || str_starts_with($dt, '0000')) return null;
        $ts = strtotime($dt);
        return $ts ? date('c', $ts) : null;
    }

    /** @return array{0:string,1:int}|null ['t'|'e', id] */
    public static function parseKey(string $key): ?array
    {
        if (preg_match('/^([te])(\d{1,19})$/', $key, $m)) return [$m[1], (int)$m[2]];
        return null;
    }

    public static function keyFor(array $row): string
    {
        $tid = (int)($row['thread_id'] ?? 0);
        return $tid > 0 ? 't' . $tid : 'e' . (int)$row['email_id'];
    }

    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, max(1, count($ids)), '?'));
    }

    public static function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    /** SQL condition for a view (alias e). */
    public static function viewCondition(string $view): string
    {
        switch ($view) {
            case 'inbox':   return "e.folder = 'INBOX' AND (e.snoozed_until IS NULL OR e.snoozed_until <= NOW())";
            case 'snoozed': return "e.folder = 'INBOX' AND e.snoozed_until > NOW()";
            case 'starred': return "e.is_starred = 1 AND e.folder NOT IN ('Trash','Spam','Drafts','Outbox')";
            case 'drafts':  return "e.folder = 'Drafts'";
            case 'outbox':  return "e.folder = 'Outbox'";
            case 'sent':    return "e.folder = 'Sent'";
            case 'archive': return "e.folder = 'Archive'";
            case 'spam':    return "e.folder = 'Spam'";
            case 'trash':   return "e.folder = 'Trash'";
            default:        return "e.folder NOT IN ('Trash','Spam','Drafts','Outbox')";
        }
    }

    // ------------------------------------------------------------- thread list

    /**
     * Parse the search box. Supports from:, to:, subject:, has:attachment,
     * is:unread|read|starred, in:<view>, label:<name>, before:/after:YYYY-MM-DD,
     * "quoted phrases" and free words.
     */
    public static function parseSearch(string $q): array
    {
        $out = ['terms' => [], 'from' => [], 'to' => [], 'subject' => [], 'has_att' => false,
                'is' => [], 'in' => null, 'label' => null, 'before' => null, 'after' => null];
        preg_match_all('/(\w+):"([^"]*)"|(\w+):(\S+)|"([^"]+)"|(\S+)/u', $q, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $op = strtolower($m[1] !== '' ? $m[1] : ($m[3] ?? ''));
            $val = $m[1] !== '' ? $m[2] : ($m[4] ?? '');
            if ($op !== '' && $val !== '') {
                switch ($op) {
                    case 'from': $out['from'][] = $val; continue 2;
                    case 'to': case 'cc': $out['to'][] = $val; continue 2;
                    case 'subject': $out['subject'][] = $val; continue 2;
                    case 'has': if (str_starts_with(strtolower($val), 'attach')) $out['has_att'] = true; continue 2;
                    case 'is': $out['is'][] = strtolower($val); continue 2;
                    case 'in': $v = strtolower($val); if (in_array($v, self::VIEWS, true)) $out['in'] = $v; continue 2;
                    case 'label': $out['label'] = $val; continue 2;
                    case 'before': case 'after':
                        $ts = strtotime($val);
                        if ($ts) $out[$op] = date('Y-m-d', $ts);
                        continue 2;
                }
                $out['terms'][] = $m[0];
                continue;
            }
            $term = ($m[5] ?? '') !== '' ? $m[5] : ($m[6] ?? '');
            if ($term !== '') $out['terms'][] = $term;
        }
        return $out;
    }

    /**
     * @param array{view?:string, account_id?:int, label_id?:int, q?:string, filter?:string, offset?:int, limit?:int} $p
     */
    public static function listThreads(PDO $db, int $companyId, int $userId, array $p): array
    {
        $accountIds = array_map(fn($a) => (int)$a['account_id'], self::accounts($db, $companyId, $userId));
        $limit = max(10, min(100, (int)($p['limit'] ?? 50)));
        $offset = max(0, (int)($p['offset'] ?? 0));
        if (!empty($p['account_id'])) {
            $accountIds = in_array((int)$p['account_id'], $accountIds, true) ? [(int)$p['account_id']] : [];
        }
        if (!$accountIds) return ['threads' => [], 'total' => 0, 'has_more' => false];

        $view = in_array($p['view'] ?? 'inbox', self::VIEWS, true) ? $p['view'] : 'inbox';
        $q = trim((string)($p['q'] ?? ''));
        $search = $q !== '' ? self::parseSearch($q) : null;
        if ($search) $view = $search['in'] ?? (!empty($p['label_id']) ? $view : 'all');

        $where = ["e.account_id IN (" . self::in($accountIds) . ")", self::viewCondition($view)];
        $params = $accountIds;

        $labelId = (int)($p['label_id'] ?? 0);
        if ($search && $search['label']) {
            $st = $db->prepare("SELECT tag_id FROM email_tags WHERE company_id IN (?, 0) AND name = ? ORDER BY company_id DESC LIMIT 1");
            $st->execute([$companyId, $search['label']]);
            $labelId = (int)$st->fetchColumn() ?: -1;
        }
        if ($labelId) {
            $where[] = "EXISTS (SELECT 1 FROM email_tag_map m WHERE m.email_id = e.email_id AND m.tag_id = ?)";
            $params[] = $labelId;
        }

        $filter = (string)($p['filter'] ?? '');
        if ($filter === 'unread') $where[] = "e.is_read = 0";
        if ($filter === 'starred') $where[] = "e.is_starred = 1";
        if ($filter === 'attachments') $where[] = "e.has_attachments = 1";

        if ($search) {
            foreach ($search['from'] as $v) {
                $where[] = "(e.sender LIKE ? OR e.sender_name LIKE ?)";
                $like = '%' . self::likeEscape($v) . '%';
                array_push($params, $like, $like);
            }
            foreach ($search['to'] as $v) {
                $where[] = "(e.to_recipients LIKE ? OR e.cc_recipients LIKE ?)";
                $like = '%' . self::likeEscape($v) . '%';
                array_push($params, $like, $like);
            }
            foreach ($search['subject'] as $v) {
                $where[] = "e.subject LIKE ?";
                $params[] = '%' . self::likeEscape($v) . '%';
            }
            if ($search['has_att']) $where[] = "e.has_attachments = 1";
            foreach ($search['is'] as $v) {
                if ($v === 'unread') $where[] = "e.is_read = 0";
                if ($v === 'read') $where[] = "e.is_read = 1";
                if ($v === 'starred') $where[] = "e.is_starred = 1";
            }
            if ($search['before']) { $where[] = "e.sent_at < ?"; $params[] = $search['before'] . ' 00:00:00'; }
            if ($search['after'])  { $where[] = "e.sent_at >= ?"; $params[] = $search['after'] . ' 00:00:00'; }
            foreach ($search['terms'] as $t) {
                $like = '%' . self::likeEscape($t) . '%';
                $where[] = "(e.subject LIKE ? OR e.sender LIKE ? OR e.sender_name LIKE ? OR e.to_recipients LIKE ? OR e.body_text LIKE ?)";
                array_push($params, $like, $like, $like, $like, $like);
            }
        }

        $whereSql = implode(' AND ', $where);
        $sortExpr = match ($view) {
            'snoozed' => "MIN(e.snoozed_until)",
            'outbox'  => "MAX(COALESCE(e.scheduled_at, e.created_at))",
            'inbox'   => "MAX(GREATEST(COALESCE(e.sent_at, e.created_at), COALESCE(e.snoozed_until, '1970-01-01')))",
            default   => "MAX(COALESCE(e.sent_at, e.created_at))",
        };
        $order = $view === 'snoozed' || $view === 'outbox' ? 'ASC' : 'DESC';

        $sql = "SELECT COALESCE(NULLIF(e.thread_id, 0), -e.email_id) AS gk,
                       $sortExpr AS sort_at,
                       COUNT(*) AS n, SUM(e.is_read = 0) AS unread,
                       MAX(e.is_starred) AS starred, MAX(e.has_attachments) AS att
                  FROM emails e
                 WHERE $whereSql
              GROUP BY gk
              ORDER BY sort_at $order, gk DESC
                 LIMIT " . ($limit + 1) . " OFFSET $offset";
        $st = $db->prepare($sql);
        $st->execute($params);
        $groups = $st->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($groups) > $limit;
        $groups = array_slice($groups, 0, $limit);

        $total = null;
        if ($offset === 0) {
            $ct = $db->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(e.thread_id, 0), -e.email_id)) FROM emails e WHERE $whereSql");
            $ct->execute($params);
            $total = (int)$ct->fetchColumn();
        }
        if (!$groups) return ['threads' => [], 'total' => $total ?? 0, 'has_more' => false, 'view' => $view];

        // Messages of these conversations (within the same view) for display.
        $threadIds = [];
        $emailIds = [];
        foreach ($groups as $g) {
            $gk = (int)$g['gk'];
            if ($gk > 0) $threadIds[] = $gk; else $emailIds[] = -$gk;
        }
        $parts = [];
        $mParams = [];
        if ($threadIds) {
            $parts[] = "e.thread_id IN (" . self::in($threadIds) . ")";
            $mParams = array_merge($mParams, $threadIds);
        }
        if ($emailIds) {
            $parts[] = "e.email_id IN (" . self::in($emailIds) . ")";
            $mParams = array_merge($mParams, $emailIds);
        }
        $msql = "SELECT e.email_id, e.thread_id, e.account_id, e.folder, e.direction, e.sender, e.sender_name,
                        e.to_recipients, e.subject, LEFT(e.body_text, 600) AS body_text, e.sent_at, e.created_at,
                        e.is_read, e.is_starred, e.has_attachments, e.snoozed_until, e.scheduled_at,
                        e.send_status, e.send_error
                   FROM emails e
                  WHERE (" . implode(' OR ', $parts) . ")
                    AND e.account_id IN (" . self::in($accountIds) . ")
                    AND " . self::viewCondition($view === 'starred' || $labelId || $search ? 'all' : $view) . "
               ORDER BY COALESCE(e.sent_at, e.created_at) DESC, e.email_id DESC";
        $ms = $db->prepare($msql);
        $ms->execute(array_merge($mParams, $accountIds));
        $byGroup = [];
        $allEmailIds = [];
        foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gk = (int)$r['thread_id'] > 0 ? (int)$r['thread_id'] : -(int)$r['email_id'];
            $byGroup[$gk][] = $r;
            $allEmailIds[] = (int)$r['email_id'];
        }
        $tags = self::tagsForEmails($db, $allEmailIds);

        $threads = [];
        foreach ($groups as $g) {
            $gk = (int)$g['gk'];
            $msgs = $byGroup[$gk] ?? [];
            if (!$msgs) continue;
            $latest = $msgs[0];
            $names = [];
            foreach (array_reverse($msgs) as $m) {
                if ($m['folder'] === 'Drafts') continue;
                $n = $m['direction'] === 'outgoing' ? 'me' : self::displayName($m['sender_name'], $m['sender']);
                $names[$n] = true;
            }
            if ($view === 'sent' || $view === 'drafts' || $view === 'outbox') {
                $to = MimeParser::parseAddressList((string)$latest['to_recipients']);
                $names = [];
                foreach ($to as $a) $names[self::displayName($a['name'], $a['email'])] = true;
                if (!$names) $names['(no recipients)'] = true;
            }
            $threadTags = [];
            foreach ($msgs as $m) foreach ($tags[(int)$m['email_id']] ?? [] as $t) $threadTags[$t['tag_id']] = $t;

            $hasDraft = false;
            foreach ($msgs as $m) if ($m['folder'] === 'Drafts') { $hasDraft = true; break; }

            $threads[] = [
                'key'          => $gk > 0 ? 't' . $gk : 'e' . (-$gk),
                'subject'      => (string)($latest['subject'] ?? ''),
                'snippet'      => HtmlSanitizer::snippet($latest['body_text'] ?? ''),
                'participants' => array_slice(array_keys($names), 0, 4),
                'from_email'   => $latest['direction'] === 'outgoing' ? '' : (string)$latest['sender'],
                'count'        => (int)$g['n'],
                'unread'       => (int)$g['unread'],
                'starred'      => (int)$g['starred'] === 1,
                'attachments'  => (int)$g['att'] === 1,
                'has_draft'    => $hasDraft,
                'date'         => self::iso($latest['sent_at'] ?? $latest['created_at']),
                'snoozed_until'=> self::iso($latest['snoozed_until'] ?? null),
                'scheduled_at' => self::iso($latest['scheduled_at'] ?? null),
                'send_status'  => $latest['send_status'] ?? null,
                'send_error'   => $latest['send_error'] ?? null,
                'latest_email_id' => (int)$latest['email_id'],
                'account_id'   => (int)$latest['account_id'],
                'labels'       => array_values($threadTags),
            ];
        }
        return ['threads' => $threads, 'total' => $total, 'has_more' => $hasMore, 'view' => $view];
    }

    public static function displayName(?string $name, ?string $email): string
    {
        $name = trim((string)$name);
        if ($name !== '') return $name;
        $email = (string)$email;
        // Legacy rows stored "Name <email>" in sender.
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>/', $email, $m)) return trim($m[1]);
        return $email !== '' ? $email : '(unknown)';
    }

    /** @return array<int, array<int, array{tag_id:int,name:string,color:string}>> */
    public static function tagsForEmails(PDO $db, array $emailIds): array
    {
        if (!$emailIds) return [];
        $emailIds = array_values(array_unique($emailIds));
        $st = $db->prepare("SELECT m.email_id, t.tag_id, t.name, COALESCE(NULLIF(t.color_hex, ''), t.color) AS color
                              FROM email_tag_map m JOIN email_tags t ON t.tag_id = m.tag_id
                             WHERE m.email_id IN (" . self::in($emailIds) . ")");
        $st->execute($emailIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['email_id']][] = ['tag_id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color'] ?: '#7b1e2b'];
        }
        return $out;
    }

    // ------------------------------------------------------------ conversation

    /**
     * Owned emails for a set of conversation keys.
     * $scope: 'all' (everything), 'visible' (exclude Trash/Spam/Outbox), or a view name.
     */
    public static function emailsForKeys(PDO $db, int $companyId, int $userId, array $keys, string $scope = 'all'): array
    {
        $accountIds = array_map(fn($a) => (int)$a['account_id'], self::accounts($db, $companyId, $userId));
        if (!$accountIds) return [];
        $threadIds = [];
        $emailIds = [];
        foreach ($keys as $k) {
            $p = self::parseKey((string)$k);
            if (!$p) continue;
            if ($p[0] === 't') $threadIds[] = $p[1]; else $emailIds[] = $p[1];
        }
        if (!$threadIds && !$emailIds) return [];
        $parts = [];
        $params = [];
        if ($threadIds) { $parts[] = "e.thread_id IN (" . self::in($threadIds) . ")"; $params = array_merge($params, $threadIds); }
        if ($emailIds) { $parts[] = "e.email_id IN (" . self::in($emailIds) . ")"; $params = array_merge($params, $emailIds); }
        $cond = '';
        if ($scope === 'visible') $cond = " AND e.folder NOT IN ('Trash','Spam','Outbox')";
        elseif ($scope !== 'all' && in_array($scope, self::VIEWS, true)) $cond = ' AND ' . self::viewCondition($scope);
        $sql = "SELECT e.* FROM emails e
                 WHERE (" . implode(' OR ', $parts) . ")
                   AND e.account_id IN (" . self::in($accountIds) . ")$cond
              ORDER BY COALESCE(e.sent_at, e.created_at) ASC, e.email_id ASC";
        $st = $db->prepare($sql);
        $st->execute(array_merge($params, $accountIds));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function email(PDO $db, int $companyId, int $userId, int $emailId): ?array
    {
        $st = $db->prepare("SELECT e.* FROM emails e
                              JOIN email_accounts a ON a.account_id = e.account_id
                             WHERE e.email_id = ? AND a.company_id = ? AND a.user_id = ?");
        $st->execute([$emailId, $companyId, $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function attachmentsFor(PDO $db, array $emailIds): array
    {
        if (!$emailIds) return [];
        $st = $db->prepare("SELECT attachment_id, email_id, file_name, mime_type, file_size, content_id, is_inline
                              FROM email_attachments WHERE email_id IN (" . self::in($emailIds) . ") ORDER BY attachment_id");
        $st->execute(array_values($emailIds));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['email_id']][] = $r;
        return $out;
    }

    public static function linksFor(PDO $db, int $companyId, array $emailIds): array
    {
        if (!$emailIds) return [];
        $st = $db->prepare("SELECT l.link_id, l.email_id, l.linked_type, l.linked_id
                              FROM email_links l
                             WHERE l.email_id IN (" . self::in($emailIds) . ")");
        $st->execute(array_values($emailIds));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        // Names for CRM accounts / QI documents (best effort; tables may differ per env).
        $crmIds = [];
        foreach ($rows as $r) if (in_array($r['linked_type'], ['customer', 'supplier'], true)) $crmIds[] = (int)$r['linked_id'];
        $names = [];
        if ($crmIds) {
            try {
                $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND id IN (" . self::in($crmIds) . ")");
                $st->execute(array_merge([$companyId], $crmIds));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $names['crm' . $r['id']] = $r['name'];
            } catch (Throwable $e) { /* ignore */ }
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $type = $r['linked_type'];
            $id = (int)$r['linked_id'];
            $sig = $type . ':' . $id;
            if (isset($seen[$sig])) continue;
            $seen[$sig] = true;
            if (in_array($type, self::PROJECT_LINK_TYPES, true)) {
                // Label + URL from the Projects module; deleted / other company → skipped.
                $d = null;
                try {
                    if (self::projectsAvailable()) $d = ProjectLink::describe($db, $companyId, $type, $id);
                } catch (Throwable $e) { /* projects tables missing */ }
                if ($d) $out[] = ['type' => $type, 'id' => $id, 'label' => (string)$d['label'], 'url' => (string)$d['url']];
                continue;
            }
            $isCrm = in_array($type, ['customer', 'supplier'], true);
            if ($isCrm && !isset($names['crm' . $id])) continue; // other company / deleted
            $url = match ($type) {
                'customer', 'supplier' => '/crm/account_view.php?id=' . $id,
                'invoice'     => '/qi/invoice_view.php?id=' . $id,
                'quote'       => '/qi/quote_view.php?id=' . $id,
                'credit_note' => '/qi/credit_note_view.php?id=' . $id,
                default       => null,
            };
            $out[] = [
                'type'  => $type,
                'id'    => $id,
                'label' => $isCrm ? $names['crm' . $id] : ucfirst(str_replace('_', ' ', $type)) . ' #' . $id,
                'url'   => $url,
            ];
        }
        return $out;
    }

    /** Loads the Projects library (ProjectLink); false when the module is not installed. */
    public static function projectsAvailable(): bool
    {
        if (class_exists('ProjectLink', false)) return true;
        $lib = MailPaths::root() . '/projects/lib/autoload.php';
        if (!is_file($lib)) return false;
        require_once $lib;
        return class_exists('ProjectLink', false);
    }

    // ------------------------------------------------------------------ counts

    public static function counts(PDO $db, int $companyId, int $userId): array
    {
        $accountIds = array_map(fn($a) => (int)$a['account_id'], self::accounts($db, $companyId, $userId));
        $out = ['inbox' => 0, 'drafts' => 0, 'outbox' => 0, 'spam' => 0, 'snoozed' => 0, 'starred' => 0, 'by_account' => [], 'by_label' => []];
        if (!$accountIds) return $out + ['latest_id' => 0];
        $in = self::in($accountIds);

        $st = $db->prepare("SELECT e.account_id,
                   COUNT(DISTINCT CASE WHEN e.folder='INBOX' AND e.is_read=0 AND (e.snoozed_until IS NULL OR e.snoozed_until<=NOW())
                                       THEN COALESCE(NULLIF(e.thread_id,0), -e.email_id) END) AS inbox,
                   SUM(e.folder='Drafts') AS drafts,
                   SUM(e.folder='Outbox') AS outbox,
                   COUNT(DISTINCT CASE WHEN e.folder='Spam' AND e.is_read=0 THEN COALESCE(NULLIF(e.thread_id,0), -e.email_id) END) AS spam,
                   COUNT(DISTINCT CASE WHEN e.folder='INBOX' AND e.snoozed_until > NOW() THEN COALESCE(NULLIF(e.thread_id,0), -e.email_id) END) AS snoozed
              FROM emails e
             WHERE e.account_id IN ($in) AND e.folder IN ('INBOX','Drafts','Outbox','Spam')
          GROUP BY e.account_id");
        $st->execute($accountIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (['inbox', 'drafts', 'outbox', 'spam', 'snoozed'] as $k) $out[$k] += (int)$r[$k];
            $out['by_account'][(int)$r['account_id']] = (int)$r['inbox'];
        }
        // Newest message id — lets the client notice mail delivered by cron.
        $st = $db->prepare("SELECT MAX(email_id) FROM emails WHERE account_id IN ($in)");
        $st->execute($accountIds);
        $latest = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT m.tag_id, COUNT(DISTINCT COALESCE(NULLIF(e.thread_id,0), -e.email_id)) AS n
                              FROM email_tag_map m JOIN emails e ON e.email_id = m.email_id
                             WHERE e.account_id IN ($in) AND e.is_read = 0 AND e.folder NOT IN ('Trash','Spam')
                          GROUP BY m.tag_id");
        $st->execute($accountIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['by_label'][(int)$r['tag_id']] = (int)$r['n'];
        $out['latest_id'] = $latest;
        return $out;
    }

    // ------------------------------------------------------------------ labels

    /** Company labels plus global (company 0) defaults not overridden by name. */
    public static function labels(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT tag_id, company_id, name, COALESCE(NULLIF(color_hex, ''), color) AS color
                              FROM email_tags WHERE company_id IN (?, 0) ORDER BY company_id DESC, name");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = mb_strtolower($r['name']);
            if (isset($out[$k])) continue;
            $out[$k] = ['tag_id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color'] ?: '#7b1e2b',
                        'global' => (int)$r['company_id'] === 0];
        }
        $list = array_values($out);
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    public static function labelAllowed(PDO $db, int $companyId, int $tagId): bool
    {
        $st = $db->prepare("SELECT 1 FROM email_tags WHERE tag_id = ? AND company_id IN (?, 0)");
        $st->execute([$tagId, $companyId]);
        return (bool)$st->fetchColumn();
    }
}
