<?php
// projects/lib/BoardRepo.php
//
// Boards and their structure: create (from a template), load (everything the
// board page needs in one payload), rename, archive, copy, move, delete;
// groups; columns; saved views; formulas; and the items' own fields that the
// rest of Flowwork reads (see Columns::mirrors()).

final class BoardRepo
{
    public const VIEWS = ['table', 'kanban', 'calendar', 'gantt', 'workload', 'chart'];
    public const GROUP_COLORS = ['#2f5c84', '#7a5ab8', '#b08d57', '#2f7457', '#7a1f30', '#c06a2b', '#1f7a7a', '#b4577a', '#5b6670', '#4b4fa8', '#6b7d2b', '#a8281e'];
    private const BOARD_TYPES = ['schedule', 'costing', 'procurement', 'quality', 'safety', 'general'];

    private PDO $db;

    public function __construct(private ProjectRepo $repo)
    {
        $this->db = $repo->db;
    }

    // ================================================================= boards

    /**
     * Create a board on a project.
     * @param array $in title, template, default_view, description, sort_order
     */
    public function createBoard(int $projectId, array $in, bool $check = true): int
    {
        if ($check) $this->repo->requireProject($projectId, 3);
        $tpl = Templates::board((string)($in['template'] ?? 'blank'));
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 255) ?: $tpl['title'];
        $view = in_array($in['default_view'] ?? '', self::VIEWS, true) ? $in['default_view'] : ($tpl['view'] ?? 'table');
        $type = in_array($tpl['type'], self::BOARD_TYPES, true) ? $tpl['type'] : 'general';
        $order = isset($in['sort_order']) ? (int)$in['sort_order'] : $this->nextBoardOrder($projectId);
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO project_boards (company_id, project_id, board_type, title, default_view, description, sort_order, archived, is_active, created_by, item_noun, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, NOW())")
                ->execute([$this->repo->companyId, $projectId, $type, $title, $view,
                           mb_substr(trim((string)($in['description'] ?? '')), 0, 2000) ?: null, $order, $this->repo->userId, $tpl['noun'] ?? null]);
            $bid = (int)$this->db->lastInsertId();
            $gi = $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, ?, ?, 0, ?, NOW())");
            foreach ($tpl['groups'] as $i => [$name, $color]) $gi->execute([$bid, $name, $i, $color]);
            $ci = $this->db->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())");
            foreach ($tpl['columns'] as $i => $c) {
                $ci->execute([$bid, $this->repo->companyId, $c[0], $c[1], isset($c[3]) ? json_encode($c[3], JSON_UNESCAPED_UNICODE) : null, $i, $c[2] ?? 150]);
            }
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $bid, null, 'board_created', ['title' => $title, 'project_id' => $projectId, 'template' => $in['template'] ?? 'blank']);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
        return $bid;
    }

    private function nextBoardOrder(int $projectId): int
    {
        $st = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM project_boards WHERE project_id = ? AND company_id = ?");
        $st->execute([$projectId, $this->repo->companyId]);
        return (int)$st->fetchColumn();
    }

    public function updateBoard(int $boardId, array $in): void
    {
        $b = $this->repo->requireBoard($boardId, 2);
        $f = [];
        if (array_key_exists('title', $in)) {
            $t = mb_substr(trim((string)$in['title']), 0, 255);
            if ($t === '') throw new ProjectUserError('Give the board a name.');
            $f['title'] = $t;
        }
        if (array_key_exists('description', $in)) $f['description'] = mb_substr(trim((string)$in['description']), 0, 2000) ?: null;
        if (array_key_exists('default_view', $in)) {
            if (!in_array($in['default_view'], self::VIEWS, true)) throw new ProjectUserError('Unknown view.');
            $f['default_view'] = $in['default_view'];
        }
        if (array_key_exists('item_noun', $in)) $f['item_noun'] = mb_substr(trim(strtolower((string)$in['item_noun'])), 0, 40) ?: null;
        if (array_key_exists('type', $in)) $f['board_type'] = in_array($in['type'], self::BOARD_TYPES, true) ? $in['type'] : 'general';
        if (!$f) return;
        if ((isset($f['default_view']) || isset($f['board_type'])) && $b['level'] < 3) unset($f['default_view'], $f['board_type']);
        if (!$f) throw new ProjectForbidden('Only the board’s managers can change that.');
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $this->db->prepare("UPDATE project_boards SET $set WHERE board_id = ? AND company_id = ?")->execute(array_merge(array_values($f), [$boardId, $this->repo->companyId]));
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, isset($f['title']) ? 'board_renamed' : 'board_updated',
                      isset($f['title']) ? ['from' => $b['title'], 'to' => $f['title']] : ['fields' => array_keys($f)]);
        $this->repo->forgetBoard($boardId);
    }

    public function archiveBoard(int $boardId, bool $archived): void
    {
        $b = $this->repo->requireBoard($boardId, 3);
        $this->db->prepare("UPDATE project_boards SET archived = ? WHERE board_id = ? AND company_id = ?")->execute([$archived ? 1 : 0, $boardId, $this->repo->companyId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, $archived ? 'board_archived' : 'board_restored', ['title' => $b['title']]);
        $this->repo->forgetBoard($boardId);
    }

    /** Delete a board for good. @return string[] stored file paths to remove */
    public function deleteBoard(int $boardId): array
    {
        $b = $this->repo->requireBoard($boardId, 3);
        $this->db->beginTransaction();
        try {
            $files = $this->purgeBoard($boardId);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, null, null, 'board_deleted', ['project_id' => (int)$b['project_id'], 'title' => $b['title']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->repo->forgetBoard($boardId);
        return $files;
    }

    /** Remove a board and everything on it (caller holds the transaction and has checked access). */
    public function purgeBoard(int $boardId): array
    {
        $co = $this->repo->companyId;
        $st = $this->db->prepare("SELECT id FROM board_items WHERE board_id = ? AND company_id = ?");
        $st->execute([$boardId, $co]);
        $itemIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $files = [];
        if ($itemIds) {
            $in = implode(',', $itemIds);
            $files = $this->db->query("SELECT file_path FROM board_item_attachments WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['board_item_values', 'board_item_comments', 'board_item_attachments', 'board_watchers', 'board_item_people'] as $t) {
                $this->db->exec("DELETE FROM `$t` WHERE item_id IN ($in)");
            }
            $this->db->exec("DELETE FROM board_subitems WHERE parent_item_id IN ($in)");
            $this->db->exec("DELETE FROM board_dependencies WHERE predecessor_item IN ($in) OR successor_item IN ($in)");
            try { $this->db->exec("DELETE l FROM calendar_event_links l WHERE l.linked_type = 'item' AND l.linked_id IN ($in)"); } catch (Throwable $e) { /* calendar not installed */ }
            try { $this->db->exec("DELETE FROM email_links WHERE linked_type = 'item' AND linked_id IN ($in)"); } catch (Throwable $e) { /* mail not installed */ }
            $this->db->exec("UPDATE board_audit_log SET item_id = NULL WHERE item_id IN ($in)");
        }
        $this->db->prepare("DELETE FROM board_items WHERE board_id = ? AND company_id = ?")->execute([$boardId, $co]);
        $this->db->prepare("DELETE FROM board_columns WHERE board_id = ? AND company_id = ?")->execute([$boardId, $co]);
        // Tables keyed by board only — the caller has already checked the board is this company's.
        foreach (['board_groups', 'board_saved_views', 'board_dependencies', 'board_members', 'board_column_settings', 'board_guests'] as $t) {
            try { $this->db->prepare("DELETE FROM `$t` WHERE board_id = ?")->execute([$boardId]); } catch (Throwable $e) { /* optional table */ }
        }
        try {
            $this->db->prepare("DELETE FROM board_automation_logs WHERE automation_id IN (SELECT id FROM board_automations WHERE board_id = ?)")->execute([$boardId]);
            $this->db->prepare("DELETE FROM board_automations WHERE board_id = ?")->execute([$boardId]);
        } catch (Throwable $e) { /* optional table */ }
        $this->db->prepare("DELETE FROM project_user_state WHERE kind = 'board' AND ref_id = ? AND company_id = ?")->execute([$boardId, $co]);
        $this->db->prepare("UPDATE board_audit_log SET board_id = NULL WHERE board_id = ? AND company_id = ?")->execute([$boardId, $co]);
        $this->db->prepare("DELETE FROM project_boards WHERE board_id = ? AND company_id = ?")->execute([$boardId, $co]);
        return array_values(array_filter(array_map('strval', $files)));
    }

    /**
     * Copy a board (columns, groups, optionally items with values and subitems)
     * to a project. @return int the new board id
     */
    public function copyBoard(int $boardId, int $targetProjectId, bool $withItems, bool $check = true, ?string $title = null): int
    {
        $b = $check ? $this->repo->requireBoard($boardId, 1) : $this->repo->boardRow($boardId);
        if (!$b) throw new ProjectUserError('Board not found.');
        if ($check) $this->repo->requireProject($targetProjectId, 3);
        $co = $this->repo->companyId;
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO project_boards (company_id, project_id, board_type, title, default_view, description, sort_order, archived, is_active, created_by, item_noun, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, NOW())")
                ->execute([$co, $targetProjectId, $b['board_type'] ?: 'general', mb_substr($title ?? ($targetProjectId === (int)$b['project_id'] ? 'Copy of ' . $b['title'] : $b['title']), 0, 255),
                           $b['default_view'] ?: 'table', $b['description'], $this->nextBoardOrder($targetProjectId), $this->repo->userId, $b['item_noun'] ?? null]);
            $nb = (int)$this->db->lastInsertId();
            $colMap = [];
            $st = $this->db->prepare("SELECT * FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position, column_id");
            $st->execute([$boardId, $co]);
            $ci = $this->db->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ci->execute([$nb, $co, $c['name'], $c['type'], $c['config'], $c['position'], $c['visible'], $c['width'], $c['description'] ?? null]);
                $colMap[(int)$c['column_id']] = (int)$this->db->lastInsertId();
            }
            $groupMap = [];
            $st = $this->db->prepare("SELECT * FROM board_groups WHERE board_id = ? ORDER BY position, id");
            $st->execute([$boardId]);
            $gi = $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, ?, ?, 0, ?, NOW())");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $gi->execute([$nb, $g['name'], $g['position'], $g['color']]);
                $groupMap[(int)$g['id']] = (int)$this->db->lastInsertId();
            }
            if ($withItems && $groupMap) {
                $st = $this->db->prepare("SELECT * FROM board_items WHERE board_id = ? AND company_id = ? AND (archived = 0 OR archived IS NULL) ORDER BY position, id");
                $st->execute([$boardId, $co]);
                $items = new ItemRepo($this->repo);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    if (!isset($groupMap[(int)$it['group_id']])) continue;
                    $items->copyItemRow($it, $nb, $groupMap[(int)$it['group_id']], $colMap, (int)$it['position']);
                }
            }
            Activity::log($this->db, $co, $this->repo->userId, $nb, null, 'board_created', ['title' => $b['title'], 'project_id' => $targetProjectId, 'copied_from' => $boardId]);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
        if ($withItems) self::refreshMirrors($this->db, $co, $nb);
        return $nb;
    }

    /** Move a board to another project. */
    public function moveBoard(int $boardId, int $targetProjectId): void
    {
        $b = $this->repo->requireBoard($boardId, 3);
        $this->repo->requireProject($targetProjectId, 3);
        if ((int)$b['project_id'] === $targetProjectId) return;
        $this->db->prepare("UPDATE project_boards SET project_id = ?, sort_order = ? WHERE board_id = ? AND company_id = ?")
            ->execute([$targetProjectId, $this->nextBoardOrder($targetProjectId), $boardId, $this->repo->companyId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'board_moved', ['from_project' => (int)$b['project_id'], 'to_project' => $targetProjectId]);
        $this->repo->forgetBoard($boardId);
    }

    public function reorderBoards(int $projectId, array $ids): void
    {
        $this->repo->requireProject($projectId, 3);
        $st = $this->db->prepare("UPDATE project_boards SET sort_order = ? WHERE board_id = ? AND project_id = ? AND company_id = ?");
        foreach (array_values(array_map('intval', $ids)) as $i => $id) $st->execute([$i, $id, $projectId, $this->repo->companyId]);
    }

    // ================================================================= load

    /** Columns of a board, ordered (raw rows). */
    public function columnRows(int $boardId): array
    {
        $st = $this->db->prepare("SELECT * FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position, column_id");
        $st->execute([$boardId, $this->repo->companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function columnOut(array $c): array
    {
        return ['id' => (int)$c['column_id'], 'name' => (string)$c['name'], 'type' => (string)$c['type'], 'config' => Columns::config($c),
                'width' => max(60, min(600, (int)($c['width'] ?: 150))), 'hidden' => isset($c['visible']) && !(int)$c['visible'],
                'description' => (string)($c['description'] ?? '')];
    }

    /** Everything the board page needs. */
    public function load(int $boardId): array
    {
        $b = $this->repo->requireBoard($boardId, 1);
        $co = $this->repo->companyId;
        $cols = $this->columnRows($boardId);
        $groups = $this->groupRows($boardId);
        if (!$groups && $b['level'] >= 2) {
            $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, 'Group 1', 0, 0, '#2f5c84', NOW())")->execute([$boardId]);
            $groups = $this->groupRows($boardId);
        }
        $prefs = $this->repo->prefs('board', $boardId);
        $items = $this->itemsPayload($boardId, $cols, null);
        $hasType = fn(string $t) => (bool)array_filter($cols, fn($c) => $c['type'] === $t);

        $suppliers = [];
        if ($hasType('supplier')) {
            try {
                $st = $this->db->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier'
                                            AND (status = 'active' OR status IS NULL) ORDER BY name LIMIT 2000");
                $st->execute([$co]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $suppliers[] = ['id' => (int)$s['id'], 'name' => $s['name']];
            } catch (Throwable $e) { error_log('[projects] suppliers: ' . $e->getMessage()); }
        }

        $deps = [];
        $st = $this->db->prepare("SELECT id, predecessor_item, successor_item, dependency_type, lag_days FROM board_dependencies WHERE board_id = ?");
        $st->execute([$boardId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $deps[] = ['id' => (int)$d['id'], 'from' => (int)$d['predecessor_item'], 'to' => (int)$d['successor_item'], 'type' => $d['dependency_type'] ?: 'FS', 'lag' => (int)$d['lag_days']];

        $st = $this->db->prepare("SELECT starred FROM project_user_state WHERE user_id = ? AND kind = 'board' AND ref_id = ?");
        $st->execute([$this->repo->userId, $boardId]);
        $starred = (bool)$st->fetchColumn();

        $st = $this->db->prepare("SELECT COUNT(*) FROM board_automations WHERE board_id = ? AND is_active = 1");
        $st->execute([$boardId]);
        $automations = (int)$st->fetchColumn();

        $st = $this->db->prepare("SELECT COUNT(*) FROM board_items WHERE board_id = ? AND company_id = ? AND archived = 1");
        $st->execute([$boardId, $co]);
        $archivedCount = (int)$st->fetchColumn();

        $project = $this->repo->projectRow((int)$b['project_id']);
        return [
            'board' => ProjectRepo::boardSummary($b) + ['level' => (int)$b['level'], 'starred' => $starred, 'project_name' => $b['project_name'],
                       'project_color' => ProjectRepo::projectColor($project ?: ['project_id' => $b['project_id']]), 'project_status' => $b['project_status'],
                       'automations' => $automations, 'archived_items' => $archivedCount],
            'columns' => array_map([self::class, 'columnOut'], $cols),
            'groups' => array_map(fn($g) => self::groupOut($g, $prefs), $groups),
            'items' => $items,
            'suppliers' => $suppliers,
            'deps' => $deps,
            'views' => $this->savedViews($boardId),
            'prefs' => $prefs,
            'cursor' => Activity::cursor($this->db, $boardId),
            'members' => array_map(fn($m) => ['id' => $m['id'], 'role' => $m['role']], $this->repo->members((int)$b['project_id'])),
        ];
    }

    /**
     * Items as the browser wants them: values decoded, counts of subitems,
     * updates and files. $ids limits to those items (live updates).
     */
    public function itemsPayload(int $boardId, array $cols, ?array $ids, bool $archived = false): array
    {
        $co = $this->repo->companyId;
        $where = "i.board_id = ? AND i.company_id = ? AND " . ($archived ? "i.archived = 1" : "(i.archived = 0 OR i.archived IS NULL)");
        $args = [$boardId, $co];
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) return [];
            $where .= ' AND i.id IN (' . implode(',', $ids) . ')';
        }
        $st = $this->db->prepare("SELECT i.id, i.group_id, i.title, i.description, i.position, i.created_by, i.created_at, i.updated_at, i.archived
                                    FROM board_items i LEFT JOIN board_groups g ON g.id = i.group_id
                                   WHERE $where
                                ORDER BY g.position, i.group_id, i.position, i.id LIMIT 5000");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $in = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $vals = [];
        foreach ($this->db->query("SELECT item_id, column_id, value FROM board_item_values WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $vals[(int)$v['item_id']][(int)$v['column_id']] = $v['value'];
        }
        $count = function (string $sql) {
            $out = [];
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) $out[(int)$r[0]] = [(int)$r[1], (int)($r[2] ?? 0)];
            return $out;
        };
        $subs = $count("SELECT parent_item_id, COUNT(*), SUM(completed = 1) FROM board_subitems WHERE parent_item_id IN ($in) GROUP BY parent_item_id");
        $upd = $count("SELECT item_id, COUNT(*) FROM board_item_comments WHERE item_id IN ($in) GROUP BY item_id");
        $files = [];
        foreach ($this->db->query("SELECT id, item_id, column_id, file_name, file_size, mime_type FROM board_item_attachments WHERE item_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $files[(int)$f['item_id']][] = ['id' => (int)$f['id'], 'name' => $f['file_name'], 'size' => (int)$f['file_size'], 'mime' => (string)$f['mime_type'],
                                            'column_id' => $f['column_id'] ? (int)$f['column_id'] : null];
        }
        $colsById = [];
        foreach ($cols as $c) $colsById[(int)$c['column_id']] = $c;
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $raw = $vals[$id] ?? [];
            [$sn, $sd] = $subs[$id] ?? [0, 0];
            $values = [];
            foreach ($colsById as $cid => $c) {
                $t = $c['type'];
                if ($t === 'progress') {
                    $v = Columns::progressValue($c, $cols, $raw, $sn ? $sd * 100 / $sn : null);
                    if ($v !== null) $values[$cid] = $v;
                    continue;
                }
                if (in_array($t, ['files', 'created', 'updated'], true)) continue;
                if (!array_key_exists($cid, $raw)) continue;
                $d = Columns::decode($c, $raw[$cid]);
                if ($d !== null) $values[$cid] = $d;
            }
            $out[] = ['id' => $id, 'group_id' => (int)$r['group_id'], 'title' => (string)$r['title'], 'position' => (int)$r['position'],
                      'values' => (object)$values, 'subitems' => $sn, 'subitems_done' => $sd, 'updates' => $upd[$id][0] ?? 0,
                      'files' => $files[$id] ?? [], 'created_by' => (int)$r['created_by'], 'created_at' => $r['created_at'],
                      'updated_at' => $r['updated_at'] ?: $r['created_at'], 'has_description' => trim((string)$r['description']) !== ''];
        }
        return $out;
    }

    // ================================================================= groups

    public function groupRows(int $boardId): array
    {
        $st = $this->db->prepare("SELECT * FROM board_groups WHERE board_id = ? ORDER BY position, id");
        $st->execute([$boardId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function groupOut(array $g, array $prefs = []): array
    {
        $collapsed = isset($prefs['collapsed']) && is_array($prefs['collapsed']) ? in_array((int)$g['id'], array_map('intval', $prefs['collapsed']), true) : (bool)$g['collapsed'];
        return ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'color' => Columns::color($g['color'] ?? null, (int)$g['id']), 'position' => (int)$g['position'], 'collapsed' => $collapsed];
    }

    private function groupRow(int $groupId): array
    {
        $st = $this->db->prepare("SELECT g.* FROM board_groups g JOIN project_boards b ON b.board_id = g.board_id WHERE g.id = ? AND b.company_id = ?");
        $st->execute([$groupId, $this->repo->companyId]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) throw new ProjectUserError('Group not found.');
        return $g;
    }

    public function createGroup(int $boardId, string $name, ?string $color, string $where = 'top'): array
    {
        $this->repo->requireBoard($boardId, 2);
        $name = mb_substr(trim($name), 0, 255) ?: 'New group';
        $st = $this->db->prepare("SELECT COUNT(*) FROM board_groups WHERE board_id = ?");
        $st->execute([$boardId]);
        $n = (int)$st->fetchColumn();
        if ($n >= 200) throw new ProjectUserError('A board can have up to 200 groups.');
        $color = $color && preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : self::GROUP_COLORS[$n % count(self::GROUP_COLORS)];
        if ($where === 'top') {
            $this->db->prepare("UPDATE board_groups SET position = position + 1 WHERE board_id = ?")->execute([$boardId]);
            $pos = 0;
        } else {
            $pos = $n;
        }
        $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, ?, ?, 0, ?, NOW())")->execute([$boardId, $name, $pos, $color]);
        $id = (int)$this->db->lastInsertId();
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'group_created', ['group_id' => $id, 'name' => $name]);
        return self::groupOut($this->groupRow($id));
    }

    public function updateGroup(int $groupId, array $in): array
    {
        $g = $this->groupRow($groupId);
        $this->repo->requireBoard((int)$g['board_id'], 2);
        $f = [];
        if (array_key_exists('name', $in)) {
            $n = mb_substr(trim((string)$in['name']), 0, 255);
            if ($n === '') throw new ProjectUserError('Give the group a name.');
            $f['name'] = $n;
        }
        if (array_key_exists('color', $in)) $f['color'] = Columns::color($in['color'] ?? null, $groupId);
        if (!$f) return self::groupOut($g);
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $this->db->prepare("UPDATE board_groups SET $set WHERE id = ?")->execute(array_merge(array_values($f), [$groupId]));
        if (isset($f['name']) && $f['name'] !== $g['name']) {
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, (int)$g['board_id'], null, 'group_renamed', ['group_id' => $groupId, 'from' => $g['name'], 'to' => $f['name']]);
        }
        return self::groupOut($this->groupRow($groupId));
    }

    public function reorderGroups(int $boardId, array $ids): void
    {
        $this->repo->requireBoard($boardId, 2);
        $st = $this->db->prepare("UPDATE board_groups SET position = ? WHERE id = ? AND board_id = ?");
        foreach (array_values(array_map('intval', $ids)) as $i => $id) $st->execute([$i, $id, $boardId]);
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'groups_reordered', []);
    }

    /** Delete a group; its items are archived (restorable) unless moved first. */
    public function deleteGroup(int $groupId): void
    {
        $g = $this->groupRow($groupId);
        $boardId = (int)$g['board_id'];
        $this->repo->requireBoard($boardId, 2);
        $st = $this->db->prepare("SELECT COUNT(*) FROM board_groups WHERE board_id = ?");
        $st->execute([$boardId]);
        if ((int)$st->fetchColumn() <= 1) throw new ProjectUserError('A board needs at least one group.');
        $this->db->beginTransaction();
        try {
            // Archived items need a group to come back to: park them in the first remaining group.
            $st = $this->db->prepare("SELECT id FROM board_groups WHERE board_id = ? AND id <> ? ORDER BY position, id LIMIT 1");
            $st->execute([$boardId, $groupId]);
            $fallback = (int)$st->fetchColumn();
            $this->db->prepare("UPDATE board_items SET archived = 1, group_id = ? WHERE group_id = ? AND board_id = ? AND company_id = ?")
                ->execute([$fallback, $groupId, $boardId, $this->repo->companyId]);
            $this->db->prepare("DELETE FROM board_groups WHERE id = ?")->execute([$groupId]);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'group_deleted', ['group_id' => $groupId, 'name' => $g['name']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Copy a group with its items (values, subitems) right below it. */
    public function duplicateGroup(int $groupId): array
    {
        $g = $this->groupRow($groupId);
        $boardId = (int)$g['board_id'];
        $this->repo->requireBoard($boardId, 2);
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE board_groups SET position = position + 1 WHERE board_id = ? AND position > ?")->execute([$boardId, (int)$g['position']]);
            $this->db->prepare("INSERT INTO board_groups (board_id, name, position, collapsed, color, created_at) VALUES (?, ?, ?, 0, ?, NOW())")
                ->execute([$boardId, mb_substr('Copy of ' . $g['name'], 0, 255), (int)$g['position'] + 1, $g['color']]);
            $ng = (int)$this->db->lastInsertId();
            $st = $this->db->prepare("SELECT * FROM board_items WHERE group_id = ? AND board_id = ? AND company_id = ? AND (archived = 0 OR archived IS NULL) ORDER BY position, id");
            $st->execute([$groupId, $boardId, $this->repo->companyId]);
            $items = new ItemRepo($this->repo);
            $ids = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) $ids[] = $items->copyItemRow($it, $boardId, $ng, null, (int)$it['position']);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'group_created', ['group_id' => $ng, 'name' => 'Copy of ' . $g['name'], 'copied_from' => $groupId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($ids) self::refreshMirrors($this->db, $this->repo->companyId, $boardId, $ids);
        return ['group' => self::groupOut($this->groupRow($ng)), 'items' => $this->itemsPayload($boardId, $this->columnRows($boardId), $ids)];
    }

    // ================================================================= columns

    private function columnRow(int $columnId): array
    {
        $st = $this->db->prepare("SELECT * FROM board_columns WHERE column_id = ? AND company_id = ?");
        $st->execute([$columnId, $this->repo->companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new ProjectUserError('Column not found.');
        return $c;
    }

    public function createColumn(int $boardId, array $in): array
    {
        $this->repo->requireBoard($boardId, 2);
        $type = (string)($in['type'] ?? 'text');
        if (!Columns::isType($type)) throw new ProjectUserError('Unknown column type.');
        $cols = $this->columnRows($boardId);
        if (count($cols) >= 100) throw new ProjectUserError('A board can have up to 100 columns.');
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100) ?: Columns::TYPES[$type]['label'];
        $taken = array_map(fn($c) => mb_strtolower($c['name']), $cols);
        $base = $name;
        for ($i = 2; in_array(mb_strtolower($name), $taken, true); $i++) $name = mb_substr($base, 0, 95) . ' ' . $i;
        $config = isset($in['config']) && is_array($in['config']) ? Columns::encodeConfig($type, $in['config'])
                : ($type === 'status' ? json_encode(['labels' => Columns::DEFAULT_STATUS]) : null);
        $after = (int)($in['after'] ?? 0);
        $pos = count($cols);
        if ($after) {
            foreach ($cols as $c) if ((int)$c['column_id'] === $after) $pos = (int)$c['position'] + 1;
            $this->db->prepare("UPDATE board_columns SET position = position + 1 WHERE board_id = ? AND company_id = ? AND position >= ?")->execute([$boardId, $this->repo->companyId, $pos]);
        }
        $width = ['people' => 140, 'status' => 150, 'date' => 130, 'timeline' => 190, 'priority' => 130, 'text' => 180, 'longtext' => 240,
                  'number' => 120, 'formula' => 130, 'checkbox' => 100, 'rating' => 130, 'files' => 140, 'tags' => 170][$type] ?? 150;
        $this->db->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, description, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())")
            ->execute([$boardId, $this->repo->companyId, $name, $type, $config, $pos, $width, mb_substr(trim((string)($in['description'] ?? '')), 0, 255) ?: null]);
        $id = (int)$this->db->lastInsertId();
        Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'column_created', ['column_id' => $id, 'name' => $name, 'type' => $type]);
        if (in_array($type, ['status', 'people', 'date', 'timeline', 'priority', 'progress'], true)) self::refreshMirrors($this->db, $this->repo->companyId, $boardId);
        return self::columnOut($this->columnRow($id));
    }

    /** Rename, resize, describe, reconfigure or change a column's type (text family only). */
    public function updateColumn(int $columnId, array $in): array
    {
        $c = $this->columnRow($columnId);
        $boardId = (int)$c['board_id'];
        $this->repo->requireBoard($boardId, 2);
        $f = [];
        if (array_key_exists('name', $in)) {
            $n = mb_substr(trim((string)$in['name']), 0, 100);
            if ($n === '') throw new ProjectUserError('Give the column a name.');
            foreach ($this->columnRows($boardId) as $o) {
                if ((int)$o['column_id'] !== $columnId && mb_strtolower($o['name']) === mb_strtolower($n)) throw new ProjectUserError('Another column already has that name.');
            }
            $f['name'] = $n;
        }
        if (array_key_exists('width', $in)) $f['width'] = max(60, min(600, (int)$in['width']));
        if (array_key_exists('description', $in)) $f['description'] = mb_substr(trim((string)$in['description']), 0, 255) ?: null;
        if (array_key_exists('hidden', $in)) $f['visible'] = empty($in['hidden']) ? 1 : 0;
        $type = (string)$c['type'];
        if (array_key_exists('type', $in) && $in['type'] !== $type) {
            $text = ['text', 'longtext', 'email', 'phone', 'link'];
            if (!in_array($type, $text, true) || !in_array($in['type'], $text, true)) throw new ProjectUserError('This column cannot change to that type.');
            $f['type'] = $type = (string)$in['type'];
        }
        $formulaChanged = false;
        if (array_key_exists('config', $in) && is_array($in['config'])) {
            $old = Columns::config($c);
            $new = Columns::encodeConfig($type, $in['config']);
            if ($type === 'status' && $new) $this->remapStatusValues($c, $old, json_decode($new, true));
            $f['config'] = $new;
            $formulaChanged = $type === 'formula';
        }
        if (!$f) return self::columnOut($c);
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $this->db->prepare("UPDATE board_columns SET $set WHERE column_id = ? AND company_id = ?")->execute(array_merge(array_values($f), [$columnId, $this->repo->companyId]));
        if (isset($f['name']) && $f['name'] !== $c['name']) {
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'column_renamed', ['column_id' => $columnId, 'from' => $c['name'], 'to' => $f['name']]);
            $this->renameInFormulas($boardId, (string)$c['name'], $f['name']);
        } elseif (isset($f['config']) || isset($f['type'])) {
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'column_updated', ['column_id' => $columnId, 'name' => $c['name']]);
        }
        if ($formulaChanged || isset($f['name'])) $this->recomputeFormulas($boardId);
        if (isset($f['config']) && in_array($type, ['status', 'progress'], true)) self::refreshMirrors($this->db, $this->repo->companyId, $boardId);
        return self::columnOut($this->columnRow($columnId));
    }

    /** Status labels renamed or removed: keep existing cells pointing at the right label. */
    private function remapStatusValues(array $col, array $old, array $new): void
    {
        $newKeys = array_map(fn($l) => $l['key'], $new['labels']);
        $map = [];
        foreach ($old['labels'] as $l) {
            if (in_array($l['key'], $newKeys, true)) continue;
            // Same label text under a new key (legacy label maps become keyed lists).
            foreach ($new['labels'] as $n) if (strcasecmp($n['label'], $l['label']) === 0) { $map[$l['key']] = $n['key']; continue 2; }
            $map[$l['key']] = null;
        }
        if (!$map) return;
        $up = $this->db->prepare("UPDATE board_item_values SET value = ? WHERE column_id = ? AND value = ?");
        $del = $this->db->prepare("DELETE FROM board_item_values WHERE column_id = ? AND value = ?");
        foreach ($map as $from => $to) {
            if ($to === null) $del->execute([(int)$col['column_id'], $from]);
            else $up->execute([$to, (int)$col['column_id'], $from]);
        }
    }

    private function renameInFormulas(int $boardId, string $from, string $to): void
    {
        foreach ($this->columnRows($boardId) as $c) {
            if ($c['type'] !== 'formula') continue;
            $cfg = Columns::config($c);
            $f = $cfg['formula'];
            $nf = preg_replace_callback('/\{([^{}]+)\}|\[([^\[\]#][^\[\]]*)\]/', function ($m) use ($from, $to) {
                $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                $suffix = '';
                if (preg_match('/^(.*)\.(start|end)$/i', trim($name), $mm)) { $name = $mm[1]; $suffix = '.' . strtolower($mm[2]); }
                if (Formula::nameKey($name) !== Formula::nameKey($from)) return $m[0];
                return '{' . $to . $suffix . '}';
            }, $f);
            if ($nf !== $f) {
                $cfg['formula'] = $nf;
                $this->db->prepare("UPDATE board_columns SET config = ? WHERE column_id = ?")->execute([Columns::encodeConfig('formula', $cfg), (int)$c['column_id']]);
            }
        }
    }

    public function reorderColumns(int $boardId, array $ids): void
    {
        $this->repo->requireBoard($boardId, 2);
        $st = $this->db->prepare("UPDATE board_columns SET position = ? WHERE column_id = ? AND board_id = ? AND company_id = ?");
        foreach (array_values(array_map('intval', $ids)) as $i => $id) $st->execute([$i, $id, $boardId, $this->repo->companyId]);
        self::refreshMirrors($this->db, $this->repo->companyId, $boardId);
    }

    public function deleteColumn(int $columnId): void
    {
        $c = $this->columnRow($columnId);
        $boardId = (int)$c['board_id'];
        $this->repo->requireBoard($boardId, 2);
        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM board_item_values WHERE column_id = ?")->execute([$columnId]);
            $this->db->prepare("UPDATE board_item_attachments SET column_id = NULL WHERE column_id = ?")->execute([$columnId]);
            $this->db->prepare("DELETE FROM board_columns WHERE column_id = ? AND company_id = ?")->execute([$columnId, $this->repo->companyId]);
            Activity::log($this->db, $this->repo->companyId, $this->repo->userId, $boardId, null, 'column_deleted', ['column_id' => $columnId, 'name' => $c['name'], 'type' => $c['type']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->recomputeFormulas($boardId);
        self::refreshMirrors($this->db, $this->repo->companyId, $boardId);
    }

    public function duplicateColumn(int $columnId): array
    {
        $c = $this->columnRow($columnId);
        $boardId = (int)$c['board_id'];
        $new = $this->createColumn($boardId, ['type' => $c['type'], 'name' => $c['name'] . ' copy', 'after' => $columnId,
                                              'config' => Columns::config($c), 'description' => $c['description'] ?? '']);
        $this->db->prepare("INSERT INTO board_item_values (item_id, column_id, value) SELECT item_id, ?, value FROM board_item_values WHERE column_id = ?")->execute([$new['id'], $columnId]);
        return $new;
    }

    // ================================================================= formulas

    /**
     * Recompute and store formula cells for a board (or some of its items).
     * @return array<int, array<int, float|string|null>> item_id => [column_id => value]
     */
    public function recomputeFormulas(int $boardId, ?array $itemIds = null): array
    {
        return self::computeFormulas($this->db, $this->repo->companyId, $boardId, $itemIds);
    }

    public static function computeFormulas(PDO $db, int $companyId, int $boardId, ?array $itemIds = null): array
    {
        $st = $db->prepare("SELECT * FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position, column_id");
        $st->execute([$boardId, $companyId]);
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        $formulas = array_values(array_filter($cols, fn($c) => $c['type'] === 'formula'));
        if (!$formulas) return [];
        $q = "SELECT id, created_at, updated_at FROM board_items WHERE board_id = ? AND company_id = ?";
        $args = [$boardId, $companyId];
        if ($itemIds !== null) {
            $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
            if (!$itemIds) return [];
            $q .= ' AND id IN (' . implode(',', $itemIds) . ')';
        }
        $st = $db->prepare($q);
        $st->execute($args);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) return [];
        $in = implode(',', array_map(fn($r) => (int)$r['id'], $items));
        $vals = [];
        foreach ($db->query("SELECT item_id, column_id, value FROM board_item_values WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $vals[(int)$v['item_id']][(int)$v['column_id']] = $v['value'];
        }
        $byKey = [];
        $byId = [];
        foreach ($cols as $c) {
            $byId[(int)$c['column_id']] = $c;
            $byKey[$c['name']] = $byKey[$c['name']] ?? $c;
        }
        $loose = [];
        foreach ($cols as $c) $loose[Formula::nameKey($c['name'])] = $loose[Formula::nameKey($c['name'])] ?? $c;

        $upsert = $db->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $delete = $db->prepare("DELETE FROM board_item_values WHERE item_id = ? AND column_id = ?");
        $out = [];
        foreach ($items as $it) {
            $iid = (int)$it['id'];
            $raw = $vals[$iid] ?? [];
            $resolve = function (string $ref) use (&$raw, $byId, $byKey, $loose, $it) {
                $part = null;
                if ($ref !== '' && $ref[0] === '#') {
                    $c = $byId[(int)substr($ref, 1)] ?? null;
                } else {
                    $c = $byKey[$ref] ?? $loose[Formula::nameKey($ref)] ?? null;
                    if (!$c && preg_match('/^(.*)\.(start|end)$/i', trim($ref), $m)) {
                        $c = $byKey[trim($m[1])] ?? $loose[Formula::nameKey($m[1])] ?? null;
                        $part = strtolower($m[2]);
                    }
                }
                if (!$c) return null;
                return self::formulaValue($c, $raw[(int)$c['column_id']] ?? null, $part, $it);
            };
            // Formulas can use other formulas: evaluate in column order, twice, so later ones settle.
            for ($pass = 0; $pass < (count($formulas) > 1 ? 2 : 1); $pass++) {
                foreach ($formulas as $f) {
                    $cfg = Columns::config($f);
                    $res = Formula::evaluate($cfg['formula'], $resolve);
                    $raw[(int)$f['column_id']] = $res === null ? null : Formula::format($res, $cfg['precision']);
                }
            }
            foreach ($formulas as $f) {
                $fid = (int)$f['column_id'];
                $new = $raw[$fid] ?? null;
                $old = $vals[$iid][$fid] ?? null;
                if ($new === null || $new === '') { if ($old !== null) $delete->execute([$iid, $fid]); }
                elseif ((string)$old !== $new) $upsert->execute([$iid, $fid, $new]);
                $out[$iid][$fid] = $new === null || $new === '' ? null : (is_numeric($new) ? (float)$new : $new);
            }
        }
        return $out;
    }

    /** A cell's value as a formula sees it. */
    private static function formulaValue(array $c, $raw, ?string $part, array $item)
    {
        switch ($c['type']) {
            case 'created': return Formula::dayNumber($item['created_at'] ?? null);
            case 'updated': return Formula::dayNumber($item['updated_at'] ?? null);
        }
        if ($raw === null || $raw === '') return null;
        $v = Columns::decode($c, $raw);
        switch ($c['type']) {
            case 'number': case 'formula': case 'progress': case 'rating':
                return is_float($v) ? $v : (is_numeric($v) ? (float)$v : (string)$v);
            case 'date':
                return Formula::dayNumber($v);
            case 'timeline':
                if (!$v) return null;
                $s = Formula::dayNumber($v['start']);
                $e = Formula::dayNumber($v['end']);
                if ($part === 'start') return $s;
                if ($part === 'end') return $e;
                return $e - $s + 1;
            case 'checkbox':
                return $v ? 1.0 : 0.0;
        }
        return Columns::text($c, $raw);
    }

    // ================================================================= mirrors

    /**
     * Recompute the items' own fields (status_label, assigned_to, dates …) and
     * board_item_people from the cells, for a board or some of its items.
     */
    public static function refreshMirrors(PDO $db, int $companyId, int $boardId, ?array $itemIds = null): void
    {
        $st = $db->prepare("SELECT * FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position, column_id");
        $st->execute([$boardId, $companyId]);
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        $q = "SELECT id, status_label, assigned_to, priority, progress, start_date, end_date, due_date FROM board_items WHERE board_id = ? AND company_id = ?";
        if ($itemIds !== null) {
            $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
            if (!$itemIds) return;
            $q .= ' AND id IN (' . implode(',', $itemIds) . ')';
        }
        $st = $db->prepare($q);
        $st->execute([$boardId, $companyId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) return;
        $in = implode(',', array_map(fn($r) => (int)$r['id'], $items));
        $vals = [];
        foreach ($db->query("SELECT item_id, column_id, value FROM board_item_values WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $vals[(int)$v['item_id']][(int)$v['column_id']] = $v['value'];
        }
        $subs = [];
        foreach ($db->query("SELECT parent_item_id, COUNT(*) n, SUM(completed = 1) d FROM board_subitems WHERE parent_item_id IN ($in) GROUP BY parent_item_id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $subs[(int)$s['parent_item_id']] = (int)$s['n'] ? (int)$s['d'] * 100 / (int)$s['n'] : null;
        }
        $peopleCols = array_values(array_filter($cols, fn($c) => $c['type'] === 'people'));
        $fields = ['status_label', 'assigned_to', 'priority', 'progress', 'start_date', 'end_date', 'due_date'];
        $upd = $db->prepare("UPDATE board_items SET " . implode(', ', array_map(fn($f) => "`$f` = ?", $fields)) . ", updated_at = updated_at WHERE id = ?");
        $db->exec("DELETE FROM board_item_people WHERE item_id IN ($in)");
        $ins = $db->prepare("INSERT IGNORE INTO board_item_people (item_id, user_id, company_id, board_id) VALUES (?, ?, ?, ?)");
        foreach ($items as $it) {
            $iid = (int)$it['id'];
            $m = Columns::mirrors($cols, $vals[$iid] ?? [], $subs[$iid] ?? null);
            $changed = false;
            foreach ($fields as $f) if ((string)($it[$f] ?? '') !== (string)($m[$f] ?? '')) { $changed = true; break; }
            if ($changed) $upd->execute(array_merge(array_map(fn($f) => $m[$f], $fields), [$iid]));
            foreach ($peopleCols as $pc) {
                foreach ((array)Columns::decode($pc, $vals[$iid][(int)$pc['column_id']] ?? null) as $uid) $ins->execute([$iid, (int)$uid, $companyId, $boardId]);
            }
        }
    }

    /**
     * Before the new boards, an empty status / person / date cell showed the
     * item's own status_label / assigned_to / due_date (kanban and bulk edits
     * wrote only those). Copy them into the board's first column of each kind
     * so nothing that people could see is lost. Runs once (schema backfill).
     */
    public static function migrateLegacyFields(PDO $db, int $companyId, int $boardId): void
    {
        $st = $db->prepare("SELECT * FROM board_columns WHERE board_id = ? AND company_id = ? ORDER BY position, column_id");
        $st->execute([$boardId, $companyId]);
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        $first = [];
        foreach ($cols as $c) $first[$c['type']] = $first[$c['type']] ?? $c;
        $date = Columns::primaryDateColumn($cols);
        // Status cells that hold a built-in key or a label's text in another case
        // ('working' on a column labelled "Working on it") get the label's own key.
        foreach ($cols as $c) {
            if ($c['type'] !== 'status') continue;
            $cfg = Columns::config($c);
            // BINARY: the column's collation ignores case, which would merge 'done' and 'Done'.
            $st = $db->prepare("SELECT DISTINCT BINARY value FROM board_item_values WHERE column_id = ? AND value IS NOT NULL AND value <> ''");
            $st->execute([(int)$c['column_id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $v = (string)$v;
                $l = Columns::statusOf($cfg, $v);
                if ($l && $l['key'] !== $v) {
                    $db->prepare("UPDATE board_item_values SET value = ? WHERE column_id = ? AND BINARY value = ?")->execute([$l['key'], (int)$c['column_id'], $v]);
                }
            }
        }
        $st = $db->prepare("SELECT id, status_label, assigned_to, due_date, priority FROM board_items
                             WHERE board_id = ? AND company_id = ? AND (status_label IS NOT NULL OR assigned_to IS NOT NULL OR due_date IS NOT NULL OR priority IN ('low','high','urgent'))");
        $st->execute([$boardId, $companyId]);
        $ins = $db->prepare("INSERT IGNORE INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $iid = (int)$it['id'];
            if (!empty($first['status']) && ($it['status_label'] ?? '') !== '') {
                $l = Columns::statusOf(Columns::config($first['status']), (string)$it['status_label']);
                if ($l) $ins->execute([$iid, (int)$first['status']['column_id'], $l['key']]);
            }
            if (!empty($first['people']) && (int)$it['assigned_to'] > 0) $ins->execute([$iid, (int)$first['people']['column_id'], (string)(int)$it['assigned_to']]);
            if ($date && ($d = Columns::date($it['due_date']))) $ins->execute([$iid, (int)$date['column_id'], $d]);
            if (!empty($first['priority']) && in_array($it['priority'], ['low', 'high', 'urgent'], true)) $ins->execute([$iid, (int)$first['priority']['column_id'], $it['priority']]);
        }
    }

    // ================================================================= saved views

    public function savedViews(int $boardId): array
    {
        $st = $this->db->prepare("SELECT * FROM board_saved_views WHERE board_id = ? AND (user_id = ? OR is_shared = 1) ORDER BY name");
        $st->execute([$boardId, $this->repo->userId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $cfg = json_decode((string)($v['config'] ?? ''), true);
            if (!is_array($cfg)) {
                // Older saved views: filters / visible_columns only.
                $cfg = ['filters' => [], 'hidden' => []];
                $vis = json_decode((string)$v['visible_columns'], true);
                if (is_array($vis) && $vis) $cfg['visible'] = array_map('intval', $vis);
            }
            $out[] = ['id' => (int)$v['id'], 'name' => $v['name'], 'view' => in_array($v['view_type'], self::VIEWS, true) ? $v['view_type'] : 'table',
                      'shared' => (bool)$v['is_shared'], 'mine' => (int)$v['user_id'] === $this->repo->userId, 'config' => $cfg];
        }
        return $out;
    }

    public function saveView(int $boardId, array $in): array
    {
        $b = $this->repo->requireBoard($boardId, 1);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
        if ($name === '') throw new ProjectUserError('Give the view a name.');
        $view = in_array($in['view'] ?? '', self::VIEWS, true) ? $in['view'] : 'table';
        $cfg = is_array($in['config'] ?? null) ? $in['config'] : [];
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 30000) throw new ProjectUserError('This view has too many settings to save.');
        $shared = !empty($in['shared']) && $b['level'] >= 2 ? 1 : 0;
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            $st = $this->db->prepare("SELECT user_id FROM board_saved_views WHERE id = ? AND board_id = ?");
            $st->execute([$id, $boardId]);
            $owner = $st->fetchColumn();
            if ($owner === false) throw new ProjectUserError('View not found.');
            if ((int)$owner !== $this->repo->userId && $b['level'] < 3) throw new ProjectForbidden('Only its creator or a board manager can change this view.');
            $this->db->prepare("UPDATE board_saved_views SET name = ?, view_type = ?, config = ?, is_shared = ? WHERE id = ?")->execute([$name, $view, $json, $shared, $id]);
        } else {
            $this->db->prepare("INSERT INTO board_saved_views (board_id, user_id, name, view_type, filters, sorts, visible_columns, is_shared, config, created_at)
                                VALUES (?, ?, ?, ?, '[]', '[]', '[]', ?, ?, NOW())")->execute([$boardId, $this->repo->userId, $name, $view, $shared, $json]);
            $id = (int)$this->db->lastInsertId();
        }
        foreach ($this->savedViews($boardId) as $v) if ($v['id'] === $id) return $v;
        return [];
    }

    public function deleteView(int $boardId, int $viewId): void
    {
        $b = $this->repo->requireBoard($boardId, 1);
        $st = $this->db->prepare("SELECT user_id FROM board_saved_views WHERE id = ? AND board_id = ?");
        $st->execute([$viewId, $boardId]);
        $owner = $st->fetchColumn();
        if ($owner === false) throw new ProjectUserError('View not found.');
        if ((int)$owner !== $this->repo->userId && $b['level'] < 3) throw new ProjectForbidden('Only its creator or a board manager can delete this view.');
        $this->db->prepare("DELETE FROM board_saved_views WHERE id = ?")->execute([$viewId]);
    }
}
