<?php
// /shopping/lib/ShItems.php — the things on a list: adding (typed, pasted or
// from a template — the same thing twice adds up instead of doubling), the
// details, ticking off in the shop (with what it cost and where, which feeds
// the price book), removing with undo.

final class ShItems
{
    /** Fields of one item as typed in the edit dialog. @return array column => value */
    public static function fields(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        $f = [];
        if (array_key_exists('name', $in)) {
            $name = ShKit::text($in['name'], 200);
            if ($name === '') throw new ShProblem('What is it? Give the item a name.', 422);
            $f['name_raw'] = $name;
            $f['name_norm'] = ShParse::norm($name);
        }
        if (array_key_exists('qty', $in)) $f['qty'] = ShKit::qty($in['qty']);
        if (array_key_exists('unit', $in)) $f['unit'] = ShParse::unit($in['unit']);
        if (array_key_exists('priority', $in)) $f['priority'] = in_array($in['priority'], ['low', 'med', 'high'], true) ? $in['priority'] : 'med';
        if (array_key_exists('notes', $in)) $f['notes'] = ShKit::text($in['notes'], 500) ?: null;
        if (array_key_exists('category', $in)) $f['category'] = isset(ShParse::CATEGORIES[$in['category']]) ? $in['category'] : 'other';
        if (array_key_exists('needed_by', $in)) {
            $d = trim((string)$in['needed_by']);
            if ($d !== '' && !ShKit::ymd($d)) throw new ShProblem('The date it is needed by is not a date.', 422);
            $f['needed_by'] = $d === '' ? null : ShKit::ymd($d) . ' 00:00:00';
        }
        if (array_key_exists('project_id', $in)) $f['project_id'] = ShLists::projectId($db, $companyId, $userId, $role, $in['project_id']);
        if (array_key_exists('est', $in)) $f['est_price_cents'] = ShKit::price($in['est'], 'The expected price');
        if (array_key_exists('store_id', $in)) $f['store_id'] = ShStores::storeId($db, $companyId, $in['store_id']);
        return $f;
    }

    /**
     * Add parsed items to a list (inside the caller's transaction or its own).
     * An item already waiting on the list with the same name and unit gets the
     * quantity added instead. A missing price comes from the last one paid.
     * @param list<array> $items ShParse::items() rows (name, qty, unit, price_cents, priority, category, notes?, project_id?)
     * @return array{added: int[], merged: list<array{id:int, name:string, qty:string}>}
     */
    public static function addMany(PDO $db, int $companyId, int $userId, array $list, array $items, array $defaults = []): array
    {
        $listId = (int)$list['id'];
        $items = array_slice($items, 0, ShKit::MAX_LINES_PER_ADD);
        if (!$items) return ['added' => [], 'merged' => []];
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->prepare("SELECT id FROM shopping_lists WHERE id = ? FOR UPDATE")->execute([$listId]);
            $st = $db->prepare("SELECT id, name_norm, unit, qty, name_raw FROM shopping_items WHERE list_id = ? AND status NOT IN ('bought', 'removed')");
            $st->execute([$listId]);
            $waiting = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $waiting[$r['name_norm'] . '|' . ShParse::unit($r['unit'])] = $r;
            $st = $db->prepare("SELECT COUNT(*), COALESCE(MAX(order_index), 0) FROM shopping_items WHERE list_id = ? AND status <> 'removed'");
            $st->execute([$listId]);
            [$count, $order] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
            $ins = $db->prepare("INSERT INTO shopping_items (list_id, name_raw, name_norm, qty, unit, priority, notes, category, est_price_cents, project_id, status, order_index, added_by, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())");
            $more = $db->prepare("UPDATE shopping_items SET qty = qty + ?, priority = IF(? = 'high', 'high', priority), est_price_cents = COALESCE(est_price_cents, ?), updated_at = NOW() WHERE id = ?");
            $added = [];
            $names = [];
            $merged = [];
            foreach ($items as $it) {
                $name = ShKit::text($it['name'] ?? '', 200);
                if ($name === '') continue;
                $unit = ShParse::unit($it['unit'] ?? 'ea');
                $qty = round(max(0.001, min(1000000, (float)($it['qty'] ?? 1))), 3);
                $norm = ShParse::norm($name);
                $priority = in_array($it['priority'] ?? 'med', ['low', 'med', 'high'], true) ? $it['priority'] : 'med';
                $price = isset($it['price_cents']) && $it['price_cents'] !== null ? max(0, min(1000000000, (int)$it['price_cents'])) : null;
                $key = $norm . '|' . $unit;
                if (!isset($waiting[$key])) {
                    // "cement x 5" with "10 bags cement" waiting: five more bags — and
                    // "10 bags cement" with "5 × cement" waiting: the count was bags all along
                    foreach ($waiting as $k => $w) {
                        if ($w['name_norm'] !== $norm) continue;
                        $wu = ShParse::unit($w['unit']);
                        if ($unit === 'ea' || ($wu === 'ea' && in_array($unit, ShParse::COUNT_UNITS, true))) { $key = $k; break; }
                    }
                }
                if (isset($waiting[$key])) {
                    $w = $waiting[$key];
                    $more->execute([$qty, $priority, $price, $w['id']]);
                    if (ShParse::unit($w['unit']) === 'ea' && $unit !== 'ea') {
                        $db->prepare("UPDATE shopping_items SET unit = ? WHERE id = ?")->execute([$unit, $w['id']]);
                        unset($waiting[$key]);
                        $w['unit'] = $unit;
                        $key = $norm . '|' . $unit;
                        $waiting[$key] = $w;
                    }
                    $waiting[$key]['qty'] = (float)$w['qty'] + $qty;
                    $merged[] = ['id' => (int)$w['id'], 'name' => (string)$w['name_raw'], 'qty' => ShKit::qtyText($waiting[$key]['qty'])];
                    ShKit::act($db, $companyId, $listId, (int)$w['id'], $userId, 'merge', ['name' => $w['name_raw'], 'qty' => $qty] + (isset($defaults['guest']) ? ['guest' => $defaults['guest']] : []));
                    continue;
                }
                if ($count >= ShKit::MAX_ITEMS_PER_LIST) throw new ShProblem('A list holds at most ' . ShKit::MAX_ITEMS_PER_LIST . ' items — start a second list for the rest.', 422);
                // The aisle someone last put this item in wins over the parser's guess.
                $given = (string)($it['category'] ?? '');
                $cat = self::learnedCategory($db, $companyId, $norm) ?? (isset(ShParse::CATEGORIES[$given]) || $given === 'other' ? $given : ShParse::category($name));
                if ($price === null) $price = ShStores::lastPrice($db, $companyId, $name, $unit);
                $project = array_key_exists('project_id', $it) ? ($it['project_id'] ?: null) : ($defaults['project_id'] ?? null);
                $ins->execute([$listId, $name, $norm, $qty, $unit, $priority, ShKit::text($it['notes'] ?? '', 500) ?: null, $cat, $price, $project, ++$order, $userId]);
                $id = (int)$db->lastInsertId();
                $added[] = $id;
                $names[] = $name;
                $waiting[$key] = ['id' => $id, 'name_norm' => $norm, 'unit' => $unit, 'qty' => $qty, 'name_raw' => $name];
                $count++;
            }
            if ($added) ShKit::act($db, $companyId, $listId, count($added) === 1 ? $added[0] : null, $userId, 'add', (count($added) === 1 ? ['name' => $names[0], 'count' => 1] : ['count' => count($added)]) + (isset($defaults['guest']) ? ['guest' => $defaults['guest']] : []));
            ShLists::touch($db, $listId);
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['added' => $added, 'merged' => $merged];
    }

    /** The aisle people put this item in last time (they may have moved it). */
    private static function learnedCategory(PDO $db, int $companyId, string $norm): ?string
    {
        if ($norm === '') return null;
        $st = $db->prepare("SELECT i.category FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id
                             WHERE l.company_id = ? AND i.name_norm = ? AND i.category IS NOT NULL ORDER BY i.updated_at DESC, i.id DESC LIMIT 1");
        $st->execute([$companyId, $norm]);
        $c = $st->fetchColumn();
        return $c !== false && (isset(ShParse::CATEGORIES[$c]) || $c === 'other') ? (string)$c : null;
    }

    /** The item with its list, when this person may $need ('see' | 'edit') it. */
    public static function access(PDO $db, int $companyId, int $userId, string $role, int $itemId, string $need = 'edit', bool $lock = false): array
    {
        $st = $db->prepare("SELECT i.* FROM shopping_items i JOIN shopping_lists l ON l.id = i.list_id WHERE i.id = ? AND l.company_id = ? AND l.deleted_at IS NULL" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$itemId, $companyId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new ShProblem('That item is not on the list any more.', 404);
        $list = ShLists::access($db, $companyId, $userId, $role, (int)$item['list_id'], $need);
        return [$item, $list];
    }

    public static function update(PDO $db, int $companyId, int $userId, string $role, int $itemId, array $in): array
    {
        [$item, $list] = self::access($db, $companyId, $userId, $role, $itemId);
        if ($item['status'] === 'removed') throw new ShProblem('That item was removed.', 404);
        $f = self::fields($db, $companyId, $userId, $role, $in);
        if (!$f) return ShLists::item($db, $companyId, $itemId);
        if (isset($f['name_raw']) && !array_key_exists('category', $in) && ShParse::norm((string)$item['name_raw']) !== $f['name_norm']) {
            $f['category'] = ShParse::category($f['name_raw']);
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $db->prepare("UPDATE shopping_items SET $sets, updated_at = NOW() WHERE id = ?")->execute(array_merge(array_values($f), [$itemId]));
        if ($item['status'] === 'bought' && (isset($f['qty']) || isset($f['name_raw']) || isset($f['unit']) || array_key_exists('store_id', $f))) {
            self::recordPrice($db, $companyId, $userId, $itemId);
        }
        ShKit::act($db, $companyId, (int)$list['id'], $itemId, $userId, 'edit', ['name' => $f['name_raw'] ?? $item['name_raw'], 'fields' => array_keys($f)]);
        ShLists::touch($db, (int)$list['id']);
        return ShLists::item($db, $companyId, $itemId);
    }

    /**
     * In the trolley (or out again). Saying it again is harmless, so a phone
     * that lost signal can safely send the same tick twice.
     * $paid: what the whole line cost, in cents (null: not noted; false: leave as it is).
     * $guest: a name for someone ticking through a shared link.
     */
    public static function toggle(PDO $db, int $companyId, int $userId, array $list, int $itemId, bool $bought, $paid = false, $storeId = false, ?string $guest = null): array
    {
        $listId = (int)$list['id'];
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM shopping_items WHERE id = ? AND list_id = ? AND status <> 'removed' FOR UPDATE");
            $st->execute([$itemId, $listId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new ShProblem('That item is not on the list any more.', 404);
            $was = $item['status'] === 'bought';
            if ($bought) {
                if ($storeId === false && $was) {
                    $store = null; // a price noted later keeps the store it was bought at
                } elseif ($storeId === false) {
                    $q = $db->prepare("SELECT active_store_id FROM shopping_sessions WHERE list_id = ? AND ended_at IS NULL ORDER BY started_at LIMIT 1");
                    $q->execute([$listId]);
                    $store = ((int)$q->fetchColumn()) ?: null;
                } else {
                    $store = ShStores::storeId($db, $companyId, $storeId);
                }
                $db->prepare("UPDATE shopping_items SET status = 'bought', bought_at = ?, bought_by = ?, bought_name = ?, store_id = ?, paid_cents = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$was ? $item['bought_at'] : date('Y-m-d H:i:s'), $was ? $item['bought_by'] : ($guest === null ? $userId : null), $was ? $item['bought_name'] : $guest,
                              $store ?? ($item['store_id'] ? (int)$item['store_id'] : null), $paid === false ? $item['paid_cents'] : $paid, $itemId]);
            } else {
                $db->prepare("UPDATE shopping_items SET status = 'pending', bought_at = NULL, bought_by = NULL, bought_name = NULL, paid_cents = NULL, updated_at = NOW() WHERE id = ?")->execute([$itemId]);
                $db->prepare("DELETE FROM shopping_price_history WHERE item_id = ? AND company_id = ?")->execute([$itemId, $companyId]);
            }
            if ($bought) self::recordPrice($db, $companyId, $guest === null ? $userId : 0, $itemId);
            if ($was !== $bought || ($bought && $paid !== false)) {
                $payload = ['name' => $item['name_raw']] + ($bought && $paid !== false ? ['paid' => $paid] : []) + ($guest !== null ? ['guest' => $guest] : []);
                ShKit::act($db, $companyId, $listId, $itemId, $userId, $was === $bought ? 'price' : ($bought ? 'check' : 'uncheck'), $payload);
            }
            ShLists::touch($db, $listId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['item' => ShLists::item($db, $companyId, $itemId), 'counts' => self::counts($db, $listId)];
    }

    /** The price book: what one of these cost, where, kept even if the store goes. One row an item. */
    public static function recordPrice(PDO $db, int $companyId, int $userId, int $itemId): void
    {
        $st = $db->prepare("SELECT i.*, s.name AS store_name FROM shopping_items i LEFT JOIN shopping_stores s ON s.id = i.store_id AND s.company_id = ? WHERE i.id = ?");
        $st->execute([$companyId, $itemId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        $db->prepare("DELETE FROM shopping_price_history WHERE item_id = ? AND company_id = ?")->execute([$itemId, $companyId]);
        if (!$i || $i['status'] !== 'bought' || $i['paid_cents'] === null || (int)$i['paid_cents'] <= 0 || (float)$i['qty'] <= 0) return;
        $each = (int)round((int)$i['paid_cents'] / (float)$i['qty']);
        $db->prepare("INSERT INTO shopping_price_history (company_id, item_fingerprint, item_name, unit, qty, store_id, store_name, list_id, item_id, user_id, price_cents, seen_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))")
           ->execute([$companyId, ShParse::fingerprint((string)$i['name_raw'], (string)$i['unit']), mb_substr((string)$i['name_raw'], 0, 255), ShParse::unit((string)$i['unit']), $i['qty'],
                      $i['store_id'] ?: null, $i['store_name'] ?: null, $i['list_id'], $itemId, $userId ?: null, $each, $i['bought_at']]);
    }

    /** Remove (it can be brought back). */
    public static function remove(PDO $db, int $companyId, int $userId, string $role, int $itemId): void
    {
        [$item, $list] = self::access($db, $companyId, $userId, $role, $itemId);
        if ($item['status'] === 'removed') return;
        $db->prepare("UPDATE shopping_items SET status = 'removed', updated_at = NOW() WHERE id = ?")->execute([$itemId]);
        $db->prepare("DELETE FROM shopping_price_history WHERE item_id = ? AND company_id = ?")->execute([$itemId, $companyId]);
        ShKit::act($db, $companyId, (int)$list['id'], $itemId, $userId, 'remove', ['name' => $item['name_raw']]);
        ShLists::touch($db, (int)$list['id']);
    }

    public static function restore(PDO $db, int $companyId, int $userId, string $role, int $itemId): array
    {
        [$item, $list] = self::access($db, $companyId, $userId, $role, $itemId);
        if ($item['status'] === 'removed') {
            $db->prepare("UPDATE shopping_items SET status = IF(bought_at IS NULL, 'pending', 'bought'), updated_at = NOW() WHERE id = ?")->execute([$itemId]);
            if ($item['bought_at']) self::recordPrice($db, $companyId, $userId, $itemId);
            ShKit::act($db, $companyId, (int)$list['id'], $itemId, $userId, 'restore', ['name' => $item['name_raw']]);
            ShLists::touch($db, (int)$list['id']);
        }
        return ShLists::item($db, $companyId, $itemId);
    }

    /** Put items in a new order (ids in the order wanted; others keep theirs after). */
    public static function reorder(PDO $db, int $companyId, int $userId, string $role, int $listId, array $ids): void
    {
        ShLists::access($db, $companyId, $userId, $role, $listId, 'edit');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return;
        $up = $db->prepare("UPDATE shopping_items SET order_index = ? WHERE id = ? AND list_id = ?");
        foreach ($ids as $n => $id) $up->execute([$n + 1, $id, $listId]);
        ShLists::touch($db, $listId);
    }

    public static function counts(PDO $db, int $listId): array
    {
        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(status = 'bought'), 0) bought, COALESCE(SUM(CASE WHEN status = 'bought' THEN COALESCE(paid_cents, 0) END), 0) paid,
                                   COALESCE(SUM(CASE WHEN status <> 'bought' THEN ROUND(qty * COALESCE(est_price_cents, 0)) END), 0) est_left
                              FROM shopping_items WHERE list_id = ? AND status <> 'removed'");
        $st->execute([$listId]);
        $r = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
        return ['items' => $r['n'] ?? 0, 'bought' => $r['bought'] ?? 0, 'left' => ($r['n'] ?? 0) - ($r['bought'] ?? 0), 'paid' => $r['paid'] ?? 0, 'est_left' => $r['est_left'] ?? 0];
    }
}
