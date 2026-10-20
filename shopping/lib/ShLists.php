<?php
// /shopping/lib/ShLists.php — shopping lists: who may see and change them, the
// cards on the overview, one list with its items, being out shopping (one
// shared session a list), the change stamp the pages poll, search and CSV.
//
// Who sees a list: its owner, and everyone in the company when it is a team
// list. Viewers only look. Renaming, sharing and deleting is for the owner
// (and an admin, for team lists).

final class ShLists
{
    /** Lists this person may see (alias l). */
    public static function visibleSql(int $userId, array &$args): string
    {
        $args[] = $userId;
        return "(l.owner_id = ? OR l.shared_mode IN ('team', 'token'))";
    }

    public static function load(PDO $db, int $companyId, int $listId, bool $lock = false): ?array
    {
        $st = $db->prepare("SELECT * FROM shopping_lists WHERE id = ? AND company_id = ? AND deleted_at IS NULL" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$listId, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function canSee(array $list, int $userId): bool
    {
        return (int)$list['owner_id'] === $userId || in_array($list['shared_mode'], ['team', 'token'], true);
    }

    public static function canEdit(array $list, int $userId, string $role): bool
    {
        return self::canSee($list, $userId) && ShKit::canWrite($role);
    }

    public static function canManage(array $list, int $userId, string $role): bool
    {
        return self::canEdit($list, $userId, $role) && ((int)$list['owner_id'] === $userId || ($list['shared_mode'] !== 'private' && ShKit::role($role) === 'admin'));
    }

    /**
     * The list, when this person may do $need with it ('see' | 'edit' | 'manage').
     * A list that is not theirs to see is "not found", not "forbidden".
     */
    public static function access(PDO $db, int $companyId, int $userId, string $role, int $listId, string $need = 'see', bool $lock = false): array
    {
        $list = $listId > 0 ? self::load($db, $companyId, $listId, $lock) : null;
        if (!$list || !self::canSee($list, $userId)) throw new ShProblem('That list is not there any more — it may have been deleted, or it is private.', 404);
        if ($need === 'edit' && !self::canEdit($list, $userId, $role)) throw new ShForbidden('You can look at this list but not change it.');
        if ($need === 'manage' && !self::canManage($list, $userId, $role)) throw new ShForbidden('Only ' . (ShKit::personName($db, (int)$list['owner_id']) ?: 'the owner') . ' can do that with this list.');
        return $list;
    }

    /** Bump the list so its change stamp and "recently changed" order move. */
    public static function touch(PDO $db, int $listId): void
    {
        $db->prepare("UPDATE shopping_lists SET updated_at = NOW() WHERE id = ?")->execute([$listId]);
    }

    // ------------------------------------------------------------------ cards

    /** Every list this person may see, with its numbers. */
    public static function cards(PDO $db, int $companyId, int $userId, string $role, ?int $onlyId = null): array
    {
        $args = [$companyId, $companyId];
        $vis = self::visibleSql($userId, $args);
        $one = '';
        if ($onlyId) { $one = ' AND l.id = ?'; $args[] = $onlyId; }
        $st = $db->prepare("SELECT l.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS owner_name,
                                   p.name AS project_name, st.name AS store_name,
                                   COALESCE(a.n, 0) AS n, COALESCE(a.bought, 0) AS bought, COALESCE(a.est_left, 0) AS est_left, COALESCE(a.paid, 0) AS paid,
                                   COALESCE(a.urgent, 0) AS urgent, a.next_need, s.started_by AS shop_by, s.started_at AS shop_since
                              FROM shopping_lists l
                         LEFT JOIN users u ON u.id = l.owner_id
                         LEFT JOIN projects p ON p.project_id = l.project_id AND p.company_id = l.company_id
                         LEFT JOIN shopping_stores st ON st.id = l.store_id AND st.company_id = l.company_id
                         LEFT JOIN (SELECT i.list_id, COUNT(*) n, SUM(i.status = 'bought') bought,
                                           SUM(CASE WHEN i.status <> 'bought' THEN ROUND(i.qty * COALESCE(i.est_price_cents, 0)) ELSE 0 END) est_left,
                                           SUM(CASE WHEN i.status = 'bought' THEN COALESCE(i.paid_cents, 0) ELSE 0 END) paid,
                                           SUM(i.status <> 'bought' AND i.priority = 'high') urgent,
                                           MIN(CASE WHEN i.status <> 'bought' THEN DATE(i.needed_by) END) next_need
                                      FROM shopping_items i JOIN shopping_lists il ON il.id = i.list_id AND il.company_id = ?
                                     WHERE i.status <> 'removed' GROUP BY i.list_id) a ON a.list_id = l.id
                         LEFT JOIN (SELECT list_id, MIN(started_by) started_by, MIN(started_at) started_at FROM shopping_sessions WHERE ended_at IS NULL GROUP BY list_id) s ON s.list_id = l.id
                             WHERE l.company_id = ? AND l.deleted_at IS NULL AND $vis$one
                          ORDER BY FIELD(l.status, 'buying', 'open', 'done'), CASE WHEN l.status = 'done' THEN l.done_at END DESC, l.updated_at DESC, l.id DESC
                             LIMIT 300");
        $st->execute($args);
        return array_map(fn($r) => self::card($db, $r, $userId, $role), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function card(PDO $db, array $r, int $userId, string $role): array
    {
        $n = (int)$r['n'];
        $bought = (int)$r['bought'];
        $need = $r['needed_by'] ? substr((string)$r['needed_by'], 0, 10) : null;
        $next = $r['next_need'] ?? null;
        $due = $need && (!$next || $need < $next) ? $need : ($next ?: $need);
        $budget = (int)($r['budget_cents'] ?? 0);
        $paid = (int)$r['paid'];
        $estLeft = (int)$r['est_left'];
        return [
            'id' => (int)$r['id'], 'name' => (string)$r['name'],
            'purpose' => (string)$r['purpose'], 'purpose_label' => ShKit::PURPOSES[$r['purpose']] ?? 'General',
            'owner_id' => (int)$r['owner_id'], 'owner' => trim((string)$r['owner_name']), 'mine' => (int)$r['owner_id'] === $userId,
            'shared' => $r['shared_mode'] === 'private' ? 'private' : 'team',
            'status' => (string)$r['status'], 'budget' => $budget ?: null, 'needed_by' => $need, 'due' => $due, 'due_label' => ShKit::due($due),
            'late' => $due !== null && $due < date('Y-m-d') && $r['status'] !== 'done',
            'project_id' => $r['project_id'] ? (int)$r['project_id'] : null, 'project' => $r['project_name'] ?? null,
            'store_id' => $r['store_id'] ? (int)$r['store_id'] : null, 'store' => $r['store_name'] ?? null,
            'notes' => (string)($r['notes'] ?? ''),
            'items' => $n, 'bought' => $bought, 'left' => $n - $bought, 'urgent' => (int)$r['urgent'],
            'paid' => $paid, 'est_left' => $estLeft, 'est_total' => $paid + $estLeft,
            'over_budget' => $budget > 0 && $paid + $estLeft > $budget,
            'shopping' => $r['shop_by'] ? ['by' => (int)$r['shop_by'], 'name' => ShKit::personName($db, (int)$r['shop_by']), 'since' => (string)$r['shop_since']] : null,
            'created_at' => (string)$r['created_at'], 'updated_at' => (string)$r['updated_at'], 'done_at' => $r['done_at'] ?? null,
            'edit' => self::canEdit($r, $userId, $role), 'manage' => self::canManage($r, $userId, $role),
        ];
    }

    /** The sidebar and the Home tile. */
    public static function navCounts(PDO $db, int $companyId, int $userId): array
    {
        $out = ['open' => 0, 'shopping' => 0, 'items' => 0, 'month' => 0, 'month_items' => 0, 'lists' => []];
        try {
            $args = [$companyId];
            $vis = self::visibleSql($userId, $args);
            $st = $db->prepare("SELECT l.id, l.name, l.purpose, l.status,
                                       (SELECT COUNT(*) FROM shopping_items i WHERE i.list_id = l.id AND i.status NOT IN ('bought', 'removed')) AS left_n
                                  FROM shopping_lists l WHERE l.company_id = ? AND l.deleted_at IS NULL AND l.status IN ('open', 'buying') AND $vis
                              ORDER BY FIELD(l.status, 'buying', 'open'), l.updated_at DESC LIMIT 60");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['open']++;
                if ($r['status'] === 'buying') $out['shopping']++;
                $out['items'] += (int)$r['left_n'];
                if (count($out['lists']) < 8) $out['lists'][] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'purpose' => (string)$r['purpose'], 'left' => (int)$r['left_n'], 'shopping' => $r['status'] === 'buying'];
            }
            $args = [$companyId, date('Y-m-01')];
            $vis = self::visibleSql($userId, $args);
            $st = $db->prepare("SELECT COALESCE(SUM(i.paid_cents), 0), COUNT(*) FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                                 WHERE l.company_id = ? AND l.deleted_at IS NULL AND i.status = 'bought' AND i.bought_at >= ? AND $vis");
            $st->execute($args);
            [$out['month'], $out['month_items']] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        } catch (Throwable $e) {
            error_log('[shopping] nav counts: ' . $e->getMessage());
        }
        return $out;
    }

    /** The overview: what is needed soon, what was bought lately, where the money went this month. */
    public static function overview(PDO $db, int $companyId, int $userId, string $role): array
    {
        $soon = date('Y-m-d', strtotime('+7 days'));
        $args = [$companyId, $soon];
        $vis = self::visibleSql($userId, $args);
        $st = $db->prepare("SELECT i.id, i.list_id, i.name_raw, i.qty, i.unit, i.priority, l.name AS list_name, DATE(COALESCE(i.needed_by, l.needed_by)) AS due
                              FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                             WHERE l.company_id = ? AND l.deleted_at IS NULL AND l.status IN ('open', 'buying') AND i.status NOT IN ('bought', 'removed')
                               AND COALESCE(i.needed_by, l.needed_by) IS NOT NULL AND DATE(COALESCE(i.needed_by, l.needed_by)) <= ? AND $vis
                          ORDER BY due, i.priority = 'high' DESC, i.id LIMIT 8");
        $st->execute($args);
        $neededRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $args = [date('Y-m-d'), $companyId, $soon];
        $vis = self::visibleSql($userId, $args);
        $cnt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(DATE(COALESCE(i.needed_by, l.needed_by)) < ?), 0)
                               FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                              WHERE l.company_id = ? AND l.deleted_at IS NULL AND l.status IN ('open', 'buying') AND i.status NOT IN ('bought', 'removed')
                                AND COALESCE(i.needed_by, l.needed_by) IS NOT NULL AND DATE(COALESCE(i.needed_by, l.needed_by)) <= ? AND $vis");
        $cnt->execute($args);
        [$neededCount, $lateCount] = array_map('intval', $cnt->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        $needed = array_map(fn($r) => ['id' => (int)$r['id'], 'list_id' => (int)$r['list_id'], 'name' => (string)$r['name_raw'], 'qty' => ShKit::qtyText($r['qty']), 'unit' => (string)$r['unit'],
                                        'list' => (string)$r['list_name'], 'due' => (string)$r['due'], 'due_label' => ShKit::due((string)$r['due']), 'late' => $r['due'] < date('Y-m-d'), 'urgent' => $r['priority'] === 'high'],
                             $neededRows);

        $args = [$companyId];
        $vis = self::visibleSql($userId, $args);
        $st = $db->prepare("SELECT i.id, i.list_id, i.name_raw, i.qty, i.unit, i.paid_cents, i.bought_at, i.bought_by, i.bought_name, l.name AS list_name, s.name AS store_name
                              FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                         LEFT JOIN shopping_stores s ON s.id = i.store_id AND s.company_id = l.company_id
                             WHERE l.company_id = ? AND l.deleted_at IS NULL AND i.status = 'bought' AND i.bought_at IS NOT NULL AND $vis
                          ORDER BY i.bought_at DESC, i.id DESC LIMIT 8");
        $st->execute($args);
        $recent = array_map(fn($r) => ['id' => (int)$r['id'], 'list_id' => (int)$r['list_id'], 'name' => (string)$r['name_raw'], 'qty' => ShKit::qtyText($r['qty']), 'unit' => (string)$r['unit'],
                                        'paid' => $r['paid_cents'] !== null ? (int)$r['paid_cents'] : null, 'store' => $r['store_name'], 'list' => (string)$r['list_name'],
                                        'by' => $r['bought_by'] ? ShKit::personName($db, (int)$r['bought_by']) : ((string)$r['bought_name'] ?: 'Someone with the link'), 'at' => (string)$r['bought_at']],
                             $st->fetchAll(PDO::FETCH_ASSOC));

        $args = [$companyId, date('Y-m-01')];
        $vis = self::visibleSql($userId, $args);
        $st = $db->prepare("SELECT COALESCE(i.category, 'other') AS cat, SUM(i.paid_cents) AS total, COUNT(*) AS n
                              FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                             WHERE l.company_id = ? AND l.deleted_at IS NULL AND i.status = 'bought' AND i.paid_cents > 0 AND i.bought_at >= ? AND $vis
                          GROUP BY cat ORDER BY total DESC LIMIT 6");
        $st->execute($args);
        $aisles = array_map(fn($r) => ['key' => (string)$r['cat'], 'label' => ShParse::categoryLabel((string)$r['cat']), 'total' => (int)$r['total'], 'n' => (int)$r['n'],
                                        'color' => ShParse::CATEGORIES[$r['cat']][2] ?? ShParse::OTHER[2]], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['needed' => $needed, 'needed_count' => $neededCount, 'late_count' => $lateCount, 'recent' => $recent, 'aisles' => $aisles];
    }

    // ------------------------------------------------------------------ one list

    public static function itemRow(PDO $db, array $i): array
    {
        $cat = (string)($i['category'] ?: 'other');
        $qty = (float)$i['qty'];
        $est = $i['est_price_cents'] !== null ? (int)$i['est_price_cents'] : null;
        return [
            'id' => (int)$i['id'], 'name' => (string)$i['name_raw'], 'qty' => $qty, 'qty_text' => ShKit::qtyText($qty), 'unit' => (string)($i['unit'] ?: 'ea'),
            'priority' => (string)($i['priority'] ?: 'med'), 'notes' => (string)($i['notes'] ?? ''),
            'category' => $cat, 'category_label' => ShParse::categoryLabel($cat),
            'needed_by' => $i['needed_by'] ? substr((string)$i['needed_by'], 0, 10) : null,
            'project_id' => $i['project_id'] ? (int)$i['project_id'] : null, 'project' => $i['project_name'] ?? null,
            'est' => $est, 'est_line' => $est !== null ? (int)round($qty * $est) : null,
            'paid' => $i['paid_cents'] !== null ? (int)$i['paid_cents'] : null,
            'store_id' => $i['store_id'] ? (int)$i['store_id'] : null, 'store' => $i['store_name'] ?? null,
            'bought' => $i['status'] === 'bought', 'bought_at' => $i['bought_at'] ?? null,
            'bought_by' => $i['status'] === 'bought' ? ($i['bought_by'] ? ShKit::personName($db, (int)$i['bought_by']) : ((string)($i['bought_name'] ?? '') !== '' ? $i['bought_name'] . ' (link)' : '')) : '',
            'added_by' => $i['added_by'] ? ShKit::personName($db, (int)$i['added_by']) : '',
            'po_id' => $i['po_id'] ? (int)$i['po_id'] : null, 'po_number' => $i['po_number'] ?? null,
            'order' => (int)$i['order_index'], 'created_at' => (string)$i['created_at'],
        ];
    }

    public static function items(PDO $db, int $companyId, int $listId): array
    {
        $st = $db->prepare("SELECT i.*, p.name AS project_name, s.name AS store_name, po.po_number
                              FROM shopping_items i
                         LEFT JOIN projects p ON p.project_id = i.project_id AND p.company_id = ?
                         LEFT JOIN shopping_stores s ON s.id = i.store_id AND s.company_id = ?
                         LEFT JOIN purchase_orders po ON po.id = i.po_id AND po.company_id = ?
                             WHERE i.list_id = ? AND i.status <> 'removed'
                          ORDER BY i.order_index, i.id");
        $st->execute([$companyId, $companyId, $companyId, $listId]);
        return array_map(fn($i) => self::itemRow($db, $i), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function item(PDO $db, int $companyId, int $itemId): ?array
    {
        $st = $db->prepare("SELECT i.*, p.name AS project_name, s.name AS store_name, po.po_number
                              FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id AND l.company_id = ?
                         LEFT JOIN projects p ON p.project_id = i.project_id AND p.company_id = l.company_id
                         LEFT JOIN shopping_stores s ON s.id = i.store_id AND s.company_id = l.company_id
                         LEFT JOIN purchase_orders po ON po.id = i.po_id AND po.company_id = l.company_id
                             WHERE i.id = ? AND i.status <> 'removed'");
        $st->execute([$companyId, $itemId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        return $i ? self::itemRow($db, $i) : null;
    }

    /** One list for its page: the card, the items, who is shopping, links, activity, the change stamp. */
    public static function detail(PDO $db, int $companyId, int $userId, string $role, int $listId): array
    {
        $list = self::access($db, $companyId, $userId, $role, $listId);
        $card = self::cards($db, $companyId, $userId, $role, $listId)[0] ?? null;
        if (!$card) throw new ShProblem('That list is not there any more.', 404);
        $card['project_url'] = $card['project_id'] ? '/projects/view.php?project_id=' . $card['project_id'] : null;
        $session = null;
        $st = $db->prepare("SELECT s.*, st.name AS store_name FROM shopping_sessions s LEFT JOIN shopping_stores st ON st.id = s.active_store_id AND st.company_id = ?
                             WHERE s.list_id = ? AND s.ended_at IS NULL ORDER BY s.started_at LIMIT 1");
        $st->execute([$companyId, $listId]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
            $session = ['id' => (int)$s['id'], 'by' => (int)$s['started_by'], 'by_name' => ShKit::personName($db, (int)$s['started_by']), 'since' => (string)$s['started_at'],
                        'store_id' => $s['active_store_id'] ? (int)$s['active_store_id'] : null, 'store' => $s['store_name'] ?? null];
        }
        return [
            'list' => $card,
            'items' => self::items($db, $companyId, $listId),
            'session' => $session,
            'shares' => $card['manage'] ? ShShare::forList($db, $listId) : [],
            'activity' => self::activity($db, $companyId, $listId),
            'version' => self::version($db, $listId),
        ];
    }

    /** Changes when anything a page shows about the list changes. */
    public static function version(PDO $db, int $listId): string
    {
        $st = $db->prepare("SELECT CONCAT_WS('|', l.updated_at, l.status, l.name, COALESCE(l.budget_cents, ''), l.shared_mode, l.deleted_at,
                                   (SELECT CONCAT_WS('|', COUNT(*), MAX(i.updated_at), SUM(CRC32(CONCAT_WS('|', i.id, i.status, i.qty, i.unit, i.name_raw, i.priority, COALESCE(i.paid_cents, ''),
                                           COALESCE(i.est_price_cents, ''), COALESCE(i.category, ''), COALESCE(i.notes, ''), COALESCE(i.store_id, ''), COALESCE(i.po_id, ''),
                                           COALESCE(i.needed_by, ''), COALESCE(i.project_id, ''), i.order_index))))
                                      FROM shopping_items i WHERE i.list_id = l.id AND i.status <> 'removed'),
                                   (SELECT CONCAT_WS(',', COUNT(*), MAX(COALESCE(active_store_id, 0))) FROM shopping_sessions s WHERE s.list_id = l.id AND s.ended_at IS NULL))
                              FROM shopping_lists l WHERE l.id = ?");
        $st->execute([$listId]);
        return substr(md5((string)$st->fetchColumn()), 0, 16);
    }

    /** The last things that happened on the list, in words. */
    public static function activity(PDO $db, int $companyId, int $listId, int $limit = 20): array
    {
        $st = $db->prepare("SELECT a.action, a.payload_json, a.user_id, a.created_at, i.name_raw FROM shopping_actions a
                         LEFT JOIN shopping_items i ON i.id = a.item_id
                             WHERE a.list_id = ? AND a.company_id = ? ORDER BY a.id DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute([$listId, $companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $p = json_decode((string)$a['payload_json'], true) ?: [];
            $item = (string)($p['name'] ?? $a['name_raw'] ?? 'an item');
            $n = (int)($p['count'] ?? $p['item_count'] ?? 0);
            $text = match ($a['action']) {
                'add' => $n > 1 ? "added $n items" : 'added ' . $item,
                'merge' => 'added more ' . $item,
                'edit' => 'changed ' . $item,
                'check' => 'put ' . $item . ' in the trolley' . (isset($p['paid']) && $p['paid'] !== null ? ' · ' . ShKit::money((int)$p['paid']) : ''),
                'uncheck' => 'took ' . $item . ' out of the trolley',
                'price' => 'noted ' . ShKit::money((int)($p['paid'] ?? 0)) . ' for ' . $item,
                'remove' => 'removed ' . $item,
                'restore' => 'brought back ' . $item,
                'buy' => 'started shopping' . (!empty($p['store']) ? ' at ' . $p['store'] : ''),
                'finish' => 'finished shopping' . (isset($p['bought']) ? ' · ' . (int)$p['bought'] . ' bought' : ''),
                'po' => 'ordered ' . ($n ?: 'the') . ' items on ' . ($p['po_number'] ?? 'a purchase order'),
                'share' => 'made a link for someone without Flowwork',
                'unshare' => 'stopped a link',
                'create' => !empty($p['template']) ? 'made this list from “' . $p['template'] . '”' : (!empty($p['from']) ? 'made this list from “' . $p['from'] . '”' : 'made this list'),
                'rename' => 'renamed the list to “' . ($p['name'] ?? '') . '”',
                'settings' => 'changed the list’s details',
                'reopen' => 'opened the list again',
                default => (string)$a['action'],
            };
            $who = !empty($p['guest']) ? $p['guest'] . ' (link)' : (ShKit::personName($db, (int)$a['user_id']) ?: 'Someone');
            $out[] = ['who' => $who, 'text' => $text, 'at' => (string)$a['created_at'], 'action' => (string)$a['action']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ writes

    /** Check the editable fields of a list. @return array column => value */
    public static function fields(PDO $db, int $companyId, int $userId, string $role, array $in, bool $creating): array
    {
        $f = [];
        if ($creating || array_key_exists('name', $in)) {
            $name = ShKit::text($in['name'] ?? '', 120);
            if ($name === '') {
                if (!$creating) throw new ShProblem('Give the list a name.', 422);
                $name = 'Shopping list ' . date('j M');
            }
            $f['name'] = $name;
        }
        if ($creating || array_key_exists('purpose', $in)) {
            $p = (string)($in['purpose'] ?? 'general');
            $f['purpose'] = isset(ShKit::PURPOSES[$p]) ? $p : 'general';
        }
        if ($creating || array_key_exists('shared', $in)) {
            $f['shared_mode'] = ($in['shared'] ?? 'private') === 'team' ? 'team' : 'private';
        }
        if (array_key_exists('budget', $in)) $f['budget_cents'] = (int)(ShKit::price($in['budget'], 'The budget') ?? 0);
        if (array_key_exists('needed_by', $in)) {
            $d = trim((string)$in['needed_by']);
            if ($d !== '' && !ShKit::ymd($d)) throw new ShProblem('The date it is needed by is not a date.', 422);
            $f['needed_by'] = $d === '' ? null : ShKit::ymd($d);
        }
        if (array_key_exists('notes', $in)) $f['notes'] = ShKit::text($in['notes'], 500) ?: null;
        if (array_key_exists('project_id', $in)) $f['project_id'] = self::projectId($db, $companyId, $userId, $role, $in['project_id']);
        if (array_key_exists('store_id', $in)) $f['store_id'] = ShStores::storeId($db, $companyId, $in['store_id']);
        return $f;
    }

    /** A posted project id, when the project is in the company and this person can open it (null for none). */
    public static function projectId(PDO $db, int $companyId, int $userId, string $role, $v): ?int
    {
        $id = is_scalar($v) ? (int)$v : 0;
        if ($id <= 0) return null;
        require_once dirname(__DIR__, 2) . '/projects/lib/autoload.php';
        if (!ProjectLink::belongs($db, $companyId, $id) || !ProjectLink::canSeeProject($db, $companyId, $userId, $role, $id)) {
            throw new ShProblem('That project is not one you can add things to.', 422);
        }
        return $id;
    }

    public static function create(PDO $db, int $companyId, int $userId, string $role, array $in, array $items = [], array $log = []): int
    {
        if (!ShKit::canWrite($role)) throw new ShForbidden('Viewers can look at team lists but not make them.');
        $f = self::fields($db, $companyId, $userId, $role, $in, true);
        if (isset($in['template_id'])) $f['template_id'] = (int)$in['template_id'] ?: null;
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $cols = array_merge(['company_id', 'owner_id', 'status'], array_keys($f));
            $db->prepare("INSERT INTO shopping_lists (`" . implode('`, `', $cols) . "`, created_at, updated_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW(), NOW())")
               ->execute(array_merge([$companyId, $userId, 'open'], array_values($f)));
            $id = (int)$db->lastInsertId();
            ShKit::act($db, $companyId, $id, null, $userId, 'create', $log);
            if ($items) ShItems::addMany($db, $companyId, $userId, self::load($db, $companyId, $id), $items, ['project_id' => $f['project_id'] ?? null]);
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        ShKit::audit($db, $companyId, $userId, 'shopping_list_create', 'shopping_list', $id, ['name' => $f['name'], 'items' => count($items)]);
        return $id;
    }

    public static function update(PDO $db, int $companyId, int $userId, string $role, int $listId, array $in): void
    {
        $list = self::access($db, $companyId, $userId, $role, $listId, 'edit');
        $f = self::fields($db, $companyId, $userId, $role, $in, false);
        if (isset($f['shared_mode']) && $f['shared_mode'] !== ($list['shared_mode'] === 'private' ? 'private' : 'team') && !self::canManage($list, $userId, $role)) {
            throw new ShForbidden('Only the owner can change who sees this list.');
        }
        if (!$f) return;
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $db->prepare("UPDATE shopping_lists SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute(array_merge(array_values($f), [$listId, $companyId]));
        if (isset($f['name']) && $f['name'] !== $list['name']) ShKit::act($db, $companyId, $listId, null, $userId, 'rename', ['name' => $f['name']]);
        elseif (array_diff_key($f, ['name' => 1])) ShKit::act($db, $companyId, $listId, null, $userId, 'settings', array_keys($f));
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $listId): void
    {
        $list = self::access($db, $companyId, $userId, $role, $listId, 'manage');
        $db->prepare("UPDATE shopping_lists SET deleted_at = NOW(), status = IF(status = 'buying', 'open', status) WHERE id = ? AND company_id = ?")->execute([$listId, $companyId]);
        $db->prepare("UPDATE shopping_sessions SET ended_at = NOW() WHERE list_id = ? AND ended_at IS NULL")->execute([$listId]);
        $db->prepare("UPDATE shopping_share_tokens SET revoked_at = NOW() WHERE list_id = ? AND revoked_at IS NULL")->execute([$listId]);
        ShKit::audit($db, $companyId, $userId, 'shopping_list_delete', 'shopping_list', $listId, ['name' => $list['name']]);
    }

    public static function restore(PDO $db, int $companyId, int $userId, string $role, int $listId): void
    {
        $st = $db->prepare("SELECT * FROM shopping_lists WHERE id = ? AND company_id = ? AND deleted_at IS NOT NULL");
        $st->execute([$listId, $companyId]);
        $list = $st->fetch(PDO::FETCH_ASSOC);
        if (!$list || !self::canManage($list, $userId, $role)) throw new ShProblem('That list cannot be brought back.', 404);
        $db->prepare("UPDATE shopping_lists SET deleted_at = NULL, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$listId, $companyId]);
        ShKit::audit($db, $companyId, $userId, 'shopping_list_restore', 'shopping_list', $listId, ['name' => $list['name']]);
    }

    /** A fresh copy: every item still to buy again, nothing in the trolley. */
    public static function duplicate(PDO $db, int $companyId, int $userId, string $role, int $listId, string $name = ''): int
    {
        $list = self::access($db, $companyId, $userId, $role, $listId);
        $items = [];
        foreach (self::items($db, $companyId, $listId) as $i) {
            $items[] = ['name' => $i['name'], 'qty' => $i['qty'], 'unit' => $i['unit'], 'priority' => $i['priority'], 'category' => $i['category'],
                        'notes' => $i['notes'], 'price_cents' => $i['est'] ?? ($i['paid'] !== null && $i['qty'] > 0 ? (int)round($i['paid'] / $i['qty']) : null), 'project_id' => $i['project_id']];
        }
        $in = ['name' => $name !== '' ? $name : $list['name'] . ' (again)', 'purpose' => $list['purpose'], 'shared' => $list['shared_mode'] === 'private' ? 'private' : 'team',
               'budget' => $list['budget_cents'] ? $list['budget_cents'] / 100 : null, 'notes' => $list['notes']];
        try {
            $in['project_id'] = $list['project_id'] ? self::projectId($db, $companyId, $userId, $role, $list['project_id']) : null;
        } catch (ShProblem $e) {
            $in['project_id'] = null;
        }
        return self::create($db, $companyId, $userId, $role, $in, $items, ['from' => $list['name']]);
    }

    // ------------------------------------------------------------- out shopping

    /** Start (or join) the shopping trip on a list. */
    public static function startShopping(PDO $db, int $companyId, int $userId, string $role, int $listId, $storeId = null): array
    {
        $db->beginTransaction();
        try {
            $list = self::access($db, $companyId, $userId, $role, $listId, 'edit', true);
            $store = ShStores::storeId($db, $companyId, $storeId);
            $st = $db->prepare("SELECT id FROM shopping_sessions WHERE list_id = ? AND ended_at IS NULL ORDER BY started_at LIMIT 1");
            $st->execute([$listId]);
            $sid = (int)$st->fetchColumn();
            if ($sid) {
                if ($store) $db->prepare("UPDATE shopping_sessions SET active_store_id = ? WHERE id = ?")->execute([$store, $sid]);
            } else {
                $db->prepare("INSERT INTO shopping_sessions (list_id, started_by, started_at, active_store_id) VALUES (?, ?, NOW(), ?)")->execute([$listId, $userId, $store ?: ($list['store_id'] ?: null)]);
                $sid = (int)$db->lastInsertId();
                $db->prepare("UPDATE shopping_lists SET status = 'buying', done_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$listId]);
                ShKit::act($db, $companyId, $listId, null, $userId, 'buy', ['session_id' => $sid, 'store' => $store ? ShStores::name($db, $companyId, $store) : null]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['session_id' => $sid];
    }

    /** Where the shopping is happening now (prices noted are kept for that store). */
    public static function setShoppingStore(PDO $db, int $companyId, int $userId, string $role, int $listId, $storeId): void
    {
        self::access($db, $companyId, $userId, $role, $listId, 'edit');
        $store = ShStores::storeId($db, $companyId, $storeId);
        $db->prepare("UPDATE shopping_sessions SET active_store_id = ? WHERE list_id = ? AND ended_at IS NULL")->execute([$store, $listId]);
        self::touch($db, $listId);
    }

    /**
     * Finish the trip. What was not bought: 'keep' (stays on the list), 'move'
     * (to a new list, which is returned) or 'remove'.
     */
    public static function finish(PDO $db, int $companyId, int $userId, string $role, int $listId, string $leftover = 'keep'): array
    {
        $newId = null;
        $db->beginTransaction();
        try {
            $list = self::access($db, $companyId, $userId, $role, $listId, 'edit', true);
            $st = $db->prepare("SELECT id, name_raw, qty, unit, priority, notes, category, est_price_cents, project_id, needed_by FROM shopping_items WHERE list_id = ? AND status NOT IN ('bought', 'removed') ORDER BY order_index, id");
            $st->execute([$listId]);
            $left = $st->fetchAll(PDO::FETCH_ASSOC);
            $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(paid_cents), 0) FROM shopping_items WHERE list_id = ? AND status = 'bought'");
            $st->execute([$listId]);
            [$bought, $paid] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
            if ($left && $leftover === 'move') {
                $newId = self::create($db, $companyId, $userId, $role, [
                    'name' => mb_substr($list['name'] . ' — still to buy', 0, 120), 'purpose' => $list['purpose'], 'shared' => $list['shared_mode'] === 'private' ? 'private' : 'team',
                    'project_id' => null, 'notes' => $list['notes'],
                ], [], ['from' => $list['name']]);
                $db->prepare("UPDATE shopping_lists SET project_id = ? WHERE id = ?")->execute([$list['project_id'], $newId]);
                $ids = array_map(fn($r) => (int)$r['id'], $left);
                $db->prepare("UPDATE shopping_items SET list_id = ? WHERE id IN (" . implode(',', $ids) . ") AND list_id = ?")->execute([$newId, $listId]);
                $left = [];
            } elseif ($left && $leftover === 'remove') {
                $db->prepare("UPDATE shopping_items SET status = 'removed' WHERE list_id = ? AND status NOT IN ('bought', 'removed')")->execute([$listId]);
                $left = [];
            }
            $db->prepare("UPDATE shopping_sessions SET ended_at = NOW() WHERE list_id = ? AND ended_at IS NULL")->execute([$listId]);
            $done = !$left && $bought > 0;
            $db->prepare("UPDATE shopping_lists SET status = ?, done_at = ?, updated_at = NOW() WHERE id = ?")->execute([$done ? 'done' : 'open', $done ? date('Y-m-d H:i:s') : null, $listId]);
            ShKit::act($db, $companyId, $listId, null, $userId, 'finish', ['bought' => $bought, 'paid' => $paid, 'left' => count($left), 'leftover' => $leftover]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ((int)$list['owner_id'] !== $userId) {
            ShKit::notify($db, $companyId, (int)$list['owner_id'], $userId, (ShKit::personName($db, $userId) ?: 'Someone') . ' finished shopping', '“' . $list['name'] . '”: ' . $bought . ' bought' . ($paid ? ' for ' . ShKit::money($paid) : '') . ($left ? ', ' . count($left) . ' still to buy' : ''), '/shopping/list.php?id=' . $listId, true);
        }
        return ['bought' => $bought, 'paid' => $paid, 'left' => count($left), 'done' => $done, 'moved_to' => $newId];
    }

    public static function reopen(PDO $db, int $companyId, int $userId, string $role, int $listId): void
    {
        self::access($db, $companyId, $userId, $role, $listId, 'edit');
        $db->prepare("UPDATE shopping_lists SET status = 'open', done_at = NULL, updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'done'")->execute([$listId, $companyId]);
        ShKit::act($db, $companyId, $listId, null, $userId, 'reopen');
    }

    // ------------------------------------------------------------- find, export

    public static function search(PDO $db, int $companyId, int $userId, string $q): array
    {
        $q = ShKit::text($q, 80);
        if (mb_strlen($q) < 2) return ['lists' => [], 'items' => []];
        $like = '%' . ShKit::likeEscape($q) . '%';
        $args = [$companyId, $like];
        $vis = self::visibleSql($userId, $args);
        $st = $db->prepare("SELECT l.id, l.name, l.status, l.purpose FROM shopping_lists l WHERE l.company_id = ? AND l.deleted_at IS NULL AND l.name LIKE ? AND $vis
                          ORDER BY FIELD(l.status, 'buying', 'open', 'done'), l.updated_at DESC LIMIT 6");
        $st->execute($args);
        $lists = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status'], 'purpose' => (string)$r['purpose']], $st->fetchAll(PDO::FETCH_ASSOC));
        $args = [$companyId, $like];
        $vis = self::visibleSql($userId, $args);
        $st = $db->prepare("SELECT i.id, i.list_id, i.name_raw, i.qty, i.unit, i.status, l.name AS list_name FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                             WHERE l.company_id = ? AND l.deleted_at IS NULL AND i.status <> 'removed' AND i.name_raw LIKE ? AND $vis
                          ORDER BY i.status = 'bought', FIELD(l.status, 'buying', 'open', 'done'), i.updated_at DESC LIMIT 8");
        $st->execute($args);
        $items = array_map(fn($r) => ['id' => (int)$r['id'], 'list_id' => (int)$r['list_id'], 'name' => (string)$r['name_raw'], 'qty' => ShKit::qtyText($r['qty']), 'unit' => (string)$r['unit'],
                                       'bought' => $r['status'] === 'bought', 'list' => (string)$r['list_name']], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['lists' => $lists, 'items' => $items];
    }

    /** The list as CSV rows (header first). */
    public static function csv(PDO $db, int $companyId, int $userId, string $role, int $listId): array
    {
        $list = self::access($db, $companyId, $userId, $role, $listId);
        $rows = [['Item', 'Quantity', 'Unit', 'Aisle', 'Urgent', 'Needed by', 'Project', 'Expected price (each)', 'Paid', 'Store', 'Bought', 'Bought by', 'Bought at', 'Notes']];
        foreach (self::items($db, $companyId, $listId) as $i) {
            $rows[] = [$i['name'], $i['qty_text'], $i['unit'], $i['category_label'], $i['priority'] === 'high' ? 'yes' : '', $i['needed_by'] ?? '', $i['project'] ?? '',
                       $i['est'] !== null ? number_format($i['est'] / 100, 2, '.', '') : '', $i['paid'] !== null ? number_format($i['paid'] / 100, 2, '.', '') : '',
                       $i['store'] ?? '', $i['bought'] ? 'yes' : '', $i['bought_by'], $i['bought_at'] ?? '', $i['notes']];
        }
        return ['name' => (string)$list['name'], 'rows' => $rows];
    }
}
