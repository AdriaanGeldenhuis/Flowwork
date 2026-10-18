<?php
// projects/lib/ProjectLink.php
//
// The Projects API for the rest of Flowwork.
//
//   require_once __DIR__ . '/../../projects/lib/autoload.php';
//   ProjectLink::itemUrl(3, 9);                         // /projects/board.php?board_id=3&item=9
//   ProjectLink::pickerProjects($DB, $co, $user, $role, [$current]);  // dropdowns (QI, receipts, timesheets)
//   ProjectLink::belongs($DB, $co, $projectId);         // validate a posted project_id
//   ProjectLink::myWorkSummary($DB, $co, $user, $role); // Home tile: overdue / today / this week
//   ProjectLink::visibleProjectIds($DB, $co, $user, $role);  // null = all (admins)
//   ProjectLink::describe($DB, $co, 'item', 9);         // ['label' => …, 'url' => …] for links
//   ProjectLink::linkEmail($repo, 'item', 9, $emailId); // Mail → "Add to project"
//   ProjectLink::financials($repo, $projectId);         // budget, invoiced, costs, hours
//
// Everything is scoped to the company and, where a user is given, to the
// projects that user may open.

final class ProjectLink
{
    public static function projectUrl(int $projectId, ?string $tab = null): string
    {
        return '/projects/view.php?project_id=' . $projectId . ($tab ? '&tab=' . rawurlencode($tab) : '');
    }

    public static function boardUrl(int $boardId): string
    {
        return '/projects/board.php?board_id=' . $boardId;
    }

    /** $open: 'updates' | 'files' | 'activity' opens that tab of the item panel. */
    public static function itemUrl(int $boardId, int $itemId, ?string $open = null): string
    {
        return '/projects/board.php?board_id=' . $boardId . '&item=' . $itemId . ($open ? '&open=' . rawurlencode($open) : '');
    }

    public static function repo(PDO $db, int $companyId, int $userId, string $role): ProjectRepo
    {
        ProjectSchema::ensure($db);
        return new ProjectRepo($db, $companyId, $userId, $role);
    }

    /** @return array<int,true>|null projects the user can open; null = every project */
    public static function visibleProjectIds(PDO $db, int $companyId, int $userId, string $role): ?array
    {
        return self::repo($db, $companyId, $userId, $role)->visibleProjectIds();
    }

    public static function canSeeProject(PDO $db, int $companyId, int $userId, string $role, int $projectId): bool
    {
        return self::repo($db, $companyId, $userId, $role)->projectLevel($projectId) > 0;
    }

    public static function canSeeBoard(PDO $db, int $companyId, int $userId, string $role, int $boardId): bool
    {
        return self::repo($db, $companyId, $userId, $role)->boardLevel($boardId) > 0;
    }

    /** Does the project belong to the company? (validate posted ids) */
    public static function belongs(PDO $db, int $companyId, ?int $projectId): bool
    {
        if (!$projectId) return true;
        $st = $db->prepare("SELECT 1 FROM projects WHERE project_id = ? AND company_id = ?");
        $st->execute([$projectId, $companyId]);
        return (bool)$st->fetchColumn();
    }

    /** Does the board (and item, when given) belong to the company? */
    public static function boardBelongs(PDO $db, int $companyId, ?int $boardId, ?int $itemId = null): bool
    {
        if ($itemId) {
            $st = $db->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $st->execute([$itemId, $companyId]);
            $b = $st->fetchColumn();
            if ($b === false || ($boardId && (int)$b !== $boardId)) return false;
            return true;
        }
        if (!$boardId) return true;
        $st = $db->prepare("SELECT 1 FROM project_boards WHERE board_id = ? AND company_id = ?");
        $st->execute([$boardId, $companyId]);
        return (bool)$st->fetchColumn();
    }

    /** The project a board is on (0 when not found). */
    public static function projectOfBoard(PDO $db, int $companyId, int $boardId): int
    {
        $st = $db->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
        $st->execute([$boardId, $companyId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Projects for a dropdown: open ones the user can see, plus any ids in
     * $keep (the document's current project, even if completed).
     * @return array<int, array{id:int, name:string, number:string, status:string, client_id:?int, label:string}>
     */
    public static function pickerProjects(PDO $db, int $companyId, int $userId, string $role, array $keep = []): array
    {
        $repo = self::repo($db, $companyId, $userId, $role);
        [$vis] = $repo->visibleSql('p');
        $keep = array_values(array_filter(array_map('intval', $keep)));
        $keepSql = $keep ? ' OR p.project_id IN (' . implode(',', $keep) . ')' : '';
        $st = $db->prepare("SELECT p.project_id, p.name, p.project_number, p.status, p.client_id FROM projects p
                             WHERE p.company_id = ? AND (($vis AND (p.archived = 0 OR p.archived IS NULL) AND p.status IN ('draft','active','on_hold'))$keepSql)
                          ORDER BY p.name LIMIT 1000");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $num = (string)($p['project_number'] ?? '');
            $out[] = ['id' => (int)$p['project_id'], 'name' => $p['name'], 'number' => $num, 'status' => $p['status'],
                      'client_id' => $p['client_id'] ? (int)$p['client_id'] : null,
                      'label' => ($num !== '' ? $num . ' · ' : '') . $p['name'] . (in_array($p['status'], ['draft', 'active', 'on_hold'], true) ? '' : ' (' . (ProjectRepo::STATUSES[$p['status']] ?? $p['status']) . ')')];
        }
        return $out;
    }

    /** Boards of a project for a dropdown (live boards the user can open — all of them, or just theirs for board members). */
    public static function pickerBoards(PDO $db, int $companyId, int $userId, string $role, int $projectId): array
    {
        $repo = self::repo($db, $companyId, $userId, $role);
        return array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title'], 'type' => $b['type']], $repo->boardsOf($projectId));
    }

    /** Items of a board for a dropdown. */
    public static function pickerItems(PDO $db, int $companyId, int $userId, string $role, int $boardId): array
    {
        $repo = self::repo($db, $companyId, $userId, $role);
        if ($repo->boardLevel($boardId) < 1) return [];
        $st = $db->prepare("SELECT i.id, i.title, g.name AS group_name FROM board_items i LEFT JOIN board_groups g ON g.id = i.group_id
                             WHERE i.board_id = ? AND i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL)
                          ORDER BY g.position, i.position, i.id LIMIT 3000");
        $st->execute([$boardId, $companyId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'group' => (string)$r['group_name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ================================================================= my work

    /**
     * Open items the user is on, across every board they can open, with the
     * label and colour of the board's status column. Sorted by due date.
     */
    public static function myWork(ProjectRepo $repo, int $limit = 400, bool $includeDone = false): array
    {
        [$vis] = $repo->visibleSql('p');
        $st = $repo->db->prepare("SELECT i.id, i.title, i.board_id, i.group_id, i.due_date, i.start_date, i.status_label, i.progress, i.priority, i.updated_at,
                                         b.title AS board, p.project_id, p.name AS project, p.color, g.name AS group_name
                                    FROM board_item_people ip
                                    JOIN board_items i ON i.id = ip.item_id AND i.company_id = ip.company_id
                                    JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                    JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                               LEFT JOIN board_groups g ON g.id = i.group_id
                                   WHERE ip.user_id = ? AND ip.company_id = ? AND (i.archived = 0 OR i.archived IS NULL)
                                     AND (b.archived = 0 OR b.archived IS NULL) AND p.status NOT IN ('completed','cancelled','archived')
                                     AND $vis" . ($includeDone ? '' : ' AND i.progress < 100') . "
                                ORDER BY i.due_date IS NULL, i.due_date, i.id DESC LIMIT " . max(1, min(1000, $limit)));
        $st->execute([$repo->userId, $repo->companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $statusCols = [];
        $boards = array_values(array_unique(array_map(fn($r) => (int)$r['board_id'], $rows)));
        if ($boards) {
            $cs = $repo->db->query("SELECT * FROM board_columns WHERE type = 'status' AND company_id = " . (int)$repo->companyId . " AND board_id IN (" . implode(',', $boards) . ") ORDER BY position, column_id");
            foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $statusCols[(int)$c['board_id']] = $statusCols[(int)$c['board_id']] ?? $c;
        }
        $out = [];
        foreach ($rows as $r) {
            $bid = (int)$r['board_id'];
            $status = null;
            if ($r['status_label'] !== null && isset($statusCols[$bid])) {
                $cfg = Columns::config($statusCols[$bid]);
                $l = Columns::statusOf($cfg, (string)$r['status_label']);
                $status = $l ? ['key' => $l['key'], 'label' => $l['label'], 'color' => $l['color'], 'done' => !empty($l['done']), 'column_id' => (int)$statusCols[$bid]['column_id']]
                             : ['key' => $r['status_label'], 'label' => $r['status_label'], 'color' => '#85766b', 'done' => false, 'column_id' => (int)$statusCols[$bid]['column_id']];
            }
            $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'board_id' => $bid, 'board' => $r['board'], 'group' => (string)$r['group_name'],
                      'project_id' => (int)$r['project_id'], 'project' => $r['project'], 'color' => ProjectRepo::projectColor(['color' => $r['color'], 'project_id' => $r['project_id']]),
                      'due' => Columns::date($r['due_date']), 'start' => Columns::date($r['start_date']), 'status' => $status,
                      'status_column' => isset($statusCols[$bid]) ? (int)$statusCols[$bid]['column_id'] : null,
                      'statuses' => isset($statusCols[$bid]) ? Columns::config($statusCols[$bid])['labels'] : [],
                      'priority' => $r['priority'], 'progress' => (int)$r['progress'], 'done' => (int)$r['progress'] >= 100,
                      'url' => self::itemUrl($bid, (int)$r['id'])];
        }
        return $out;
    }

    /** Counts for the Home tile. @return array{overdue:int, today:int, week:int, open:int, projects:int} */
    public static function myWorkSummary(PDO $db, int $companyId, int $userId, string $role): array
    {
        $repo = self::repo($db, $companyId, $userId, $role);
        [$vis] = $repo->visibleSql('p');
        $today = ProjectRepo::today();
        $week = date('Y-m-d', strtotime($today . ' +7 days'));
        $st = $db->prepare("SELECT COUNT(*) AS open, SUM(i.due_date < ?) AS overdue, SUM(i.due_date = ?) AS today, SUM(i.due_date > ? AND i.due_date <= ?) AS week
                              FROM board_item_people ip
                              JOIN board_items i ON i.id = ip.item_id AND i.company_id = ip.company_id
                              JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                              JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                             WHERE ip.user_id = ? AND ip.company_id = ? AND i.progress < 100 AND (i.archived = 0 OR i.archived IS NULL)
                               AND (b.archived = 0 OR b.archived IS NULL) AND p.status NOT IN ('completed','cancelled','archived') AND $vis");
        $st->execute([$today, $today, $today, $week, $userId, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $db->prepare("SELECT COUNT(*) FROM projects p WHERE p.company_id = ? AND (p.archived = 0 OR p.archived IS NULL) AND p.status IN ('draft','active','on_hold') AND $vis");
        $st->execute([$companyId]);
        return ['overdue' => (int)($r['overdue'] ?? 0), 'today' => (int)($r['today'] ?? 0), 'week' => (int)($r['week'] ?? 0),
                'open' => (int)($r['open'] ?? 0), 'projects' => (int)$st->fetchColumn()];
    }

    // ================================================================= links

    /** Label and URL for a linked project, board or item (null when gone / other company). */
    public static function describe(PDO $db, int $companyId, string $type, int $id): ?array
    {
        switch ($type) {
            case 'project':
                $st = $db->prepare("SELECT name, project_number FROM projects WHERE project_id = ? AND company_id = ?");
                $st->execute([$id, $companyId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                return $r ? ['label' => ($r['project_number'] ? $r['project_number'] . ' · ' : '') . $r['name'], 'url' => self::projectUrl($id), 'icon' => 'briefcase'] : null;
            case 'board':
                $st = $db->prepare("SELECT b.title, p.name FROM project_boards b JOIN projects p ON p.project_id = b.project_id WHERE b.board_id = ? AND b.company_id = ?");
                $st->execute([$id, $companyId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                return $r ? ['label' => $r['title'] . ' · ' . $r['name'], 'url' => self::boardUrl($id), 'icon' => 'grid'] : null;
            case 'item':
                $st = $db->prepare("SELECT i.title, i.board_id, b.title AS board FROM board_items i JOIN project_boards b ON b.board_id = i.board_id WHERE i.id = ? AND i.company_id = ?");
                $st->execute([$id, $companyId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                return $r ? ['label' => $r['title'] . ' · ' . $r['board'], 'url' => self::itemUrl((int)$r['board_id'], $id), 'icon' => 'check-square'] : null;
        }
        return null;
    }

    /**
     * Link an email to a project or item (Mail → "Add to project"). The email
     * must be in one of the user's own mailboxes.
     */
    /** The email (subject, sender, text) if it is in one of the user's own mailboxes. */
    public static function ownEmail(ProjectRepo $repo, int $emailId): array
    {
        $st = $repo->db->prepare("SELECT e.email_id, e.subject, e.sender, e.sender_name, e.sent_at, e.body_text FROM emails e JOIN email_accounts a ON a.account_id = e.account_id
                                   WHERE e.email_id = ? AND a.company_id = ? AND a.user_id = ?");
        $st->execute([$emailId, $repo->companyId, $repo->userId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) throw new ProjectUserError('That email was not found in your mailboxes.');
        return $e;
    }

    public static function linkEmail(ProjectRepo $repo, string $type, int $id, int $emailId): void
    {
        if ($type === 'item') $repo->requireItem($id, 1);
        elseif ($type === 'project') $repo->requireProject($id, 1);
        else throw new ProjectUserError('Unknown link.');
        self::ownEmail($repo, $emailId);
        $st = $repo->db->prepare("SELECT 1 FROM email_links WHERE email_id = ? AND linked_type = ? AND linked_id = ?");
        $st->execute([$emailId, $type, $id]);
        if ($st->fetchColumn()) return;
        try {
            $repo->db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at, company_id) VALUES (?, ?, ?, ?, NOW(), ?)")
                ->execute([$emailId, $type, $id, $repo->userId, $repo->companyId]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S22') throw $e; // installs where Mail has not added company_id yet
            $repo->db->prepare("INSERT INTO email_links (email_id, linked_type, linked_id, created_by, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$emailId, $type, $id, $repo->userId]);
        }
        if ($type === 'item') {
            $st = $repo->db->prepare("SELECT board_id FROM board_items WHERE id = ?");
            $st->execute([$id]);
            Activity::log($repo->db, $repo->companyId, $repo->userId, (int)$st->fetchColumn(), $id, 'email_linked', ['email_id' => $emailId]);
        }
    }

    /** What else in Flowwork points at an item: emails, events, documents, hours. */
    public static function itemLinks(ProjectRepo $repo, int $itemId): array
    {
        $out = ['emails' => [], 'events' => [], 'documents' => [], 'hours' => 0.0];
        $db = $repo->db;
        try {
            $st = $db->prepare("SELECT e.email_id, e.subject, e.sender, e.sender_name, e.sent_at, a.user_id AS owner, l.created_by
                                  FROM email_links l JOIN emails e ON e.email_id = l.email_id
                                  JOIN email_accounts a ON a.account_id = e.account_id AND a.company_id = ?
                                 WHERE l.linked_type = 'item' AND l.linked_id = ? ORDER BY e.sent_at DESC LIMIT 50");
            $st->execute([$repo->companyId, $itemId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $out['emails'][] = ['id' => (int)$e['email_id'], 'subject' => (string)($e['subject'] ?: '(no subject)'), 'from' => (string)($e['sender_name'] ?: $e['sender']),
                                    'at' => $e['sent_at'], 'url' => (int)$e['owner'] === $repo->userId ? '/mail/?open=e' . (int)$e['email_id'] : null,
                                    'by' => $repo->personName((int)$e['created_by'])];
            }
        } catch (Throwable $e) { /* mail not installed */ }
        try {
            if (!class_exists('CalendarLink')) require_once ProjectPaths::root() . '/calendar/lib/autoload.php';
            foreach (CalendarLink::upcomingFor($db, $repo->companyId, $repo->userId, $repo->role, 'item', $itemId, 10) as $ev) {
                $out['events'][] = ['id' => $ev['id'], 'title' => $ev['title'], 'start' => $ev['start_datetime'], 'all_day' => $ev['all_day'], 'url' => $ev['url']];
            }
        } catch (Throwable $e) { /* calendar not installed */ }
        try {
            $st = $db->prepare("SELECT DISTINCT q.id, q.quote_number AS number, q.status, q.total FROM quote_lines l JOIN quotes q ON q.id = l.quote_id
                                 WHERE l.project_item_id = ? AND q.company_id = ? LIMIT 20");
            $st->execute([$itemId, $repo->companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) $out['documents'][] = ['kind' => 'quote', 'id' => (int)$q['id'], 'number' => $q['number'], 'status' => $q['status'], 'total' => (float)$q['total'], 'url' => '/qi/quote_view.php?id=' . (int)$q['id']];
            $st = $db->prepare("SELECT DISTINCT v.id, v.invoice_number AS number, v.status, v.total FROM invoice_lines l JOIN invoices v ON v.id = l.invoice_id
                                 WHERE l.project_item_id = ? AND v.company_id = ? LIMIT 20");
            $st->execute([$itemId, $repo->companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) $out['documents'][] = ['kind' => 'invoice', 'id' => (int)$q['id'], 'number' => $q['number'], 'status' => $q['status'], 'total' => (float)$q['total'], 'url' => '/qi/invoice_view.php?id=' . (int)$q['id']];
            $st = $db->prepare("SELECT DISTINCT b.id, b.vendor_invoice_number AS number, b.status, b.total FROM ap_bill_lines l JOIN ap_bills b ON b.id = l.bill_id
                                 WHERE l.project_item_id = ? AND b.company_id = ? LIMIT 20");
            $st->execute([$itemId, $repo->companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) $out['documents'][] = ['kind' => 'bill', 'id' => (int)$q['id'], 'number' => (string)$q['number'], 'status' => $q['status'], 'total' => (float)$q['total'], 'url' => null];
        } catch (Throwable $e) { /* documents not installed */ }
        try {
            $st = $db->prepare("SELECT COALESCE(SUM(regular_hours + COALESCE(ot_hours,0) + COALESCE(sunday_hours,0) + COALESCE(public_holiday_hours,0)), 0)
                                  FROM timesheet_entries WHERE item_id = ? AND company_id = ?");
            $st->execute([$itemId, $repo->companyId]);
            $out['hours'] = round((float)$st->fetchColumn(), 2);
        } catch (Throwable $e) { /* timesheets not installed */ }
        return $out;
    }

    // ================================================================= money

    /**
     * A project's money: budget, quotes, invoices (excl. VAT), costs posted in
     * Finance (expense lines tagged with the project or its boards), bills
     * awaiting posting, costs recorded in Projects, and timesheet hours with
     * an estimated labour cost.
     */
    public static function financials(ProjectRepo $repo, int $projectId): array
    {
        $p = $repo->requireProject($projectId, 1);
        $db = $repo->db;
        $co = $repo->companyId;
        $st = $db->prepare("SELECT board_id FROM project_boards WHERE project_id = ? AND company_id = ?");
        $st->execute([$projectId, $co]);
        $boards = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $boardIn = $boards ? implode(',', $boards) : '0';
        $out = ['budget' => (float)$p['budget'], 'quotes' => [], 'invoices' => [], 'quoted' => 0.0, 'accepted' => 0.0,
                'invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'gl_cost' => 0.0, 'committed' => 0.0,
                'manual_cost' => 0.0, 'labour_hours' => 0.0, 'labour_cost' => 0.0, 'cost_items' => [], 'gl_lines' => []];
        try {
            $st = $db->prepare("SELECT q.id, q.quote_number, q.status, q.issue_date, q.subtotal, q.total, a.name AS customer
                                  FROM quotes q LEFT JOIN crm_accounts a ON a.id = q.customer_id AND a.company_id = q.company_id
                                 WHERE q.project_id = ? AND q.company_id = ? ORDER BY q.issue_date DESC, q.id DESC LIMIT 200");
            $st->execute([$projectId, $co]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $out['quotes'][] = ['id' => (int)$q['id'], 'number' => $q['quote_number'], 'status' => $q['status'], 'date' => $q['issue_date'],
                                    'subtotal' => (float)$q['subtotal'], 'total' => (float)$q['total'], 'customer' => (string)$q['customer'], 'url' => '/qi/quote_view.php?id=' . (int)$q['id']];
                if (!in_array($q['status'], ['draft', 'declined', 'expired', 'cancelled'], true)) $out['quoted'] += (float)$q['subtotal'];
                if (in_array($q['status'], ['accepted', 'converted'], true)) $out['accepted'] += (float)$q['subtotal'];
            }
            $st = $db->prepare("SELECT v.id, v.invoice_number, v.status, v.issue_date, v.due_date, v.subtotal, v.total, v.balance_due, a.name AS customer
                                  FROM invoices v LEFT JOIN crm_accounts a ON a.id = v.customer_id AND a.company_id = v.company_id
                                 WHERE v.project_id = ? AND v.company_id = ? ORDER BY v.issue_date DESC, v.id DESC LIMIT 200");
            $st->execute([$projectId, $co]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $out['invoices'][] = ['id' => (int)$v['id'], 'number' => $v['invoice_number'], 'status' => $v['status'], 'date' => $v['issue_date'], 'due' => $v['due_date'],
                                      'subtotal' => (float)$v['subtotal'], 'total' => (float)$v['total'], 'balance' => (float)$v['balance_due'], 'customer' => (string)$v['customer'],
                                      'url' => '/qi/invoice_view.php?id=' . (int)$v['id']];
                if (in_array($v['status'], ['draft', 'cancelled'], true)) continue;
                $out['invoiced'] += (float)$v['subtotal'];
                if ($v['status'] === 'paid') $out['paid'] += (float)$v['total'];
                else {
                    $bal = (float)$v['balance_due'] > 0 ? (float)$v['balance_due'] : (float)$v['total'];
                    $out['outstanding'] += $bal;
                    $out['paid'] += max(0, (float)$v['total'] - $bal);
                }
            }
        } catch (Throwable $e) { error_log('[projects] financials qi: ' . $e->getMessage()); }
        try {
            $st = $db->prepare("SELECT je.entry_date, je.reference, COALESCE(jl.description, je.description) AS description, jl.account_code, ga.account_name,
                                       (jl.debit - jl.credit) AS amount
                                  FROM journal_lines jl
                                  JOIN journal_entries je ON je.id = jl.journal_id AND je.company_id = ? AND je.status = 'posted'
                                  JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id AND ga.account_type = 'expense'
                                 WHERE (jl.project_id = ? OR jl.board_id IN ($boardIn))
                              ORDER BY je.entry_date DESC, jl.id DESC LIMIT 1000");
            $st->execute([$co, $projectId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $out['gl_cost'] += (float)$l['amount'];
                if (count($out['gl_lines']) < 200) $out['gl_lines'][] = ['date' => $l['entry_date'], 'reference' => (string)$l['reference'], 'description' => (string)$l['description'],
                                                                         'account' => $l['account_code'] . ' ' . $l['account_name'], 'amount' => round((float)$l['amount'], 2)];
            }
        } catch (Throwable $e) { error_log('[projects] financials gl: ' . $e->getMessage()); }
        try {
            if ($boards) {
                $st = $db->prepare("SELECT COALESCE(SUM(l.line_total), 0) FROM ap_bill_lines l JOIN ap_bills b ON b.id = l.bill_id
                                     WHERE b.company_id = ? AND l.project_board_id IN ($boardIn) AND b.status IN ('draft','review','approved')");
                $st->execute([$co]);
                $out['committed'] = (float)$st->fetchColumn();
            }
        } catch (Throwable $e) { /* AP not installed */ }
        try {
            $st = $db->prepare("SELECT c.*, i.title AS item_title, a.name AS supplier FROM cost_items c
                             LEFT JOIN board_items i ON i.id = c.item_id AND i.company_id = c.company_id
                             LEFT JOIN crm_accounts a ON a.id = c.supplier_id AND a.company_id = c.company_id
                                 WHERE c.project_id = ? AND c.company_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 500");
            $st->execute([$projectId, $co]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $amt = round((float)$c['qty'] * (float)$c['unit_cost'], 2);
                $out['manual_cost'] += $amt;
                $out['cost_items'][] = ['id' => (int)$c['id'], 'type' => $c['type'], 'description' => $c['description'], 'qty' => (float)$c['qty'], 'unit_cost' => (float)$c['unit_cost'],
                                        'amount' => $amt, 'item_id' => $c['item_id'] ? (int)$c['item_id'] : null, 'item' => $c['item_title'], 'supplier' => $c['supplier'],
                                        'supplier_id' => $c['supplier_id'] ? (int)$c['supplier_id'] : null, 'by' => $repo->personName((int)$c['created_by']), 'at' => $c['created_at']];
            }
        } catch (Throwable $e) { error_log('[projects] financials costs: ' . $e->getMessage()); }
        try {
            $st = $db->prepare("SELECT COALESCE(SUM(t.regular_hours),0) r, COALESCE(SUM(t.ot_hours),0) o, COALESCE(SUM(t.sunday_hours),0) s, COALESCE(SUM(t.public_holiday_hours),0) h,
                                       COALESCE(SUM((t.regular_hours + 1.5 * COALESCE(t.ot_hours,0) + 2 * COALESCE(t.sunday_hours,0) + 2 * COALESCE(t.public_holiday_hours,0)) * COALESCE(e.hourly_rate_cents,0)) / 100, 0) cost
                                  FROM timesheet_entries t LEFT JOIN employees e ON e.id = t.employee_id AND e.company_id = t.company_id
                                 WHERE t.company_id = ? AND t.status <> 'rejected' AND (t.project_id = ? OR t.board_id IN ($boardIn))");
            $st->execute([$co, $projectId]);
            $h = $st->fetch(PDO::FETCH_ASSOC);
            $out['labour_hours'] = round((float)$h['r'] + (float)$h['o'] + (float)$h['s'] + (float)$h['h'], 2);
            $out['labour_cost'] = round((float)$h['cost'], 2);
        } catch (Throwable $e) { /* timesheets not installed */ }
        foreach (['quoted', 'accepted', 'invoiced', 'paid', 'outstanding', 'gl_cost', 'committed', 'manual_cost'] as $k) $out[$k] = round($out[$k], 2);
        $out['actual_cost'] = round($out['gl_cost'] + $out['manual_cost'] + $out['labour_cost'], 2);
        $out['margin'] = round($out['invoiced'] - $out['actual_cost'], 2);
        $out['budget_used'] = $out['budget'] > 0 ? round($out['actual_cost'] * 100 / $out['budget'], 1) : null;
        // Keep projects.actual_cost in step for reports that read the column.
        try {
            if (abs((float)$p['actual_cost'] - $out['actual_cost']) >= 0.01) {
                $db->prepare("UPDATE projects SET actual_cost = ?, updated_at = updated_at WHERE project_id = ? AND company_id = ?")->execute([$out['actual_cost'], $projectId, $co]);
            }
        } catch (Throwable $e) { /* column missing */ }
        return $out;
    }

    /** Add / edit / delete a cost recorded in Projects (cost_items). */
    public static function saveCost(ProjectRepo $repo, int $projectId, array $in): int
    {
        $repo->requireProject($projectId, 2);
        $type = in_array($in['type'] ?? '', ['material', 'labour', 'equipment', 'other'], true) ? $in['type'] : 'material';
        $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 2000);
        if ($desc === '') throw new ProjectUserError('Describe the cost.');
        $qty = str_replace([' ', ','], '', (string)($in['qty'] ?? '1'));
        $unit = str_replace([' ', ',', 'R'], '', (string)($in['unit_cost'] ?? '0'));
        if (!is_numeric($qty) || !is_numeric($unit) || (float)$qty <= 0 || (float)$unit < 0 || (float)$qty * (float)$unit > 1e12) throw new ProjectUserError('Check the quantity and amount.');
        $item = (int)($in['item_id'] ?? 0) ?: null;
        if ($item) {
            $st = $repo->db->prepare("SELECT 1 FROM board_items i JOIN project_boards b ON b.board_id = i.board_id WHERE i.id = ? AND b.project_id = ? AND i.company_id = ?");
            $st->execute([$item, $projectId, $repo->companyId]);
            if (!$st->fetchColumn()) $item = null;
        }
        $sup = (int)($in['supplier_id'] ?? 0) ?: null;
        if ($sup) {
            $st = $repo->db->prepare("SELECT 1 FROM crm_accounts WHERE id = ? AND company_id = ?");
            $st->execute([$sup, $repo->companyId]);
            if (!$st->fetchColumn()) $sup = null;
        }
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            $repo->db->prepare("UPDATE cost_items SET type = ?, description = ?, qty = ?, unit_cost = ?, item_id = ?, supplier_id = ? WHERE id = ? AND project_id = ? AND company_id = ?")
                ->execute([$type, $desc, (float)$qty, round((float)$unit, 2), $item, $sup, $id, $projectId, $repo->companyId]);
        } else {
            $repo->db->prepare("INSERT INTO cost_items (company_id, project_id, item_id, type, description, qty, unit_cost, supplier_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$repo->companyId, $projectId, $item, $type, $desc, (float)$qty, round((float)$unit, 2), $sup, $repo->userId]);
            $id = (int)$repo->db->lastInsertId();
        }
        Activity::log($repo->db, $repo->companyId, $repo->userId, null, null, 'cost_saved', ['project_id' => $projectId, 'description' => Activity::clip($desc, 80), 'amount' => round((float)$qty * (float)$unit, 2)]);
        return $id;
    }

    public static function deleteCost(ProjectRepo $repo, int $projectId, int $costId): void
    {
        $repo->requireProject($projectId, 2);
        $repo->db->prepare("DELETE FROM cost_items WHERE id = ? AND project_id = ? AND company_id = ?")->execute([$costId, $projectId, $repo->companyId]);
    }

    // ================================================================= search

    /** Projects, boards and items matching a query, for the search box. */
    public static function search(ProjectRepo $repo, string $q, int $limit = 40): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return ['projects' => [], 'boards' => [], 'items' => []];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        [$vis] = $repo->visibleSql('p');
        $co = $repo->companyId;
        $st = $repo->db->prepare("SELECT p.project_id, p.name, p.project_number, p.status, p.color FROM projects p
                                   WHERE p.company_id = ? AND $vis AND (p.name LIKE ? OR p.project_number LIKE ? OR p.client LIKE ? OR p.site_address LIKE ?)
                                ORDER BY p.status = 'active' DESC, p.name LIMIT 10");
        $st->execute([$co, $like, $like, $like, $like]);
        $projects = array_map(fn($p) => ['id' => (int)$p['project_id'], 'name' => $p['name'], 'number' => (string)$p['project_number'], 'status' => $p['status'],
                                        'color' => ProjectRepo::projectColor($p), 'url' => self::projectUrl((int)$p['project_id'])], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $repo->db->prepare("SELECT b.board_id, b.title, p.name AS project, p.project_id, p.color FROM project_boards b JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                                   WHERE b.company_id = ? AND $vis AND (b.archived = 0 OR b.archived IS NULL) AND b.title LIKE ? ORDER BY b.title LIMIT 10");
        $st->execute([$co, $like]);
        $boards = array_map(fn($b) => ['id' => (int)$b['board_id'], 'title' => $b['title'], 'project' => $b['project'], 'color' => ProjectRepo::projectColor($b), 'url' => self::boardUrl((int)$b['board_id'])],
                            $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $repo->db->prepare("SELECT DISTINCT i.id, i.title, i.board_id, i.due_date, i.status_label, b.title AS board, p.name AS project, p.project_id, p.color
                                    FROM board_items i JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                    JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                               LEFT JOIN board_item_values v ON v.item_id = i.id
                                   WHERE i.company_id = ? AND $vis AND (i.archived = 0 OR i.archived IS NULL) AND (b.archived = 0 OR b.archived IS NULL)
                                     AND (i.title LIKE ? OR i.description LIKE ? OR v.value LIKE ?)
                                ORDER BY i.updated_at DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute([$co, $like, $like, $like]);
        $items = array_map(fn($i) => ['id' => (int)$i['id'], 'title' => $i['title'], 'board_id' => (int)$i['board_id'], 'board' => $i['board'], 'project' => $i['project'],
                                      'color' => ProjectRepo::projectColor($i), 'due' => Columns::date($i['due_date']), 'status' => $i['status_label'],
                                      'url' => self::itemUrl((int)$i['board_id'], (int)$i['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['projects' => $projects, 'boards' => $boards, 'items' => $items];
    }
}
