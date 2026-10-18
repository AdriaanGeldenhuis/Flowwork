<?php
// calendar/lib/CalendarLinks.php
//
// Events can be linked to Flowwork records (calendar_event_links): a project,
// a board, a board task, an invoice or quote, a CRM customer/supplier or deal, an email,
// a pay run. This class turns a link into a label + URL — only when the viewer
// may open that record — and finds records for the "Link to…" picker.

final class CalendarLinks
{
    public const TYPES = [
        'project'         => ['label' => 'Project',  'icon' => 'briefcase'],
        'board'           => ['label' => 'Board',    'icon' => 'grid'],
        'item'            => ['label' => 'Task',     'icon' => 'check-square'],
        'invoice'         => ['label' => 'Invoice',  'icon' => 'receipt'],
        'quote'           => ['label' => 'Quote',    'icon' => 'file-text'],
        'crm_account'     => ['label' => 'Account',  'icon' => 'building'],
        'crm_opportunity' => ['label' => 'Deal',     'icon' => 'target'],
        'crm_interaction' => ['label' => 'Activity', 'icon' => 'building'],
        'email'           => ['label' => 'Email',    'icon' => 'mail'],
        'pay_run'         => ['label' => 'Pay run',  'icon' => 'wallet'],
    ];

    private PDO $db;
    private CalendarRepo $repo;

    public function __construct(PDO $db, CalendarRepo $repo)
    {
        $this->db = $db;
        $this->repo = $repo;
    }

    public function canLink(string $type, int $id): bool
    {
        return $id > 0 && $this->describe($type, $id) !== null;
    }

    /** Links of an event the viewer may open. */
    public function forEvent(int $eventId): array
    {
        $st = $this->db->prepare("SELECT linked_type, linked_id FROM calendar_event_links WHERE event_id = ? ORDER BY id");
        $st->execute([$eventId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if ($d = $this->describe((string)$l['linked_type'], (int)$l['linked_id'])) $out[] = $d;
        }
        return $out;
    }

    /** @return array{type:string,id:int,label:string,sub:string,url:string,icon:string,kind:string}|null */
    public function describe(string $type, int $id): ?array
    {
        if (!isset(self::TYPES[$type]) || $id <= 0) return null;
        $c = $this->repo->companyId;
        try {
            switch ($type) {
                case 'project':
                    if (!CalendarRepo::projectsAvailable()) return null;
                    $r = $this->one("SELECT project_id AS id, name AS label, project_number AS sub FROM projects WHERE project_id = ? AND company_id = ?", [$id, $c]);
                    if (!$r || !$this->repo->canSeeProject($id)) return null;
                    return $this->shape($type, $r, ProjectLink::projectUrl($id));
                case 'board':
                    if (!CalendarRepo::projectsAvailable()) return null;
                    $r = $this->one("SELECT b.board_id AS id, b.title AS label, p.name AS sub FROM project_boards b
                                       JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                                      WHERE b.board_id = ? AND b.company_id = ?", [$id, $c]);
                    if (!$r || !$this->canSeeBoard($id)) return null;
                    return $this->shape($type, $r, ProjectLink::boardUrl($id));
                case 'item':
                    if (!CalendarRepo::projectsAvailable()) return null;
                    $r = $this->one("SELECT i.id, i.title AS label, b.title AS sub, i.board_id FROM board_items i
                                       JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                                      WHERE i.id = ? AND i.company_id = ?", [$id, $c]);
                    if (!$r || !$this->canSeeBoard((int)$r['board_id'])) return null;
                    return $this->shape($type, $r, ProjectLink::itemUrl((int)$r['board_id'], $id));
                case 'invoice':
                    $r = $this->one("SELECT i.id, i.invoice_number AS label, a.name AS sub FROM invoices i
                                  LEFT JOIN crm_accounts a ON a.id = i.customer_id WHERE i.id = ? AND i.company_id = ?", [$id, $c]);
                    return $r ? $this->shape($type, $r, '/qi/invoice_view.php?id=' . $id) : null;
                case 'quote':
                    $r = $this->one("SELECT q.id, q.quote_number AS label, a.name AS sub FROM quotes q
                                  LEFT JOIN crm_accounts a ON a.id = q.customer_id WHERE q.id = ? AND q.company_id = ?", [$id, $c]);
                    return $r ? $this->shape($type, $r, '/qi/quote_view.php?id=' . $id) : null;
                case 'crm_account':
                    $r = $this->one("SELECT id, name AS label, type AS sub FROM crm_accounts WHERE id = ? AND company_id = ?", [$id, $c]);
                    if ($r) $r['sub'] = ucfirst((string)$r['sub']);
                    return $r ? $this->shape($type, $r, '/crm/account_view.php?id=' . $id) : null;
                case 'crm_opportunity':
                    $r = $this->one("SELECT o.id, o.title AS label, a.name AS sub FROM crm_opportunities o
                                  LEFT JOIN crm_accounts a ON a.id = o.account_id WHERE o.id = ? AND o.company_id = ?", [$id, $c]);
                    return $r ? $this->shape($type, $r, '/crm/opp_view.php?opp_id=' . $id) : null;
                case 'crm_interaction':
                    $r = $this->one("SELECT x.id, COALESCE(NULLIF(x.subject, ''), x.type) AS label, a.name AS sub, x.account_id
                                       FROM crm_interactions x JOIN crm_accounts a ON a.id = x.account_id
                                      WHERE x.id = ? AND x.company_id = ?", [$id, $c]);
                    return $r ? $this->shape($type, $r, '/crm/account_view.php?id=' . (int)$r['account_id']) : null;
                case 'email':
                    // Mail is personal: only the owner of the mailbox sees the link.
                    $r = $this->one("SELECT e.email_id AS id, COALESCE(NULLIF(e.subject, ''), '(no subject)') AS label,
                                            COALESCE(NULLIF(e.sender_name, ''), e.sender) AS sub, e.thread_id
                                       FROM emails e JOIN email_accounts a ON a.account_id = e.account_id
                                      WHERE e.email_id = ? AND e.company_id = ? AND a.user_id = ?", [$id, $c, $this->repo->userId]);
                    if (!$r) return null;
                    $key = (int)$r['thread_id'] > 0 ? 't' . (int)$r['thread_id'] : 'e' . $id;
                    return $this->shape($type, $r, '/mail/?open=' . $key);
                case 'pay_run':
                    if (!in_array($this->repo->role, ['admin', 'bookkeeper'], true)) return null;
                    $r = $this->one("SELECT id, name AS label, run_number AS sub FROM pay_runs WHERE id = ? AND company_id = ?", [$id, $c]);
                    return $r ? $this->shape($type, $r, '/payroll/run_view.php?id=' . $id) : null;
            }
        } catch (Throwable $e) {
            error_log('[calendar] link ' . $type . ':' . $id . ': ' . $e->getMessage());
        }
        return null;
    }

    /** Records matching $q that can be linked, a few per kind. */
    public function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $c = $this->repo->companyId;
        $out = [];
        $add = function (string $type, string $sql, array $params) use (&$out) {
            try {
                $st = $this->db->prepare($sql . ' LIMIT 6');
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if ($d = $this->describe($type, (int)$id)) $out[] = $d;
                }
            } catch (Throwable $e) {
                error_log('[calendar] link search ' . $type . ': ' . $e->getMessage());
            }
        };
        $projects = CalendarRepo::projectsAvailable() ? $this->repo->projectSql('p.project_id') : null;
        if ($projects !== null) {
            $add('project', "SELECT p.project_id FROM projects p WHERE p.company_id = ? AND (p.archived = 0 OR p.archived IS NULL)$projects
                               AND (p.name LIKE ? OR p.project_number LIKE ?) ORDER BY p.updated_at DESC", [$c, $like, $like]);
            $add('board', "SELECT b.board_id FROM project_boards b JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                            WHERE b.company_id = ? AND (b.archived = 0 OR b.archived IS NULL) AND (p.archived = 0 OR p.archived IS NULL)$projects
                              AND b.title LIKE ? ORDER BY b.title", [$c, $like]);
        }
        $add('crm_account', "SELECT id FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL AND name LIKE ? ORDER BY name", [$c, $like]);
        $add('crm_opportunity', "SELECT id FROM crm_opportunities WHERE company_id = ? AND title LIKE ? ORDER BY updated_at DESC", [$c, $like]);
        $add('invoice', "SELECT i.id FROM invoices i LEFT JOIN crm_accounts a ON a.id = i.customer_id
                          WHERE i.company_id = ? AND (i.invoice_number LIKE ? OR a.name LIKE ?) ORDER BY i.id DESC", [$c, $like, $like]);
        $add('quote', "SELECT q.id FROM quotes q LEFT JOIN crm_accounts a ON a.id = q.customer_id
                        WHERE q.company_id = ? AND (q.quote_number LIKE ? OR a.name LIKE ?) ORDER BY q.id DESC", [$c, $like, $like]);
        if ($projects !== null) {
            $add('item', "SELECT i.id FROM board_items i
                            JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                            JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                           WHERE i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL) AND (b.archived = 0 OR b.archived IS NULL)
                             AND (p.archived = 0 OR p.archived IS NULL)$projects AND i.title LIKE ? ORDER BY i.updated_at DESC", [$c, $like]);
        }
        return $out;
    }

    /** The Projects engine's board rule: project access, or a board_members row. */
    private function canSeeBoard(int $boardId): bool
    {
        return ProjectLink::canSeeBoard($this->db, $this->repo->companyId, $this->repo->userId, $this->repo->role, $boardId);
    }

    private function one(string $sql, array $params): ?array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function shape(string $type, array $r, string $url): array
    {
        return ['type' => $type, 'id' => (int)$r['id'], 'label' => (string)$r['label'], 'sub' => (string)($r['sub'] ?? ''),
                'url' => $url, 'icon' => self::TYPES[$type]['icon'], 'kind' => self::TYPES[$type]['label']];
    }
}
