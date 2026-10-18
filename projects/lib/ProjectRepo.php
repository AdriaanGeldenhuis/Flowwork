<?php
// projects/lib/ProjectRepo.php
//
// Who may do what, and the project-level operations (list, detail, create,
// update, members, stars, recent). One instance per request and user.
//
// Access levels (highest wins):
//   5 admin    company admins: everything in the company
//   4 owner    project owner / creator: + delete the project
//   3 manager  project manager: + settings, members, boards, automations
//   2 member   edit items, groups and columns, comment, upload
//   1 viewer   read and comment
//   0          no access
// A project member's role comes from project_members (can_edit = 0 → viewer);
// the project's owner_id / created_by count as owner, project_manager_id as
// manager. Projects shared with the whole company (visibility 'company') give
// everyone at least viewer. board_members can open one board (and see its
// project) without being on the project. Company viewers, POS users and
// bookkeepers never get more than viewer.

final class ProjectRepo
{
    public const LEVELS = ['viewer' => 1, 'member' => 2, 'editor' => 2, 'manager' => 3, 'owner' => 4, 'admin' => 5];
    public const ROLE_NAMES = [1 => 'viewer', 2 => 'member', 3 => 'manager', 4 => 'owner', 5 => 'admin'];
    public const STATUSES = ['draft' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'archived' => 'Archived'];
    public const COLORS = ['#7a1f30', '#b08d57', '#2f7457', '#2f5c84', '#7a5ab8', '#b4577a', '#c08a2b', '#1f7a7a', '#a8281e', '#4b4fa8', '#6b7d2b', '#5b6670'];

    private array $projectCache = [];
    private array $boardCache = [];
    private ?array $names = null;

    public function __construct(public readonly PDO $db, public readonly int $companyId, public readonly int $userId, public readonly string $role)
    {
    }

    /** A repo for another user of the company (their company role looked up). */
    public static function forUser(PDO $db, int $companyId, int $userId): self
    {
        $st = $db->prepare("SELECT role FROM user_companies WHERE user_id = ? AND company_id = ?");
        $st->execute([$userId, $companyId]);
        $role = $st->fetchColumn();
        if ($role === false) {
            $st = $db->prepare("SELECT role FROM users WHERE id = ? AND company_id = ? AND status = 'active'");
            $st->execute([$userId, $companyId]);
            $role = $st->fetchColumn();
        }
        return new self($db, $companyId, $userId, $role === false ? 'none' : (string)$role);
    }

    public function isAdmin(): bool { return $this->role === 'admin'; }

    /** Company roles that only ever read projects. */
    private function capLevel(): int
    {
        if ($this->role === 'none') return 0; // not in this company
        return in_array($this->role, ['viewer', 'pos', 'bookkeeper'], true) ? 1 : 5;
    }

    // ================================================================= access

    /** The project row (company-scoped) or null. */
    public function projectRow(int $projectId): ?array
    {
        if ($projectId <= 0) return null;
        if (!array_key_exists($projectId, $this->projectCache)) {
            $st = $this->db->prepare("SELECT * FROM projects WHERE project_id = ? AND company_id = ?");
            $st->execute([$projectId, $this->companyId]);
            $this->projectCache[$projectId] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $this->projectCache[$projectId];
    }

    public function forgetProject(int $projectId): void { unset($this->projectCache[$projectId]); }

    /** Access level on a project (0 = none). */
    public function projectLevel(int $projectId): int
    {
        $p = $this->projectRow($projectId);
        if (!$p) return 0;
        if ($this->isAdmin()) return 5;
        $level = 0;
        if (in_array($this->userId, [(int)$p['owner_id'], (int)$p['created_by']], true)) $level = 4;
        elseif ((int)$p['project_manager_id'] === $this->userId) $level = 3;
        $st = $this->db->prepare("SELECT role, can_edit FROM project_members WHERE project_id = ? AND user_id = ? AND company_id = ?");
        $st->execute([$projectId, $this->userId, $this->companyId]);
        if ($m = $st->fetch(PDO::FETCH_ASSOC)) {
            $l = self::LEVELS[$m['role']] ?? 2;
            if ($l >= 2 && isset($m['can_edit']) && !(int)$m['can_edit']) $l = 1;
            $level = max($level, $l);
        }
        if ($level === 0 && ($p['visibility'] ?? 'members') === 'company') $level = 1;
        return min($level, $this->capLevel());
    }

    /** The board row (with project_id, project name) or null. */
    public function boardRow(int $boardId): ?array
    {
        if ($boardId <= 0) return null;
        if (!array_key_exists($boardId, $this->boardCache)) {
            $st = $this->db->prepare("SELECT b.*, p.name AS project_name, p.color AS project_color, p.status AS project_status
                                        FROM project_boards b JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                                       WHERE b.board_id = ? AND b.company_id = ?");
            $st->execute([$boardId, $this->companyId]);
            $this->boardCache[$boardId] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $this->boardCache[$boardId];
    }

    public function forgetBoard(int $boardId): void { unset($this->boardCache[$boardId]); }

    /** Access level on a board: the project level, raised by a board_members row. */
    public function boardLevel(int $boardId): int
    {
        $b = $this->boardRow($boardId);
        if (!$b) return 0;
        $level = $this->projectLevel((int)$b['project_id']);
        if ($level < 5) {
            try {
                $st = $this->db->prepare("SELECT role FROM board_members WHERE board_id = ? AND user_id = ?");
                $st->execute([$boardId, $this->userId]);
                if ($r = $st->fetchColumn()) $level = max($level, min(self::LEVELS[$r] ?? 1, 3));
            } catch (Throwable $e) { /* board_members missing: project access only */ }
        }
        return min($level, $this->capLevel());
    }

    /** @return array the project row; throws when the user lacks $min */
    public function requireProject(int $projectId, int $min = 1): array
    {
        $p = $this->projectRow($projectId);
        $level = $p ? $this->projectLevel($projectId) : 0;
        if (!$p || ($level === 0 && !$this->hasBoardOnlyAccess($projectId))) throw new ProjectUserError('Project not found.');
        if ($level < $min) throw new ProjectForbidden($min >= 3 ? 'Only the project’s managers can do that.' : 'You can view this project but not change it.');
        return $p + ['level' => $level];
    }

    /** @return array the board row; throws when the user lacks $min */
    public function requireBoard(int $boardId, int $min = 1): array
    {
        $b = $this->boardRow($boardId);
        $level = $b ? $this->boardLevel($boardId) : 0;
        if (!$b || $level === 0) throw new ProjectUserError('Board not found.');
        if ($level < $min) throw new ProjectForbidden($min >= 3 ? 'Only the board’s managers can do that.' : 'You can view this board but not change it.');
        return $b + ['level' => $level];
    }

    /** The item with its board, after an access check. */
    public function requireItem(int $itemId, int $min = 1): array
    {
        $st = $this->db->prepare("SELECT * FROM board_items WHERE id = ? AND company_id = ?");
        $st->execute([$itemId, $this->companyId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) throw new ProjectUserError('Item not found.');
        $b = $this->requireBoard((int)$it['board_id'], $min);
        return $it + ['_board' => $b];
    }

    private function hasBoardOnlyAccess(int $projectId): bool
    {
        try {
            $st = $this->db->prepare("SELECT 1 FROM board_members bm JOIN project_boards b ON b.board_id = bm.board_id
                                       WHERE bm.user_id = ? AND b.project_id = ? AND b.company_id = ? LIMIT 1");
            $st->execute([$this->userId, $projectId, $this->companyId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Projects this user can open; null means every project (admins). @return array<int,true>|null */
    public function visibleProjectIds(): ?array
    {
        static $memo = [];
        $key = $this->companyId . ':' . $this->userId;
        if ($this->isAdmin()) return null;
        if (isset($memo[$key])) return $memo[$key];
        $ids = [];
        $st = $this->db->prepare("SELECT project_id FROM project_members WHERE user_id = ? AND company_id = ?
                                  UNION SELECT project_id FROM projects WHERE company_id = ?
                                     AND (owner_id = ? OR created_by = ? OR project_manager_id = ? OR visibility = 'company')");
        $st->execute([$this->userId, $this->companyId, $this->companyId, $this->userId, $this->userId, $this->userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        try {
            $st = $this->db->prepare("SELECT DISTINCT b.project_id FROM board_members bm JOIN project_boards b ON b.board_id = bm.board_id
                                       WHERE bm.user_id = ? AND b.company_id = ?");
            $st->execute([$this->userId, $this->companyId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        } catch (Throwable $e) { /* no board_members table */ }
        return $memo[$key] = $ids;
    }

    /** SQL fragment limiting `$alias.project_id` to visible projects (and its params). */
    public function visibleSql(string $alias = 'p'): array
    {
        $ids = $this->visibleProjectIds();
        if ($ids === null) return ['1=1', []];
        if (!$ids) return ['1=0', []];
        return [$alias . '.project_id IN (' . implode(',', array_map('intval', array_keys($ids))) . ')', []];
    }

    public function canCreateProjects(): bool
    {
        return $this->capLevel() > 1;
    }

    // ================================================================= people

    /** Active people in the company: [id => ['id', 'name', 'email', 'role']]. */
    public function people(): array
    {
        if ($this->names !== null) return $this->names;
        $st = $this->db->prepare("SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.avatar_path
                                    FROM users u LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
                                   WHERE (u.company_id = ? OR uc.company_id = ?) AND u.status = 'active'
                                ORDER BY u.first_name, u.last_name");
        $st->execute([$this->companyId, $this->companyId, $this->companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $out[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'], 'email' => $u['email']];
        }
        return $this->names = $out;
    }

    public function personName(?int $id): string
    {
        if (!$id) return '';
        return $this->people()[$id]['name'] ?? 'Former member';
    }

    // ================================================================= list

    /**
     * Projects for the portfolio, newest activity first, with live stats.
     * @param array $f ['status' => 'active'|'…'|'open'|'all', 'q' => text, 'mine' => bool, 'archived' => bool]
     */
    public function listProjects(array $f = []): array
    {
        [$vis] = $this->visibleSql('p');
        $where = ["p.company_id = ?", $vis];
        $args = [$this->companyId];
        $status = (string)($f['status'] ?? 'open');
        if (!empty($f['archived'])) $where[] = "(p.archived = 1 OR p.status = 'archived')";
        else {
            $where[] = "(p.archived = 0 OR p.archived IS NULL) AND p.status <> 'archived'";
            if ($status === 'open') $where[] = "p.status IN ('draft','active','on_hold')";
            elseif (isset(self::STATUSES[$status])) { $where[] = "p.status = ?"; $args[] = $status; }
        }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(p.name LIKE ? OR p.project_number LIKE ? OR p.client LIKE ? OR a.name LIKE ? OR p.site_address LIKE ?)";
            array_push($args, $like, $like, $like, $like, $like);
        }
        if (!empty($f['mine'])) {
            $where[] = "(p.owner_id = ? OR p.created_by = ? OR p.project_manager_id = ? OR EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.project_id AND m.user_id = ?))";
            array_push($args, $this->userId, $this->userId, $this->userId, $this->userId);
        }
        $sql = "SELECT p.*, a.name AS client_name,
                       s.starred, s.last_opened_at
                  FROM projects p
             LEFT JOIN crm_accounts a ON a.id = p.client_id AND a.company_id = p.company_id
             LEFT JOIN project_user_state s ON s.user_id = ? AND s.kind = 'project' AND s.ref_id = p.project_id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY COALESCE(s.starred, 0) DESC, COALESCE(p.updated_at, p.created_at) DESC
                 LIMIT 500";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge([$this->userId], $args));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $stats = $this->projectStats(array_map(fn($r) => (int)$r['project_id'], $rows));
        return array_map(fn($r) => $this->projectSummary($r, $stats[(int)$r['project_id']] ?? []), $rows);
    }

    /** Item counts per project: total, done, overdue, due this week, mine open; boards; members. */
    public function projectStats(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $today = self::today();
        $week = date('Y-m-d', strtotime($today . ' +7 days'));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['boards' => 0, 'items' => 0, 'done' => 0, 'overdue' => 0, 'week' => 0, 'mine' => 0, 'members' => []];
        $st = $this->db->prepare("SELECT b.project_id, COUNT(DISTINCT b.board_id) AS boards
                                    FROM project_boards b
                                   WHERE b.company_id = ? AND b.project_id IN ($in) AND (b.archived = 0 OR b.archived IS NULL)
                                GROUP BY b.project_id");
        $st->execute([$this->companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['project_id']]['boards'] = (int)$r['boards'];
        $st = $this->db->prepare("SELECT b.project_id, COUNT(*) AS items,
                                         SUM(i.progress >= 100) AS done,
                                         SUM(i.progress < 100 AND i.due_date IS NOT NULL AND i.due_date < ?) AS overdue,
                                         SUM(i.progress < 100 AND i.due_date BETWEEN ? AND ?) AS week,
                                         SUM(i.progress < 100 AND EXISTS (SELECT 1 FROM board_item_people ip WHERE ip.item_id = i.id AND ip.user_id = ?)) AS mine
                                    FROM board_items i JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                   WHERE i.company_id = ? AND b.project_id IN ($in) AND (i.archived = 0 OR i.archived IS NULL)
                                     AND (b.archived = 0 OR b.archived IS NULL)
                                GROUP BY b.project_id");
        $st->execute([$today, $today, $week, $this->userId, $this->companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['project_id'];
            foreach (['items', 'done', 'overdue', 'week', 'mine'] as $k) $out[$id][$k] = (int)$r[$k];
        }
        $st = $this->db->prepare("SELECT project_id, user_id, role FROM project_members WHERE company_id = ? AND project_id IN ($in) ORDER BY FIELD(role,'owner','manager','member','viewer'), added_at");
        $st->execute([$this->companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['project_id']]['members'][] = (int)$r['user_id'];
        return $out;
    }

    /** What the browser gets for a project card / row. */
    public function projectSummary(array $p, array $s): array
    {
        $id = (int)$p['project_id'];
        $items = (int)($s['items'] ?? 0);
        $progress = $items ? (int)round(($s['done'] ?? 0) * 100 / $items) : (int)($p['progress_percent'] ?? 0);
        $members = $s['members'] ?? [];
        foreach ([(int)$p['project_manager_id'], (int)$p['owner_id']] as $u) if ($u && !in_array($u, $members, true)) array_unshift($members, $u);
        return [
            'id' => $id,
            'number' => (string)($p['project_number'] ?? ''),
            'name' => (string)$p['name'],
            'description' => (string)($p['description'] ?? ''),
            'color' => self::projectColor($p),
            'status' => (string)$p['status'],
            'visibility' => (string)($p['visibility'] ?? 'members'),
            'client' => (string)($p['client_name'] ?? '') ?: (string)($p['client'] ?? ''),
            'client_id' => $p['client_id'] ? (int)$p['client_id'] : null,
            'site' => (string)($p['site_address'] ?? ''),
            'start' => Columns::date($p['start_date'] ?? null),
            'end' => Columns::date($p['end_date'] ?? null),
            'budget' => (float)($p['budget'] ?? 0),
            'manager_id' => $p['project_manager_id'] ? (int)$p['project_manager_id'] : null,
            'owner_id' => $p['owner_id'] ? (int)$p['owner_id'] : ((int)$p['created_by'] ?: null),
            'members' => array_slice(array_values(array_unique($members)), 0, 12),
            'archived' => (bool)($p['archived'] ?? false) || $p['status'] === 'archived',
            'starred' => !empty($p['starred']),
            'last_opened' => $p['last_opened_at'] ?? null,
            'updated_at' => $p['updated_at'] ?? $p['created_at'],
            'stats' => ['boards' => (int)($s['boards'] ?? 0), 'items' => $items, 'done' => (int)($s['done'] ?? 0),
                        'overdue' => (int)($s['overdue'] ?? 0), 'week' => (int)($s['week'] ?? 0), 'mine' => (int)($s['mine'] ?? 0)],
            'progress' => $progress,
            'url' => '/projects/view.php?project_id=' . $id,
        ];
    }

    public static function projectColor(array $p): string
    {
        $c = (string)($p['color'] ?? '');
        return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : self::COLORS[(int)$p['project_id'] % count(self::COLORS)];
    }

    /** The company's "today" (calendar time zone when set). */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    // ================================================================= detail

    /** Boards of a project the user can open, in order. */
    public function boardsOf(int $projectId, bool $withArchived = false): array
    {
        $st = $this->db->prepare("SELECT b.*, s.starred,
                                         (SELECT COUNT(*) FROM board_items i WHERE i.board_id = b.board_id AND i.company_id = b.company_id AND (i.archived = 0 OR i.archived IS NULL)) AS items,
                                         (SELECT COUNT(*) FROM board_items i WHERE i.board_id = b.board_id AND i.company_id = b.company_id AND (i.archived = 0 OR i.archived IS NULL) AND i.progress >= 100) AS done
                                    FROM project_boards b
                               LEFT JOIN project_user_state s ON s.user_id = ? AND s.kind = 'board' AND s.ref_id = b.board_id
                                   WHERE b.project_id = ? AND b.company_id = ?" . ($withArchived ? '' : " AND (b.archived = 0 OR b.archived IS NULL)") . "
                                ORDER BY b.archived, b.sort_order, b.board_id");
        $st->execute([$this->userId, $projectId, $this->companyId]);
        $out = [];
        $projectLevel = $this->projectLevel($projectId);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $level = $projectLevel >= 1 ? $projectLevel : $this->boardLevel((int)$b['board_id']);
            if ($level < 1) continue;
            $out[] = self::boardSummary($b) + ['level' => $level];
        }
        return $out;
    }

    /**
     * Per board: how its items split over the first status column's labels
     * (the "battery"), and how many are overdue. @return array<int, array>
     */
    public function boardBreakdown(int $projectId): array
    {
        $st = $this->db->prepare("SELECT b.board_id FROM project_boards b WHERE b.project_id = ? AND b.company_id = ? AND (b.archived = 0 OR b.archived IS NULL)");
        $st->execute([$projectId, $this->companyId]);
        $boards = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$boards) return [];
        $in = implode(',', $boards);
        $first = [];
        foreach ($this->db->query("SELECT * FROM board_columns WHERE company_id = " . (int)$this->companyId . " AND board_id IN ($in) AND type = 'status' ORDER BY position, column_id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $first[(int)$c['board_id']] = $first[(int)$c['board_id']] ?? $c;
        }
        $out = [];
        foreach ($boards as $bid) $out[$bid] = ['labels' => [], 'empty' => 0, 'overdue' => 0];
        $counts = [];
        if ($first) {
            $cols = implode(',', array_map(fn($c) => (int)$c['column_id'], $first));
            $st = $this->db->query("SELECT i.board_id, v.value, COUNT(*) n FROM board_items i
                                      LEFT JOIN board_item_values v ON v.item_id = i.id AND v.column_id IN ($cols)
                                     WHERE i.board_id IN ($in) AND i.company_id = " . (int)$this->companyId . " AND (i.archived = 0 OR i.archived IS NULL)
                                  GROUP BY i.board_id, v.value");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int)$r['board_id']][(string)$r['value']] = (int)$r['n'];
        }
        foreach ($first as $bid => $c) {
            $cfg = Columns::config($c);
            foreach ($cfg['labels'] as $l) {
                $n = $counts[$bid][$l['key']] ?? 0;
                if ($n) $out[$bid]['labels'][] = ['label' => $l['label'], 'color' => $l['color'], 'n' => $n, 'done' => !empty($l['done'])];
            }
            $out[$bid]['empty'] = $counts[$bid][''] ?? 0;
        }
        $today = self::today();
        foreach ($this->db->query("SELECT board_id, COUNT(*) n FROM board_items WHERE board_id IN ($in) AND company_id = " . (int)$this->companyId . "
                                    AND (archived = 0 OR archived IS NULL) AND progress < 100 AND due_date < " . $this->db->quote($today) . " GROUP BY board_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['board_id']]['overdue'] = (int)$r['n'];
        }
        return $out;
    }

    /** Open items on the project that are overdue or due in the next two weeks. */
    public function dueSoon(int $projectId, int $limit = 12): array
    {
        $until = date('Y-m-d', strtotime(self::today() . ' +14 days'));
        $st = $this->db->prepare("SELECT i.id, i.title, i.board_id, i.due_date, i.status_label, b.title AS board,
                                         (SELECT GROUP_CONCAT(ip.user_id) FROM board_item_people ip WHERE ip.item_id = i.id) AS people
                                    FROM board_items i JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                   WHERE b.project_id = ? AND i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL) AND (b.archived = 0 OR b.archived IS NULL)
                                     AND i.progress < 100 AND i.due_date IS NOT NULL AND i.due_date <= ?
                                ORDER BY i.due_date, i.id LIMIT " . max(1, min(100, $limit)));
        $st->execute([$projectId, $this->companyId, $until]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($this->boardLevel((int)$r['board_id']) < 1) continue;
            $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'board_id' => (int)$r['board_id'], 'board' => $r['board'], 'due' => Columns::date($r['due_date']),
                      'status' => $r['status_label'], 'people' => $r['people'] ? array_map('intval', explode(',', $r['people'])) : [],
                      'url' => ProjectLink::itemUrl((int)$r['board_id'], (int)$r['id'])];
        }
        return $out;
    }

    public static function boardSummary(array $b): array
    {
        return [
            'id' => (int)$b['board_id'],
            'project_id' => (int)$b['project_id'],
            'title' => (string)$b['title'],
            'description' => (string)($b['description'] ?? ''),
            'type' => (string)($b['board_type'] ?? '') ?: 'general',
            'default_view' => (string)($b['default_view'] ?? 'table') ?: 'table',
            'item_noun' => (string)($b['item_noun'] ?? '') ?: 'item',
            'archived' => (bool)($b['archived'] ?? false),
            'starred' => !empty($b['starred']),
            'items' => (int)($b['items'] ?? 0),
            'done' => (int)($b['done'] ?? 0),
            'url' => '/projects/board.php?board_id=' . (int)$b['board_id'],
        ];
    }

    /** Members of a project with their roles (including the implicit owner and manager). */
    public function members(int $projectId): array
    {
        $p = $this->projectRow($projectId);
        if (!$p) return [];
        $people = $this->people();
        $st = $this->db->prepare("SELECT user_id, role, can_edit, added_at FROM project_members WHERE project_id = ? AND company_id = ? ORDER BY FIELD(role,'owner','manager','member','viewer'), added_at");
        $st->execute([$projectId, $this->companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $uid = (int)$m['user_id'];
            $role = (string)$m['role'] ?: 'member';
            if ($role !== 'viewer' && isset($m['can_edit']) && !(int)$m['can_edit']) $role = 'viewer';
            $out[$uid] = ['id' => $uid, 'name' => $people[$uid]['name'] ?? 'Former member', 'email' => $people[$uid]['email'] ?? '',
                          'role' => $role, 'active' => isset($people[$uid])];
        }
        foreach ([(int)$p['owner_id'] ?: (int)$p['created_by'] => 'owner', (int)$p['project_manager_id'] => 'manager'] as $uid => $role) {
            if (!$uid) continue;
            if (!isset($out[$uid])) {
                $out[$uid] = ['id' => $uid, 'name' => $people[$uid]['name'] ?? 'Former member', 'email' => $people[$uid]['email'] ?? '', 'role' => $role, 'active' => isset($people[$uid]), 'implicit' => true];
            } elseif ((self::LEVELS[$out[$uid]['role']] ?? 0) < self::LEVELS[$role]) {
                $out[$uid]['role'] = $role;
            }
        }
        uasort($out, fn($a, $b) => [-(self::LEVELS[$a['role']] ?? 0), $a['name']] <=> [-(self::LEVELS[$b['role']] ?? 0), $b['name']]);
        return array_values($out);
    }

    // ================================================================= writes

    /** Validated project fields from input. */
    private function projectFields(array $in, ?array $old = null): array
    {
        $f = [];
        if (array_key_exists('name', $in) || !$old) {
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 255);
            if ($name === '') throw new ProjectUserError('Give the project a name.');
            $f['name'] = $name;
        }
        foreach (['description' => 5000, 'site_address' => 1000, 'project_number' => 50] as $k => $max) {
            if (array_key_exists($k, $in)) $f[$k] = ($v = mb_substr(trim((string)$in[$k]), 0, $max)) === '' ? null : $v;
        }
        if (array_key_exists('client_id', $in)) {
            $cid = (int)$in['client_id'];
            if ($cid > 0) {
                $st = $this->db->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
                $st->execute([$cid, $this->companyId]);
                $name = $st->fetchColumn();
                if ($name === false) throw new ProjectUserError('That client was not found in the CRM.');
                $f['client_id'] = $cid;
                $f['client'] = mb_substr((string)$name, 0, 255);
            } else {
                $f['client_id'] = null;
                $f['client'] = ($c = mb_substr(trim((string)($in['client'] ?? '')), 0, 255)) === '' ? null : $c;
            }
        } elseif (array_key_exists('client', $in)) {
            $f['client'] = ($c = mb_substr(trim((string)$in['client']), 0, 255)) === '' ? null : $c;
        }
        foreach (['start_date', 'end_date'] as $k) {
            if (array_key_exists($k, $in)) {
                $v = trim((string)$in[$k]);
                $d = Columns::date($v);
                if ($v !== '' && $d === null) throw new ProjectUserError('Check the project dates.');
                $f[$k] = $d;
            }
        }
        $s = $f['start_date'] ?? ($old['start_date'] ?? null);
        $e = $f['end_date'] ?? ($old['end_date'] ?? null);
        if ($s && $e && $e < $s) throw new ProjectUserError('The end date is before the start date.');
        if (array_key_exists('budget', $in)) {
            $b = str_replace([' ', ',', 'R'], '', (string)$in['budget']);
            if ($b !== '' && (!is_numeric($b) || (float)$b < 0 || (float)$b > 1e12)) throw new ProjectUserError('The budget must be a positive amount.');
            $f['budget'] = $b === '' ? 0 : round((float)$b, 2);
        }
        if (array_key_exists('status', $in)) {
            $st = (string)$in['status'];
            if (!isset(self::STATUSES[$st])) throw new ProjectUserError('Unknown project status.');
            $f['status'] = $st;
            if ($st === 'archived') $f['archived'] = 1;
            elseif ($old && $old['status'] === 'archived') $f['archived'] = 0;
        }
        if (array_key_exists('visibility', $in)) $f['visibility'] = $in['visibility'] === 'company' ? 'company' : 'members';
        if (array_key_exists('color', $in)) $f['color'] = preg_match('/^#[0-9a-f]{6}$/i', (string)$in['color']) ? strtolower($in['color']) : null;
        if (array_key_exists('manager_id', $in)) {
            $mid = (int)$in['manager_id'];
            if ($mid > 0 && !isset($this->people()[$mid])) throw new ProjectUserError('Choose a manager from your company.');
            $f['project_manager_id'] = $mid > 0 ? $mid : null;
        }
        return $f;
    }

    /** Next "PRJ-0007" style number for the company. */
    private function nextNumber(): string
    {
        $st = $this->db->prepare("SELECT project_number FROM projects WHERE company_id = ? AND project_number REGEXP '^PRJ-[0-9]+$'");
        $st->execute([$this->companyId]);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $max = max($max, (int)substr((string)$n, 4));
        return sprintf('PRJ-%04d', $max + 1);
    }

    /**
     * Create a project with its first boards (from a template) and members.
     * @param array $in fields + template (key), members [{user_id, role}]
     */
    public function createProject(array $in): int
    {
        if (!$this->canCreateProjects()) throw new ProjectForbidden('Your role cannot create projects.');
        $f = $this->projectFields($in);
        $f += ['status' => 'active', 'visibility' => 'members'];
        if (empty($f['project_number'])) $f['project_number'] = $this->nextNumber();
        if (empty($f['color'])) $f['color'] = self::COLORS[random_int(0, count(self::COLORS) - 1)];
        $template = (string)($in['template'] ?? 'blank');
        $this->db->beginTransaction();
        try {
            $cols = array_keys($f);
            $this->db->prepare("INSERT INTO projects (company_id, " . implode(', ', $cols) . ", owner_id, created_by, created_at)
                                VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW())")
                ->execute(array_merge([$this->companyId], array_values($f), [$this->userId, $this->userId]));
            $pid = (int)$this->db->lastInsertId();
            $this->addMemberRow($pid, $this->userId, 'owner');
            if (!empty($f['project_manager_id']) && (int)$f['project_manager_id'] !== $this->userId) $this->addMemberRow($pid, (int)$f['project_manager_id'], 'manager');
            foreach ((array)($in['members'] ?? []) as $m) {
                $uid = (int)(is_array($m) ? ($m['user_id'] ?? 0) : $m);
                $role = is_array($m) && in_array($m['role'] ?? '', ['manager', 'member', 'viewer'], true) ? $m['role'] : 'member';
                if ($uid > 0 && isset($this->people()[$uid]) && $uid !== $this->userId) $this->addMemberRow($pid, $uid, $role, true);
            }
            $boards = new BoardRepo($this);
            foreach (Templates::projectBoards($template) as $i => $tplKey) {
                $boards->createBoard($pid, ['template' => $tplKey, 'sort_order' => $i], false);
            }
            Activity::log($this->db, $this->companyId, $this->userId, null, null, 'project_created', ['project_id' => $pid, 'name' => $f['name']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->touch('project', $pid);
        foreach ((array)($in['members'] ?? []) as $m) {
            $uid = (int)(is_array($m) ? ($m['user_id'] ?? 0) : $m);
            if ($uid > 0 && $uid !== $this->userId) ProjectNotify::addedToProject($this, $pid, $uid);
        }
        return $pid;
    }

    public function updateProject(int $projectId, array $in): void
    {
        $old = $this->requireProject($projectId, 3);
        $f = $this->projectFields($in, $old);
        if (isset($f['status']) && $f['status'] !== $old['status'] && !$this->canSetStatus($projectId)) throw new ProjectForbidden('Only the project’s managers can change its status.');
        if (!$f) return;
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        $this->db->prepare("UPDATE projects SET $set, updated_at = NOW() WHERE project_id = ? AND company_id = ?")
            ->execute(array_merge(array_values($f), [$projectId, $this->companyId]));
        if (!empty($f['project_manager_id']) && (int)$f['project_manager_id'] !== (int)$old['project_manager_id']) {
            $this->addMemberRow($projectId, (int)$f['project_manager_id'], 'manager', true);
            if ((int)$f['project_manager_id'] !== $this->userId) ProjectNotify::addedToProject($this, $projectId, (int)$f['project_manager_id'], 'manager');
        }
        $changed = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)($old[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed) Activity::log($this->db, $this->companyId, $this->userId, null, null, 'project_updated', ['project_id' => $projectId, 'fields' => $changed] + (isset($f['status']) ? ['status' => $f['status']] : []));
        $this->forgetProject($projectId);
    }

    private function canSetStatus(int $projectId): bool
    {
        return $this->projectLevel($projectId) >= 3;
    }

    /** Delete a project and everything on it (owners and admins). */
    public function deleteProject(int $projectId): array
    {
        $p = $this->requireProject($projectId, 4);
        $files = [];
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT board_id FROM project_boards WHERE project_id = ? AND company_id = ?");
            $st->execute([$projectId, $this->companyId]);
            $boards = new BoardRepo($this);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) $files = array_merge($files, $boards->purgeBoard((int)$bid));
            foreach (['project_members', 'cost_items', 'timesheets'] as $t) {
                try { $this->db->prepare("DELETE FROM `$t` WHERE project_id = ? AND company_id = ?")->execute([$projectId, $this->companyId]); }
                catch (Throwable $e) { error_log('[projects] delete ' . $t . ': ' . $e->getMessage()); }
            }
            $this->db->prepare("DELETE FROM project_user_state WHERE kind = 'project' AND ref_id = ? AND company_id = ?")->execute([$projectId, $this->companyId]);
            // Documents elsewhere keep existing; they just stop pointing at the project.
            foreach (['invoices', 'quotes'] as $t) {
                try { $this->db->prepare("UPDATE `$t` SET project_id = NULL WHERE project_id = ? AND company_id = ?")->execute([$projectId, $this->companyId]); }
                catch (Throwable $e) { /* column may not exist */ }
            }
            try {
                $this->db->prepare("DELETE l FROM calendar_event_links l JOIN calendar_events e ON e.id = l.event_id
                                     WHERE l.linked_type = 'project' AND l.linked_id = ? AND e.company_id = ?")->execute([$projectId, $this->companyId]);
            } catch (Throwable $e) { /* calendar not installed */ }
            $this->db->prepare("DELETE FROM projects WHERE project_id = ? AND company_id = ?")->execute([$projectId, $this->companyId]);
            Activity::log($this->db, $this->companyId, $this->userId, null, null, 'project_deleted', ['project_id' => $projectId, 'name' => $p['name']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->forgetProject($projectId);
        return $files;
    }

    /** Copy a project: its boards (structure, optionally items) and members. */
    public function duplicateProject(int $projectId, bool $withItems): int
    {
        $p = $this->requireProject($projectId, 2);
        if (!$this->canCreateProjects()) throw new ProjectForbidden('Your role cannot create projects.');
        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO projects (company_id, project_number, name, color, description, client, client_id, site_address, status, visibility,
                                                      start_date, end_date, budget, project_manager_id, owner_id, created_by, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$this->companyId, $this->nextNumber(), mb_substr('Copy of ' . $p['name'], 0, 255), $p['color'] ?? null, $p['description'],
                           $p['client'], $p['client_id'], $p['site_address'], $p['visibility'] ?? 'members', $p['start_date'], $p['end_date'],
                           $p['budget'], $p['project_manager_id'], $this->userId, $this->userId]);
            $new = (int)$this->db->lastInsertId();
            $this->db->prepare("INSERT IGNORE INTO project_members (company_id, project_id, user_id, role, can_edit, added_at)
                                SELECT company_id, ?, user_id, role, can_edit, NOW() FROM project_members WHERE project_id = ? AND company_id = ?")
                ->execute([$new, $projectId, $this->companyId]);
            $this->addMemberRow($new, $this->userId, 'owner', true);
            $boards = new BoardRepo($this);
            $st = $this->db->prepare("SELECT board_id FROM project_boards WHERE project_id = ? AND company_id = ? AND (archived = 0 OR archived IS NULL) ORDER BY sort_order, board_id");
            $st->execute([$projectId, $this->companyId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) $boards->copyBoard((int)$bid, $new, $withItems, false);
            Activity::log($this->db, $this->companyId, $this->userId, null, null, 'project_created', ['project_id' => $new, 'from' => $projectId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $new;
    }

    // ================================================================= members

    private function addMemberRow(int $projectId, int $userId, string $role, bool $upgradeOnly = false): void
    {
        $role = in_array($role, ['owner', 'manager', 'member', 'viewer'], true) ? $role : 'member';
        if ($upgradeOnly) {
            $st = $this->db->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ?");
            $st->execute([$projectId, $userId]);
            $cur = $st->fetchColumn();
            if ($cur !== false) {
                if ((self::LEVELS[$cur] ?? 0) < self::LEVELS[$role]) {
                    $this->db->prepare("UPDATE project_members SET role = ?, can_edit = 1 WHERE project_id = ? AND user_id = ?")->execute([$role, $projectId, $userId]);
                }
                return;
            }
        }
        $this->db->prepare("INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at) VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE role = VALUES(role), can_edit = VALUES(can_edit)")
            ->execute([$this->companyId, $projectId, $userId, $role, $role === 'viewer' ? 0 : 1]);
    }

    public function setMember(int $projectId, int $userId, string $role): void
    {
        $this->requireProject($projectId, 3);
        if (!isset($this->people()[$userId])) throw new ProjectUserError('Choose someone from your company.');
        if (!in_array($role, ['owner', 'manager', 'member', 'viewer'], true)) throw new ProjectUserError('Unknown role.');
        if ($role === 'owner' && $this->projectLevel($projectId) < 4) throw new ProjectForbidden('Only an owner can add another owner.');
        $st = $this->db->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ? AND company_id = ?");
        $st->execute([$projectId, $userId, $this->companyId]);
        $was = $st->fetchColumn();
        if ($was === 'owner' && $role !== 'owner') $this->guardLastOwner($projectId, $userId);
        $this->addMemberRow($projectId, $userId, $role);
        Activity::log($this->db, $this->companyId, $this->userId, null, null, $was === false ? 'member_added' : 'member_role', ['project_id' => $projectId, 'user_id' => $userId, 'role' => $role]);
        if ($was === false && $userId !== $this->userId) ProjectNotify::addedToProject($this, $projectId, $userId, $role);
    }

    public function removeMember(int $projectId, int $userId): void
    {
        $p = $this->requireProject($projectId, $userId === $this->userId ? 1 : 3);
        $st = $this->db->prepare("SELECT role FROM project_members WHERE project_id = ? AND user_id = ? AND company_id = ?");
        $st->execute([$projectId, $userId, $this->companyId]);
        $was = $st->fetchColumn();
        if ($was === 'owner') $this->guardLastOwner($projectId, $userId);
        $this->db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ? AND company_id = ?")->execute([$projectId, $userId, $this->companyId]);
        if ((int)$p['project_manager_id'] === $userId) {
            $this->db->prepare("UPDATE projects SET project_manager_id = NULL WHERE project_id = ? AND company_id = ?")->execute([$projectId, $this->companyId]);
        }
        Activity::log($this->db, $this->companyId, $this->userId, null, null, 'member_removed', ['project_id' => $projectId, 'user_id' => $userId]);
        $this->forgetProject($projectId);
    }

    private function guardLastOwner(int $projectId, int $leaving): void
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = ? AND company_id = ? AND role = 'owner' AND user_id <> ?");
        $st->execute([$projectId, $this->companyId, $leaving]);
        $p = $this->projectRow($projectId);
        $implicit = $p && in_array((int)($p['owner_id'] ?: $p['created_by']), [0, $leaving], true) === false;
        if (!(int)$st->fetchColumn() && !$implicit && !$this->isAdmin()) throw new ProjectUserError('A project needs at least one owner. Make someone else an owner first.');
    }

    // ================================================================= per-user state

    public function star(string $kind, int $refId, bool $on): void
    {
        if ($kind === 'project') $this->requireProject($refId, 1); else $this->requireBoard($refId, 1);
        $this->db->prepare("INSERT INTO project_user_state (company_id, user_id, kind, ref_id, starred) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE starred = VALUES(starred)")
            ->execute([$this->companyId, $this->userId, $kind, $refId, $on ? 1 : 0]);
    }

    /** Remember that the user opened a project or board (Recent). */
    public function touch(string $kind, int $refId): void
    {
        try {
            $this->db->prepare("INSERT INTO project_user_state (company_id, user_id, kind, ref_id, last_opened_at) VALUES (?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE last_opened_at = NOW()")
                ->execute([$this->companyId, $this->userId, $kind, $refId]);
        } catch (Throwable $e) {
            error_log('[projects] touch: ' . $e->getMessage());
        }
    }

    public function prefs(string $kind, int $refId): array
    {
        $st = $this->db->prepare("SELECT prefs FROM project_user_state WHERE user_id = ? AND kind = ? AND ref_id = ?");
        $st->execute([$this->userId, $kind, $refId]);
        $p = json_decode((string)$st->fetchColumn(), true);
        return is_array($p) ? $p : [];
    }

    public function savePrefs(string $kind, int $refId, array $patch): array
    {
        $prefs = array_merge($this->prefs($kind, $refId), $patch);
        $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 60000) throw new ProjectUserError('Too many preferences saved for this board.');
        $this->db->prepare("INSERT INTO project_user_state (company_id, user_id, kind, ref_id, prefs) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)")
            ->execute([$this->companyId, $this->userId, $kind, $refId, $json]);
        return $prefs;
    }

    /** Starred and recently opened boards across projects the user can open. */
    public function sidebarBoards(): array
    {
        [$vis] = $this->visibleSql('p');
        $st = $this->db->prepare("SELECT b.board_id, b.project_id, b.title, p.name AS project_name, p.color, p.project_id AS pid, s.starred, s.last_opened_at
                                    FROM project_user_state s
                                    JOIN project_boards b ON b.board_id = s.ref_id AND b.company_id = s.company_id
                                    JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                                   WHERE s.user_id = ? AND s.company_id = ? AND s.kind = 'board' AND (b.archived = 0 OR b.archived IS NULL)
                                     AND (s.starred = 1 OR s.last_opened_at IS NOT NULL) AND $vis
                                ORDER BY s.starred DESC, s.last_opened_at DESC LIMIT 30");
        $st->execute([$this->userId, $this->companyId]);
        $starred = [];
        $recent = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = ['id' => (int)$r['board_id'], 'title' => $r['title'], 'project' => $r['project_name'], 'project_id' => (int)$r['project_id'],
                    'color' => self::projectColor(['color' => $r['color'], 'project_id' => $r['pid']]), 'url' => '/projects/board.php?board_id=' . (int)$r['board_id']];
            if ((int)$r['starred']) $starred[] = $row;
            elseif (count($recent) < 6) $recent[] = $row;
        }
        return ['starred' => $starred, 'recent' => $recent];
    }

    // ================================================================= rate limits

    /** Seconds to wait, or 0 when the action may go ahead. */
    public static function rateLimit(string $key, int $max, int $windowSeconds): int
    {
        $dir = ProjectPaths::storageDir();
        if ($dir === null) return 0;
        $dir .= '/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $fh = @fopen($dir . '/' . sha1($key) . '.json', 'c+');
        if (!$fh) return 0;
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
            if (count($hits) >= $max) return max(1, $hits[0] + $windowSeconds - $now);
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            return 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
