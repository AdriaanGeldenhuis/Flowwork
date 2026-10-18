<?php
// projects/lib/ItemRepo.php
//
// Items and everything on them: create, rename, cell values (validated,
// formulas recomputed, the item's own fields kept in step, automations and
// notifications fired), move / reorder, duplicate, archive / restore /
// delete, move to another board, subitems, updates (comments with replies and
// @mentions), files, watchers and dependencies.

final class ItemRepo
{
    public const MAX_FILE = 25 * 1024 * 1024;
    public const FILE_TYPES = ['pdf', 'txt', 'csv', 'rtf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
                               'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tif', 'tiff', 'dwg', 'dxf', 'zip', 'msg', 'eml'];

    private PDO $db;
    private BoardRepo $boards;
    /** Automations already run in this request, to stop loops. */
    private static int $depth = 0;

    public function __construct(private ProjectRepo $repo)
    {
        $this->db = $repo->db;
        $this->boards = new BoardRepo($repo);
    }

    /** Fresh payloads for items on one board. */
    public function payload(int $boardId, array $ids): array
    {
        return $this->boards->itemsPayload($boardId, $this->boards->columnRows($boardId), $ids);
    }

    private function groupOnBoard(int $groupId, int $boardId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM board_groups WHERE id = ? AND board_id = ?");
        $st->execute([$groupId, $boardId]);
        return (bool)$st->fetchColumn();
    }

    private function firstGroup(int $boardId): int
    {
        $st = $this->db->prepare("SELECT id FROM board_groups WHERE board_id = ? ORDER BY position, id LIMIT 1");
        $st->execute([$boardId]);
        $g = (int)$st->fetchColumn();
        if (!$g) {
            $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, 'Group 1', 0, 0, '#2f5c84', NOW())")->execute([$boardId]);
            $g = (int)$this->db->lastInsertId();
        }
        return $g;
    }

    // ================================================================= create

    /**
     * Add an item. $values: column_id => input (validated like a cell edit).
     * @return array the item payload
     */
    public function create(int $boardId, ?int $groupId, string $title, array $values = [], string $where = 'bottom', array $opts = []): array
    {
        $this->repo->requireBoard($boardId, 2);
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $title)), 0, 500);
        if ($title === '') throw new ProjectUserError('Give it a name.');
        if (!$groupId || !$this->groupOnBoard($groupId, $boardId)) $groupId = $this->firstGroup($boardId);
        $st = $this->db->prepare("SELECT COUNT(*) FROM board_items WHERE board_id = ? AND company_id = ?");
        $st->execute([$boardId, $this->repo->companyId]);
        if ((int)$st->fetchColumn() >= 20000) throw new ProjectUserError('This board is full (20 000 items). Archive or move some first.');
        $cols = [];
        foreach ($this->boards->columnRows($boardId) as $c) $cols[(int)$c['column_id']] = $c;
        $ctx = $this->valueContext($cols);
        $encoded = [];
        foreach ($values as $cid => $v) {
            $cid = (int)$cid;
            if (!isset($cols[$cid]) || in_array($cols[$cid]['type'], Columns::COMPUTED, true)) continue;
            $raw = Columns::encodeValue($cols[$cid], $v, $ctx);
            if ($raw !== null && $raw !== '') $encoded[$cid] = $raw;
        }
        $this->db->beginTransaction();
        try {
            if ($where === 'top') {
                $this->db->prepare("UPDATE board_items SET position = position + 1, updated_at = updated_at WHERE group_id = ? AND board_id = ?")->execute([$groupId, $boardId]);
                $pos = 0;
            } else {
                $st = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM board_items WHERE group_id = ? AND board_id = ?");
                $st->execute([$groupId, $boardId]);
                $pos = (int)$st->fetchColumn();
            }
            $this->db->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, description, position, priority, progress, archived, created_by, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, 'medium', 0, 0, ?, NOW(), NOW())")
                ->execute([$boardId, $this->repo->companyId, $groupId, $title, isset($opts['description']) ? mb_substr((string)$opts['description'], 0, 20000) : null, $pos, $this->repo->userId]);
            $id = (int)$this->db->lastInsertId();
            $ins = $this->db->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
            foreach ($encoded as $cid => $raw) $ins->execute([$id, $cid, $raw]);
            BoardRepo::computeFormulas($this->db, $this->repo->companyId, $boardId, [$id]);
            BoardRepo::refreshMirrors($this->db, $this->repo->companyId, $boardId, [$id]);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, $id, 'item_created', ['title' => $title, 'group_id' => $groupId] + (isset($opts['source']) ? ['source' => $opts['source']] : []));
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if (empty($opts['quiet'])) {
            foreach ($encoded as $cid => $raw) {
                if ($cols[$cid]['type'] === 'people') ProjectNotify::assigned($this->repo, $id, Columns::decode($cols[$cid], $raw) ?: []);
            }
            Automations::fire($this->repo, 'item_created', $id, ['group_id' => $groupId]);
        }
        return empty($opts['no_payload']) ? ($this->payload($boardId, [$id])[0] ?? []) : ['id' => $id];
    }

    /** People and suppliers a cell may name. */
    private function valueContext(array $cols): array
    {
        $ctx = ['users' => array_fill_keys(array_keys($this->repo->people()), true)];
        foreach ($cols as $c) {
            if ($c['type'] === 'supplier') {
                $st = $this->db->prepare("SELECT id FROM crm_accounts WHERE company_id = ? AND type = 'supplier'");
                $st->execute([$this->repo->companyId]);
                $ctx['suppliers'] = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
                break;
            }
        }
        return $ctx;
    }

    // ================================================================= edit

    public function rename(int $itemId, string $title): array
    {
        $it = $this->repo->requireItem($itemId, 2);
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $title)), 0, 500);
        if ($title === '') throw new ProjectUserError('Give it a name.');
        if ($title !== $it['title']) {
            $this->db->prepare("UPDATE board_items SET title = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$title, $itemId, $this->repo->companyId]);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], $itemId, 'item_renamed', ['from' => $it['title'], 'to' => $title]);
        }
        return $this->payload((int)$it['board_id'], [$itemId])[0] ?? [];
    }

    public function describe(int $itemId, string $text): void
    {
        $it = $this->repo->requireItem($itemId, 2);
        $text = mb_substr(trim(str_replace("\0", '', $text)), 0, 20000);
        $this->db->prepare("UPDATE board_items SET description = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$text === '' ? null : $text, $itemId, $this->repo->companyId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], $itemId, 'item_described', ['excerpt' => Activity::clip($text, 80)]);
    }

    /**
     * Set one cell. @return array ['items' => [payload], 'automated' => bool]
     */
    public function setValue(int $itemId, int $columnId, $value): array
    {
        $it = $this->repo->requireItem($itemId, 2);
        $boardId = (int)$it['board_id'];
        $changes = $this->writeValues($boardId, [$itemId], $columnId, $value);
        $ran = $this->afterChanges($changes);
        $ids = array_values(array_unique(array_merge([$itemId], $ran)));
        return ['items' => $this->payload($boardId, $ids), 'automated' => (bool)$ran];
    }

    /** Set the same cell on several items (bulk edit). */
    public function setValues(int $boardId, array $itemIds, int $columnId, $value): array
    {
        $this->repo->requireBoard($boardId, 2);
        $itemIds = $this->idsOnBoard($boardId, $itemIds);
        if (!$itemIds) return ['items' => [], 'automated' => false];
        $changes = $this->writeValues($boardId, $itemIds, $columnId, $value);
        $ran = $this->afterChanges($changes);
        return ['items' => $this->payload($boardId, array_values(array_unique(array_merge($itemIds, $ran)))), 'automated' => (bool)$ran];
    }

    /** @return int[] those of $ids that are live items on the board */
    private function idsOnBoard(int $boardId, array $ids, bool $archived = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $this->db->prepare("SELECT id FROM board_items WHERE board_id = ? AND company_id = ? AND id IN (" . implode(',', $ids) . ")" .
                                 ($archived ? '' : ' AND (archived = 0 OR archived IS NULL)'));
        $st->execute([$boardId, $this->repo->companyId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Write a cell on items (one transaction): value, formulas, item fields,
     * activity. Returns what changed, for notifications and automations.
     */
    private function writeValues(int $boardId, array $itemIds, int $columnId, $value): array
    {
        $cols = [];
        foreach ($this->boards->columnRows($boardId) as $c) $cols[(int)$c['column_id']] = $c;
        $col = $cols[$columnId] ?? null;
        if (!$col) throw new ProjectUserError('Column not found.');
        $new = Columns::encodeValue($col, $value, $this->valueContext([$col]));
        $in = implode(',', array_map('intval', $itemIds));
        $old = [];
        $st = $this->db->prepare("SELECT item_id, value FROM board_item_values WHERE column_id = ? AND item_id IN ($in)");
        $st->execute([$columnId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $old[(int)$r['item_id']] = $r['value'];
        $changes = [];
        $this->db->beginTransaction();
        try {
            $up = $this->db->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            $del = $this->db->prepare("DELETE FROM board_item_values WHERE item_id = ? AND column_id = ?");
            $touch = $this->db->prepare("UPDATE board_items SET updated_at = NOW() WHERE id = ?");
            foreach ($itemIds as $iid) {
                $was = $old[$iid] ?? null;
                if ((string)$was === (string)$new) continue;
                if ($new === null || $new === '') $del->execute([$iid, $columnId]);
                else $up->execute([$iid, $columnId, $new]);
                $touch->execute([$iid]);
                $changes[] = ['item_id' => $iid, 'board_id' => $boardId, 'column' => $col, 'old' => $was, 'new' => $new];
            }
            if ($changes) {
                $changed = array_column($changes, 'item_id');
                BoardRepo::computeFormulas($this->db, $this->repo->companyId, $boardId, $changed);
                BoardRepo::refreshMirrors($this->db, $this->repo->companyId, $boardId, $changed);
                $names = array_map(fn($p) => $p['name'], $this->repo->people());
                foreach ($changes as $ch) {
                    Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, $ch['item_id'], 'value_changed', [
                        'column_id' => $columnId, 'column' => $col['name'], 'type' => $col['type'],
                        'from' => Activity::clip(Columns::text($col, $ch['old'], ['userNames' => $names])),
                        'to' => Activity::clip(Columns::text($col, $ch['new'], ['userNames' => $names])),
                    ] + (count($changes) > 1 ? ['bulk' => count($changes)] : []) + (self::$depth > 0 ? ['automation' => true] : []));
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $changes;
    }

    /** Notifications and automations for written cells. @return int[] items automations touched */
    private function afterChanges(array $changes): array
    {
        $touched = [];
        foreach ($changes as $ch) {
            $col = $ch['column'];
            if ($col['type'] === 'people') {
                $before = Columns::decode($col, $ch['old']) ?: [];
                $after = Columns::decode($col, $ch['new']) ?: [];
                $added = array_values(array_diff($after, $before));
                if ($added) ProjectNotify::assigned($this->repo, $ch['item_id'], $added);
            }
            if (self::$depth < 3) {
                self::$depth++;
                try {
                    $touched = array_merge($touched, Automations::fire($this->repo, 'value_changed', $ch['item_id'], [
                        'column_id' => (int)$col['column_id'], 'type' => $col['type'], 'old' => $ch['old'], 'new' => $ch['new'],
                    ]));
                } finally {
                    self::$depth--;
                }
            }
        }
        return array_values(array_unique($touched));
    }

    /** Used by automations: write a cell without the access check (already done by the trigger). */
    public function automationSet(int $boardId, int $itemId, int $columnId, $value): void
    {
        $changes = $this->writeValues($boardId, [$itemId], $columnId, $value);
        $this->afterChanges($changes);
    }

    // ================================================================= move

    /**
     * Move items to a group; $order (optional) is the full id order of the
     * target group after the drop.
     */
    public function move(int $boardId, array $itemIds, int $groupId, ?array $order = null): void
    {
        $this->repo->requireBoard($boardId, 2);
        if (!$this->groupOnBoard($groupId, $boardId)) throw new ProjectUserError('Group not found.');
        $itemIds = $this->idsOnBoard($boardId, $itemIds);
        if (!$itemIds) return;
        $in = implode(',', $itemIds);
        $st = $this->db->prepare("SELECT i.id, i.group_id, g.name FROM board_items i LEFT JOIN board_groups g ON g.id = i.group_id WHERE i.id IN ($in)");
        $st->execute();
        $from = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $this->db->prepare("SELECT name FROM board_groups WHERE id = ?");
        $st->execute([$groupId]);
        $toName = (string)$st->fetchColumn();
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM board_items WHERE group_id = ? AND board_id = ?");
            $st->execute([$groupId, $boardId]);
            $pos = (int)$st->fetchColumn();
            $mv = $this->db->prepare("UPDATE board_items SET group_id = ?, position = ?, updated_at = NOW() WHERE id = ? AND board_id = ?");
            foreach ($from as $r) {
                if ((int)$r['group_id'] === $groupId) continue;
                $mv->execute([$groupId, $pos++, (int)$r['id'], $boardId]);
                Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, (int)$r['id'], 'item_moved', ['from' => $r['name'], 'to' => $toName, 'group_id' => $groupId]
                              + (self::$depth > 0 ? ['automation' => true] : []));
            }
            if ($order) $this->applyOrder($boardId, $groupId, $order);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if (self::$depth < 3) {
            self::$depth++;
            try {
                foreach ($from as $r) if ((int)$r['group_id'] !== $groupId) Automations::fire($this->repo, 'item_moved', (int)$r['id'], ['group_id' => $groupId]);
            } finally {
                self::$depth--;
            }
        }
    }

    public function reorder(int $boardId, int $groupId, array $order): void
    {
        $this->repo->requireBoard($boardId, 2);
        if (!$this->groupOnBoard($groupId, $boardId)) throw new ProjectUserError('Group not found.');
        $this->applyOrder($boardId, $groupId, $order);
    }

    private function applyOrder(int $boardId, int $groupId, array $order): void
    {
        $st = $this->db->prepare("UPDATE board_items SET position = ?, updated_at = updated_at WHERE id = ? AND group_id = ? AND board_id = ?");
        foreach (array_values(array_map('intval', $order)) as $i => $id) $st->execute([$i, $id, $groupId, $boardId]);
    }

    /** Move items to another board (cells copied by matching column name and type). */
    public function moveToBoard(int $fromBoardId, array $itemIds, int $toBoardId, ?int $groupId): array
    {
        $this->repo->requireBoard($fromBoardId, 2);
        $to = $this->repo->requireBoard($toBoardId, 2);
        if ($fromBoardId === $toBoardId) return [];
        $itemIds = $this->idsOnBoard($fromBoardId, $itemIds);
        if (!$itemIds) return [];
        if (!$groupId || !$this->groupOnBoard($groupId, $toBoardId)) $groupId = $this->firstGroup($toBoardId);
        $fromCols = $this->boards->columnRows($fromBoardId);
        $toCols = $this->boards->columnRows($toBoardId);
        $map = [];
        foreach ($fromCols as $fc) {
            foreach ($toCols as $tc) {
                if ($tc['type'] === $fc['type'] && Formula::nameKey($tc['name']) === Formula::nameKey($fc['name']) && !in_array((int)$tc['column_id'], $map, true)) { $map[(int)$fc['column_id']] = (int)$tc['column_id']; break; }
            }
        }
        foreach ($fromCols as $fc) { // then any free column of the same type
            if (isset($map[(int)$fc['column_id']]) || in_array($fc['type'], ['formula', 'text', 'number'], true)) continue;
            foreach ($toCols as $tc) {
                if ($tc['type'] === $fc['type'] && !in_array((int)$tc['column_id'], $map, true)) { $map[(int)$fc['column_id']] = (int)$tc['column_id']; break; }
            }
        }
        $in = implode(',', $itemIds);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM board_items WHERE group_id = ? AND board_id = ?");
            $st->execute([$groupId, $toBoardId]);
            $pos = (int)$st->fetchColumn();
            $vals = $this->db->query("SELECT item_id, column_id, value FROM board_item_values WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            $this->db->exec("DELETE FROM board_item_values WHERE item_id IN ($in)");
            $ins = $this->db->prepare("INSERT IGNORE INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
            foreach ($vals as $v) if (isset($map[(int)$v['column_id']])) $ins->execute([(int)$v['item_id'], $map[(int)$v['column_id']], $v['value']]);
            $mv = $this->db->prepare("UPDATE board_items SET board_id = ?, group_id = ?, position = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            foreach ($itemIds as $iid) $mv->execute([$toBoardId, $groupId, $pos++, $iid, $this->repo->companyId]);
            $this->db->exec("UPDATE board_subitems SET board_id = " . (int)$toBoardId . " WHERE parent_item_id IN ($in)");
            $this->db->exec("UPDATE board_item_attachments SET column_id = NULL WHERE item_id IN ($in)");
            $this->db->exec("DELETE FROM board_dependencies WHERE predecessor_item IN ($in) OR successor_item IN ($in)");
            foreach ($itemIds as $iid) {
                Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $fromBoardId, $iid, 'item_moved_board', ['to_board' => $to['title'], 'to_board_id' => $toBoardId]);
                Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $toBoardId, $iid, 'item_moved_board', ['from_board_id' => $fromBoardId, 'arrived' => true]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        BoardRepo::computeFormulas($this->db, $this->repo->companyId, $toBoardId, $itemIds);
        BoardRepo::refreshMirrors($this->db, $this->repo->companyId, $toBoardId, $itemIds);
        return $itemIds;
    }

    // ================================================================= copy / archive / delete

    /**
     * Copy an item row (values, subitems) onto a board/group. $colMap maps old
     * column ids to new ones (null = same board). Caller holds the transaction.
     */
    public function copyItemRow(array $it, int $boardId, int $groupId, ?array $colMap, int $position, ?string $title = null): int
    {
        $this->db->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, description, position, status_label, assigned_to, priority, progress,
                                                     due_date, start_date, end_date, tags, archived, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())")
            ->execute([$boardId, $this->repo->companyId, $groupId, mb_substr($title ?? $it['title'], 0, 500), $it['description'], $position, $it['status_label'],
                       $it['assigned_to'], $it['priority'] ?: 'medium', (int)$it['progress'], $it['due_date'], $it['start_date'], $it['end_date'], $it['tags'], $this->repo->userId]);
        $new = (int)$this->db->lastInsertId();
        $st = $this->db->prepare("SELECT column_id, value FROM board_item_values WHERE item_id = ?");
        $st->execute([(int)$it['id']]);
        $ins = $this->db->prepare("INSERT IGNORE INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $cid = $colMap === null ? (int)$v['column_id'] : ($colMap[(int)$v['column_id']] ?? 0);
            if ($cid) $ins->execute([$new, $cid, $v['value']]);
        }
        $this->db->prepare("INSERT INTO board_subitems (parent_item_id, board_id, company_id, title, status_label, assigned_to, due_date, priority, completed, position, created_by, created_at)
                            SELECT ?, ?, company_id, title, status_label, assigned_to, due_date, priority, completed, position, ?, NOW() FROM board_subitems WHERE parent_item_id = ?")
            ->execute([$new, $boardId, $this->repo->userId, (int)$it['id']]);
        $this->db->prepare("UPDATE board_items SET subitem_count = (SELECT COUNT(*) FROM board_subitems WHERE parent_item_id = ?) WHERE id = ?")->execute([$new, $new]);
        return $new;
    }

    public function duplicate(int $itemId): array
    {
        $it = $this->repo->requireItem($itemId, 2);
        $boardId = (int)$it['board_id'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE board_items SET position = position + 1, updated_at = updated_at WHERE group_id = ? AND board_id = ? AND position > ?")
                ->execute([(int)$it['group_id'], $boardId, (int)$it['position']]);
            $new = $this->copyItemRow($it, $boardId, (int)$it['group_id'], null, (int)$it['position'] + 1, $it['title'] . ' (copy)');
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, $new, 'item_created', ['title' => $it['title'] . ' (copy)', 'duplicated_from' => $itemId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        BoardRepo::refreshMirrors($this->db, $this->repo->companyId, $boardId, [$new]);
        return $this->payload($boardId, [$new])[0] ?? [];
    }

    public function archive(int $boardId, array $itemIds, bool $archived): array
    {
        $this->repo->requireBoard($boardId, 2);
        $itemIds = $this->idsOnBoard($boardId, $itemIds, true);
        if (!$itemIds) return [];
        $in = implode(',', $itemIds);
        $titles = $this->db->query("SELECT id, title FROM board_items WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->db->prepare("UPDATE board_items SET archived = ?, updated_at = NOW() WHERE id IN ($in) AND board_id = ? AND company_id = ?")->execute([$archived ? 1 : 0, $boardId, $this->repo->companyId]);
        foreach ($itemIds as $iid) {
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, $iid, $archived ? 'item_archived' : 'item_restored', ['title' => $titles[$iid] ?? '']);
        }
        if (!$archived) {
            // A restored item whose group was deleted goes to the first group.
            $this->db->prepare("UPDATE board_items i LEFT JOIN board_groups g ON g.id = i.group_id SET i.group_id = ? WHERE i.id IN ($in) AND g.id IS NULL")->execute([$this->firstGroup($boardId)]);
        }
        return $itemIds;
    }

    /** Delete items for good. @return string[] stored file paths to remove */
    public function delete(int $boardId, array $itemIds): array
    {
        $b = $this->repo->requireBoard($boardId, 2);
        $itemIds = $this->idsOnBoard($boardId, $itemIds, true);
        if (!$itemIds) return [];
        $in = implode(',', $itemIds);
        $this->db->beginTransaction();
        try {
            $titles = $this->db->query("SELECT id, title FROM board_items WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
            $files = $this->db->query("SELECT file_path FROM board_item_attachments WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['board_item_values', 'board_item_comments', 'board_item_attachments', 'board_watchers', 'board_item_people'] as $t) $this->db->exec("DELETE FROM `$t` WHERE item_id IN ($in)");
            $this->db->exec("DELETE FROM board_subitems WHERE parent_item_id IN ($in)");
            $this->db->exec("DELETE FROM board_dependencies WHERE predecessor_item IN ($in) OR successor_item IN ($in)");
            try { $this->db->exec("DELETE FROM calendar_event_links WHERE linked_type = 'item' AND linked_id IN ($in)"); } catch (Throwable $e) { /* calendar not installed */ }
            try { $this->db->exec("DELETE FROM email_links WHERE linked_type = 'item' AND linked_id IN ($in)"); } catch (Throwable $e) { /* mail not installed */ }
            $this->db->exec("UPDATE board_audit_log SET item_id = NULL WHERE item_id IN ($in)");
            $this->db->prepare("DELETE FROM board_items WHERE id IN ($in) AND board_id = ? AND company_id = ?")->execute([$boardId, $this->repo->companyId]);
            foreach ($itemIds as $iid) Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'item_deleted', ['title' => $titles[$iid] ?? '', 'item_id' => $iid]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array_values(array_map('strval', $files));
    }

    // ================================================================= detail

    /** One item with everything the item panel shows. */
    public function detail(int $itemId): array
    {
        $it = $this->repo->requireItem($itemId, 1);
        $boardId = (int)$it['board_id'];
        $payload = $this->payload($boardId, [$itemId])[0] ?? null;
        if (!$payload && (int)$it['archived']) {
            $payload = $this->boards->itemsPayload($boardId, $this->boards->columnRows($boardId), [$itemId], true)[0] ?? null;
        }
        if (!$payload) throw new ProjectUserError('Item not found.');
        $st = $this->db->prepare("SELECT name FROM board_groups WHERE id = ?");
        $st->execute([(int)$it['group_id']]);
        $watchers = $this->db->prepare("SELECT user_id FROM board_watchers WHERE item_id = ?");
        $watchers->execute([$itemId]);
        $deps = $this->db->prepare("SELECT d.id, d.predecessor_item, d.successor_item, d.dependency_type, d.lag_days, a.title AS a_title, b.title AS b_title
                                      FROM board_dependencies d JOIN board_items a ON a.id = d.predecessor_item JOIN board_items b ON b.id = d.successor_item
                                     WHERE d.board_id = ? AND (d.predecessor_item = ? OR d.successor_item = ?)");
        $deps->execute([$boardId, $itemId, $itemId]);
        $depOut = [];
        foreach ($deps->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $waiting = (int)$d['successor_item'] === $itemId;
            $depOut[] = ['id' => (int)$d['id'], 'kind' => $waiting ? 'waiting_on' : 'blocking', 'item_id' => (int)($waiting ? $d['predecessor_item'] : $d['successor_item']),
                         'title' => $waiting ? $d['a_title'] : $d['b_title'], 'type' => $d['dependency_type'] ?: 'FS', 'lag' => (int)$d['lag_days']];
        }
        return $payload + [
            'board_id' => $boardId,
            'board' => $it['_board']['title'],
            'project_id' => (int)$it['_board']['project_id'],
            'project' => $it['_board']['project_name'],
            'group' => (string)$st->fetchColumn(),
            'description' => (string)($it['description'] ?? ''),
            'archived' => (bool)(int)$it['archived'],
            'level' => (int)$it['_board']['level'],
            'subitem_list' => $this->subitems($itemId, false),
            'watchers' => array_map('intval', $watchers->fetchAll(PDO::FETCH_COLUMN)),
            'deps' => $depOut,
            'links' => ProjectLink::itemLinks($this->repo, $itemId),
            'url' => ProjectLink::itemUrl($boardId, $itemId),
        ];
    }

    // ================================================================= subitems

    private function subitemOut(array $s): array
    {
        return ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'done' => (bool)(int)$s['completed'], 'owner' => $s['assigned_to'] ? (int)$s['assigned_to'] : null,
                'due' => Columns::date($s['due_date'] ?? null), 'position' => (int)$s['position']];
    }

    public function subitems(int $itemId, bool $check = true): array
    {
        if ($check) $this->repo->requireItem($itemId, 1);
        $st = $this->db->prepare("SELECT * FROM board_subitems WHERE parent_item_id = ? AND company_id = ? ORDER BY position, id");
        $st->execute([$itemId, $this->repo->companyId]);
        return array_map([$this, 'subitemOut'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private function subitemRow(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM board_subitems WHERE id = ? AND company_id = ?");
        $st->execute([$id, $this->repo->companyId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new ProjectUserError('Subitem not found.');
        return $s;
    }

    /** After a subitem change: count, the parent's progress, the board's fields. */
    private function subitemsChanged(array $it): void
    {
        $this->db->prepare("UPDATE board_items SET subitem_count = (SELECT COUNT(*) FROM board_subitems WHERE parent_item_id = ?), updated_at = NOW() WHERE id = ?")
            ->execute([(int)$it['id'], (int)$it['id']]);
        BoardRepo::refreshMirrors($this->db, $this->repo->companyId, (int)$it['board_id'], [(int)$it['id']]);
    }

    public function addSubitem(int $itemId, string $title, ?int $owner = null, ?string $due = null): array
    {
        $it = $this->repo->requireItem($itemId, 2);
        $title = mb_substr(trim($title), 0, 255);
        if ($title === '') throw new ProjectUserError('Give the subitem a name.');
        $st = $this->db->prepare("SELECT COUNT(*), COALESCE(MAX(position), -1) + 1 FROM board_subitems WHERE parent_item_id = ?");
        $st->execute([$itemId]);
        [$n, $pos] = $st->fetch(PDO::FETCH_NUM);
        if ((int)$n >= 500) throw new ProjectUserError('An item can have up to 500 subitems.');
        if ($owner && !isset($this->repo->people()[$owner])) $owner = null;
        $this->db->prepare("INSERT INTO board_subitems (parent_item_id, board_id, company_id, title, assigned_to, due_date, completed, position, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())")
            ->execute([$itemId, (int)$it['board_id'], $this->repo->companyId, $title, $owner ?: null, Columns::date($due), (int)$pos, $this->repo->userId]);
        $id = (int)$this->db->lastInsertId();
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], $itemId, 'subitem_added', ['title' => $title]);
        $this->subitemsChanged($it);
        if ($owner && $owner !== $this->repo->userId) ProjectNotify::subitemAssigned($this->repo, $itemId, $owner, $title);
        return $this->subitemOut($this->subitemRow($id));
    }

    public function updateSubitem(int $subId, array $in): array
    {
        $s = $this->subitemRow($subId);
        $it = $this->repo->requireItem((int)$s['parent_item_id'], 2);
        $f = [];
        if (array_key_exists('title', $in)) {
            $t = mb_substr(trim((string)$in['title']), 0, 255);
            if ($t === '') throw new ProjectUserError('Give the subitem a name.');
            $f['title'] = $t;
        }
        if (array_key_exists('done', $in)) { $f['completed'] = empty($in['done']) ? 0 : 1; $f['status_label'] = $f['completed'] ? 'done' : null; }
        if (array_key_exists('owner', $in)) { $o = (int)$in['owner']; $f['assigned_to'] = $o && isset($this->repo->people()[$o]) ? $o : null; }
        if (array_key_exists('due', $in)) $f['due_date'] = Columns::date($in['due']);
        if (!$f) return $this->subitemOut($s);
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $this->db->prepare("UPDATE board_subitems SET $set WHERE id = ?")->execute(array_merge(array_values($f), [$subId]));
        if (isset($f['completed']) && (int)$s['completed'] !== $f['completed']) {
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], (int)$it['id'], 'subitem_done', ['title' => $s['title'], 'done' => (bool)$f['completed']]);
        }
        if (!empty($f['assigned_to']) && (int)$f['assigned_to'] !== (int)$s['assigned_to'] && (int)$f['assigned_to'] !== $this->repo->userId) {
            ProjectNotify::subitemAssigned($this->repo, (int)$it['id'], (int)$f['assigned_to'], $f['title'] ?? $s['title']);
        }
        $this->subitemsChanged($it);
        return $this->subitemOut($this->subitemRow($subId));
    }

    public function deleteSubitem(int $subId): void
    {
        $s = $this->subitemRow($subId);
        $it = $this->repo->requireItem((int)$s['parent_item_id'], 2);
        $this->db->prepare("DELETE FROM board_subitems WHERE id = ?")->execute([$subId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], (int)$it['id'], 'subitem_deleted', ['title' => $s['title']]);
        $this->subitemsChanged($it);
    }

    public function reorderSubitems(int $itemId, array $order): void
    {
        $this->repo->requireItem($itemId, 2);
        $st = $this->db->prepare("UPDATE board_subitems SET position = ? WHERE id = ? AND parent_item_id = ?");
        foreach (array_values(array_map('intval', $order)) as $i => $id) $st->execute([$i, $id, $itemId]);
    }

    /** Turn a subitem into an item of its own (same group, below its parent). */
    public function promoteSubitem(int $subId): array
    {
        $s = $this->subitemRow($subId);
        $it = $this->repo->requireItem((int)$s['parent_item_id'], 2);
        $new = $this->create((int)$it['board_id'], (int)$it['group_id'], (string)$s['title']);
        $this->db->prepare("DELETE FROM board_subitems WHERE id = ?")->execute([$subId]);
        $this->subitemsChanged($it);
        return $new;
    }

    // ================================================================= updates (comments)

    private function updateOut(array $c): array
    {
        return ['id' => (int)$c['id'], 'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null, 'user_id' => (int)$c['user_id'],
                'user' => $this->repo->personName((int)$c['user_id']), 'body' => (string)$c['comment'],
                'at' => $c['created_at'], 'edited' => !empty($c['edited_at']), 'mine' => (int)$c['user_id'] === $this->repo->userId];
    }

    public function updates(int $itemId): array
    {
        $this->repo->requireItem($itemId, 1);
        $st = $this->db->prepare("SELECT * FROM board_item_comments WHERE item_id = ? AND company_id = ? ORDER BY created_at, id");
        $st->execute([$itemId, $this->repo->companyId]);
        $rows = array_map([$this, 'updateOut'], $st->fetchAll(PDO::FETCH_ASSOC));
        $top = [];
        $replies = [];
        foreach ($rows as $r) {
            if ($r['parent_id']) $replies[$r['parent_id']][] = $r;
            else $top[] = $r;
        }
        foreach ($top as &$t) $t['replies'] = $replies[$t['id']] ?? [];
        unset($t);
        // Orphaned replies (parent deleted) still show, as top-level updates.
        $ids = array_column($top, 'id');
        foreach ($replies as $pid => $list) if (!in_array($pid, $ids, true)) foreach ($list as $r) $top[] = $r + ['replies' => []];
        usort($top, fn($a, $b) => strcmp($b['at'], $a['at']) ?: $b['id'] <=> $a['id']);
        return $top;
    }

    /** @return int[] user ids mentioned as @[Name](id) */
    public static function mentions(string $body): array
    {
        preg_match_all('/@\[[^\]]{1,120}\]\((\d{1,10})\)/u', $body, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    public function addUpdate(int $itemId, string $body, ?int $parentId = null): array
    {
        $it = $this->repo->requireItem($itemId, 1);
        $body = trim(str_replace("\0", '', $body));
        if ($body === '') throw new ProjectUserError('Write something first.');
        if (mb_strlen($body) > 10000) throw new ProjectUserError('That update is too long (10 000 characters at most).');
        if ($parentId) {
            $st = $this->db->prepare("SELECT parent_id FROM board_item_comments WHERE id = ? AND item_id = ? AND company_id = ?");
            $st->execute([$parentId, $itemId, $this->repo->companyId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new ProjectUserError('That update was deleted.');
            if ($p['parent_id']) $parentId = (int)$p['parent_id']; // replies stay one level deep
        }
        $this->db->prepare("INSERT INTO board_item_comments (company_id, item_id, parent_id, user_id, comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$this->repo->companyId, $itemId, $parentId ?: null, $this->repo->userId, $body]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare("INSERT IGNORE INTO board_watchers (item_id, user_id, created_at) VALUES (?, ?, NOW())")->execute([$itemId, $this->repo->userId]);
        $this->db->prepare("UPDATE board_items SET updated_at = NOW() WHERE id = ?")->execute([$itemId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], $itemId, 'update_added', ['comment_id' => $id, 'excerpt' => Activity::clip(preg_replace('/@\[([^\]]+)\]\(\d+\)/u', '@$1', $body), 100), 'reply' => (bool)$parentId]);
        $mentioned = array_values(array_filter(self::mentions($body), fn($u) => isset($this->repo->people()[$u])));
        ProjectNotify::update($this->repo, $it, $id, $body, $mentioned, $parentId);
        $st = $this->db->prepare("SELECT * FROM board_item_comments WHERE id = ?");
        $st->execute([$id]);
        return $this->updateOut($st->fetch(PDO::FETCH_ASSOC)) + ['replies' => []];
    }

    public function editUpdate(int $commentId, string $body): array
    {
        $c = $this->commentRow($commentId);
        $this->repo->requireItem((int)$c['item_id'], 1);
        if ((int)$c['user_id'] !== $this->repo->userId) throw new ProjectForbidden('You can only edit your own updates.');
        $body = trim(str_replace("\0", '', $body));
        if ($body === '') throw new ProjectUserError('Write something first.');
        if (mb_strlen($body) > 10000) throw new ProjectUserError('That update is too long (10 000 characters at most).');
        $this->db->prepare("UPDATE board_item_comments SET comment = ?, edited_at = NOW() WHERE id = ?")->execute([$body, $commentId]);
        return $this->updateOut($this->commentRow($commentId));
    }

    public function deleteUpdate(int $commentId): void
    {
        $c = $this->commentRow($commentId);
        $it = $this->repo->requireItem((int)$c['item_id'], 1);
        if ((int)$c['user_id'] !== $this->repo->userId && $it['_board']['level'] < 3) throw new ProjectForbidden('You can only delete your own updates.');
        $this->db->prepare("DELETE FROM board_item_comments WHERE id = ? OR parent_id = ?")->execute([$commentId, $commentId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], (int)$it['id'], 'update_deleted', []);
    }

    private function commentRow(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM board_item_comments WHERE id = ? AND company_id = ?");
        $st->execute([$id, $this->repo->companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new ProjectUserError('That update was deleted.');
        return $c;
    }

    // ================================================================= watchers / dependencies

    public function watch(int $itemId, bool $on): void
    {
        $this->repo->requireItem($itemId, 1);
        if ($on) $this->db->prepare("INSERT IGNORE INTO board_watchers (item_id, user_id, created_at) VALUES (?, ?, NOW())")->execute([$itemId, $this->repo->userId]);
        else $this->db->prepare("DELETE FROM board_watchers WHERE item_id = ? AND user_id = ?")->execute([$itemId, $this->repo->userId]);
    }

    /** $successor waits on $predecessor (finish-to-start by default). */
    public function addDependency(int $predecessor, int $successor, string $type = 'FS', int $lag = 0): int
    {
        $a = $this->repo->requireItem($predecessor, 2);
        $b = $this->repo->requireItem($successor, 2);
        if ($predecessor === $successor) throw new ProjectUserError('An item cannot wait on itself.');
        if ((int)$a['board_id'] !== (int)$b['board_id']) throw new ProjectUserError('Dependencies link items on the same board.');
        $boardId = (int)$a['board_id'];
        $type = in_array($type, ['FS', 'SS', 'FF', 'SF'], true) ? $type : 'FS';
        $st = $this->db->prepare("SELECT predecessor_item, successor_item FROM board_dependencies WHERE board_id = ?");
        $st->execute([$boardId]);
        $edges = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$p, $s]) {
            if ((int)$p === $predecessor && (int)$s === $successor) throw new ProjectUserError('These items are already linked.');
            $edges[(int)$p][] = (int)$s;
        }
        // Refuse cycles: is the predecessor reachable from the successor?
        $seen = [];
        $stack = [$successor];
        while ($stack) {
            $n = array_pop($stack);
            if ($n === $predecessor) throw new ProjectUserError('That would make a loop of items waiting on each other.');
            if (isset($seen[$n])) continue;
            $seen[$n] = true;
            foreach ($edges[$n] ?? [] as $m) $stack[] = $m;
        }
        $this->db->prepare("INSERT INTO board_dependencies (board_id, predecessor_item, successor_item, dependency_type, lag_days, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$boardId, $predecessor, $successor, $type, max(-365, min(365, $lag))]);
        $id = (int)$this->db->lastInsertId();
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, $successor, 'dependency_added', ['waiting_on' => $a['title'], 'item_id' => $predecessor]);
        return $id;
    }

    public function removeDependency(int $depId): void
    {
        $st = $this->db->prepare("SELECT d.* FROM board_dependencies d JOIN project_boards b ON b.board_id = d.board_id WHERE d.id = ? AND b.company_id = ?");
        $st->execute([$depId, $this->repo->companyId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return;
        $this->repo->requireBoard((int)$d['board_id'], 2);
        $this->db->prepare("DELETE FROM board_dependencies WHERE id = ?")->execute([$depId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$d['board_id'], (int)$d['successor_item'], 'dependency_removed', ['item_id' => (int)$d['predecessor_item']]);
    }

    // ================================================================= files

    /**
     * Store an uploaded file on an item (optionally in a files column).
     * @param array $file one entry of $_FILES
     */
    public function addFile(int $itemId, array $file, ?int $columnId = null): array
    {
        $it = $this->repo->requireItem($itemId, 2);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new ProjectUserError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'That file is too large.' : 'The upload did not arrive. Please try again.');
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\/\\\\]+/u', ' ', (string)$file['name'])), 0, 200) ?: 'file';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FILE_TYPES, true)) throw new ProjectUserError('That type of file cannot be attached (' . ($ext ?: 'no extension') . ').');
        $size = (int)$file['size'];
        if ($size <= 0 || $size > self::MAX_FILE) throw new ProjectUserError('Files can be up to 25 MB.');
        $st = $this->db->prepare("SELECT COUNT(*) FROM board_item_attachments WHERE item_id = ?");
        $st->execute([$itemId]);
        if ((int)$st->fetchColumn() >= 200) throw new ProjectUserError('An item can hold up to 200 files.');
        if ($columnId) {
            $st = $this->db->prepare("SELECT 1 FROM board_columns WHERE column_id = ? AND board_id = ? AND type = 'files'");
            $st->execute([$columnId, (int)$it['board_id']]);
            if (!$st->fetchColumn()) $columnId = null;
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) { $mime = finfo_file($fi, $file['tmp_name']) ?: $mime; finfo_close($fi); }
        $dir = ProjectPaths::storageDir();
        if ($dir === null) throw new ProjectUserError('File storage is not available on the server.');
        $rel = 'storage/projects/' . $this->repo->companyId . '/' . (int)$it['board_id'];
        $abs = ProjectPaths::root() . '/' . $rel;
        if (!is_dir($abs) && !@mkdir($abs, 0750, true)) throw new ProjectUserError('File storage is not available on the server.');
        $stored = bin2hex(random_bytes(16)) . '.bin';
        if (!move_uploaded_file($file['tmp_name'], $abs . '/' . $stored)) throw new ProjectUserError('The file could not be saved.');
        $this->db->prepare("INSERT INTO board_item_attachments (company_id, item_id, column_id, file_name, file_path, file_size, mime_type, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$this->repo->companyId, $itemId, $columnId, $name, $rel . '/' . $stored, $size, mb_substr($mime, 0, 100), $this->repo->userId]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare("UPDATE board_items SET updated_at = NOW() WHERE id = ?")->execute([$itemId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$it['board_id'], $itemId, 'file_added', ['name' => $name, 'file_id' => $id]);
        return ['id' => $id, 'name' => $name, 'size' => $size, 'mime' => $mime, 'column_id' => $columnId];
    }

    /** @return array the attachment row (access checked) */
    public function fileRow(int $fileId, int $min = 1): array
    {
        $st = $this->db->prepare("SELECT a.*, i.board_id FROM board_item_attachments a JOIN board_items i ON i.id = a.item_id
                                   WHERE a.id = ? AND i.company_id = ?");
        $st->execute([$fileId, $this->repo->companyId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new ProjectUserError('File not found.');
        $b = $this->repo->requireBoard((int)$f['board_id'], $min);
        return $f + ['_level' => $b['level']];
    }

    /** @return string|null the stored path to unlink */
    public function deleteFile(int $fileId): ?string
    {
        $f = $this->fileRow($fileId, 2);
        if ((int)$f['uploaded_by'] !== $this->repo->userId && $f['_level'] < 3) throw new ProjectForbidden('Only the person who added a file, or a board manager, can remove it.');
        $this->db->prepare("DELETE FROM board_item_attachments WHERE id = ?")->execute([$fileId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$f['board_id'], (int)$f['item_id'], 'file_removed', ['name' => $f['file_name']]);
        return (string)$f['file_path'];
    }

    /** Files on every board of a project (the project's Files page). */
    public function projectFiles(int $projectId): array
    {
        $this->repo->requireProject($projectId, 1);
        $st = $this->db->prepare("SELECT a.id, a.item_id, a.file_name, a.file_size, a.mime_type, a.uploaded_by, a.uploaded_at, i.title AS item, i.board_id, b.title AS board
                                    FROM board_item_attachments a
                                    JOIN board_items i ON i.id = a.item_id AND i.company_id = ?
                                    JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                   WHERE b.project_id = ? AND (b.archived = 0 OR b.archived IS NULL) AND (i.archived = 0 OR i.archived IS NULL)
                                ORDER BY a.uploaded_at DESC, a.id DESC LIMIT 1000");
        $st->execute([$this->repo->companyId, $projectId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($this->repo->boardLevel((int)$f['board_id']) < 1) continue;
            $out[] = ['id' => (int)$f['id'], 'name' => $f['file_name'], 'size' => (int)$f['file_size'], 'mime' => (string)$f['mime_type'],
                      'by' => $this->repo->personName((int)$f['uploaded_by']), 'at' => $f['uploaded_at'], 'item' => $f['item'], 'item_id' => (int)$f['item_id'],
                      'board' => $f['board'], 'board_id' => (int)$f['board_id']];
        }
        return $out;
    }
}
