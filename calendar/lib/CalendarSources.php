<?php
// calendar/lib/CalendarSources.php
//
// Dates that live in other modules, shown on the calendar as they are right
// now (nothing is copied, so nothing goes stale or duplicates):
//   projects  project start and due dates (projects the user can open)
//   tasks     board items assigned to the user, on their due date
//   invoices  unpaid invoices on their due date (overdue ones flagged)
//   quotes    sent quotes on their expiry date
//   deals     the user's open CRM opportunities on their expected close date
//   payroll   pay days (admins and bookkeepers only)
// Admins switch sources on or off for the company; users hide the ones they
// do not want (CalendarSettings). Every query is scoped to the company and to
// what the user may open in that module.

final class CalendarSources
{
    public const META = [
        'projects' => ['label' => 'Projects',        'color' => '#7a5ab8', 'icon' => 'briefcase'],
        'tasks'    => ['label' => 'My tasks',        'color' => '#c08a2b', 'icon' => 'check-square'],
        'invoices' => ['label' => 'Invoices due',    'color' => '#2f7457', 'icon' => 'receipt'],
        'quotes'   => ['label' => 'Quotes expiring', 'color' => '#2f5c84', 'icon' => 'file-text'],
        'deals'    => ['label' => 'Deals closing',   'color' => '#b4577a', 'icon' => 'target'],
        'payroll'  => ['label' => 'Pay days',        'color' => '#5b6670', 'icon' => 'wallet'],
    ];

    private PDO $db;
    private CalendarRepo $repo;
    private static array $columns = [];

    public function __construct(PDO $db, CalendarRepo $repo)
    {
        $this->db = $db;
        $this->repo = $repo;
    }

    /** @return array<string,bool> source => allowed by the company for this user's role */
    public function available(): array
    {
        return CalendarSettings::availableSources($this->db, $this->repo->companyId, $this->repo->role);
    }

    /** Items from the enabled sources overlapping [$fromDate, $toDate] ('Y-m-d'). */
    public function items(string $fromDate, string $toDate, array $enabled): array
    {
        $out = [];
        $available = $this->available();
        $today = substr(CalendarRepo::nowLocal($this->db, $this->repo->companyId), 0, 10);
        foreach (array_keys(self::META) as $name) {
            if (empty($available[$name]) || empty($enabled[$name])) continue;
            try {
                foreach ($this->$name($fromDate, $toDate, $today) as $item) {
                    $out[] = $item + ['source' => $name, 'color' => self::META[$name]['color'], 'icon' => self::META[$name]['icon'],
                                      'all_day' => true, 'status' => null, 'sub' => ''];
                }
            } catch (Throwable $e) {
                // A module that is not installed (or an older schema) must not break the calendar.
                error_log('[calendar] source ' . $name . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    private function projects(string $from, string $to, string $today): array
    {
        $c = $this->repo->companyId;
        $st = $this->db->prepare("SELECT project_id, name, project_number, start_date, end_date, status FROM projects
                                   WHERE company_id = ? AND (archived = 0 OR archived IS NULL) AND status IN ('draft','active','on_hold')
                                     AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))
                                   LIMIT 500");
        $st->execute([$c, $from, $to, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (!$this->repo->canSeeProject((int)$p['project_id'])) continue;
            $url = '/projects/view.php?project_id=' . (int)$p['project_id'];
            $sub = trim(($p['project_number'] ? $p['project_number'] . ' · ' : '') . ucfirst(str_replace('_', ' ', $p['status'])));
            if ($p['start_date'] && $p['start_date'] >= $from && $p['start_date'] <= $to && $p['start_date'] !== $p['end_date']) {
                $out[] = ['key' => 'project-start:' . $p['project_id'], 'title' => $p['name'] . ' starts', 'sub' => $sub,
                          'start' => $p['start_date'], 'end' => $p['start_date'], 'url' => $url, 'icon' => 'flag'];
            }
            if ($p['end_date'] && $p['end_date'] >= $from && $p['end_date'] <= $to) {
                $out[] = ['key' => 'project-due:' . $p['project_id'], 'title' => $p['name'] . ' due', 'sub' => $sub,
                          'start' => $p['end_date'], 'end' => $p['end_date'], 'url' => $url,
                          'status' => $p['end_date'] < $today ? 'overdue' : null];
            }
        }
        return $out;
    }

    private function tasks(string $from, string $to, string $today): array
    {
        $st = $this->db->prepare("SELECT i.id, i.title, i.due_date, i.status_label, i.progress, i.board_id, b.title AS board_title, p.name AS project_name
                                    FROM board_items i
                                    JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                               LEFT JOIN projects p ON p.project_id = b.project_id
                                   WHERE i.company_id = ? AND i.assigned_to = ? AND (i.archived = 0 OR i.archived IS NULL)
                                     AND (b.archived = 0 OR b.archived IS NULL) AND i.due_date BETWEEN ? AND ?
                                ORDER BY i.due_date LIMIT 500");
        $st->execute([$this->repo->companyId, $this->repo->userId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $done = (int)$t['progress'] >= 100 || preg_match('/^(done|complete|completed|finished|closed)$/i', trim((string)$t['status_label']));
            $due = substr((string)$t['due_date'], 0, 10);
            $out[] = ['key' => 'item:' . $t['id'], 'title' => $t['title'],
                      'sub' => trim(($t['project_name'] ? $t['project_name'] . ' · ' : '') . $t['board_title']),
                      'start' => $due, 'end' => $due,
                      'url' => '/projects/board.php?board_id=' . (int)$t['board_id'] . '&item=' . (int)$t['id'],
                      'status' => $done ? 'done' : ($due < $today ? 'overdue' : null)];
        }
        return $out;
    }

    private function invoices(string $from, string $to, string $today): array
    {
        $soft = ($this->hasColumn('invoices', 'deleted_at') ? ' AND i.deleted_at IS NULL' : '')
              . ($this->hasColumn('invoices', 'archived_at') ? ' AND i.archived_at IS NULL' : '');
        $st = $this->db->prepare("SELECT i.id, i.invoice_number, i.due_date, i.status, i.total, i.currency, a.name AS customer
                                    FROM invoices i LEFT JOIN crm_accounts a ON a.id = i.customer_id
                                   WHERE i.company_id = ? AND i.status IN ('sent','viewed','part-paid','overdue')
                                     AND i.due_date BETWEEN ? AND ?$soft
                                ORDER BY i.due_date LIMIT 500");
        $st->execute([$this->repo->companyId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $due = substr((string)$i['due_date'], 0, 10);
            $out[] = ['key' => 'invoice:' . $i['id'], 'title' => $i['invoice_number'] . ' due',
                      'sub' => trim(($i['customer'] ?: '') . ' · ' . self::money($i['total'], $i['currency']), ' ·'),
                      'start' => $due, 'end' => $due, 'url' => '/qi/invoice_view.php?id=' . (int)$i['id'],
                      'status' => ($due < $today || $i['status'] === 'overdue') ? 'overdue' : null];
        }
        return $out;
    }

    private function quotes(string $from, string $to, string $today): array
    {
        $soft = $this->hasColumn('quotes', 'deleted_at') ? ' AND q.deleted_at IS NULL' : '';
        $st = $this->db->prepare("SELECT q.id, q.quote_number, q.expiry_date, q.status, q.total, q.currency, a.name AS customer
                                    FROM quotes q LEFT JOIN crm_accounts a ON a.id = q.customer_id
                                   WHERE q.company_id = ? AND q.status IN ('sent','viewed') AND q.expiry_date BETWEEN ? AND ?$soft
                                ORDER BY q.expiry_date LIMIT 500");
        $st->execute([$this->repo->companyId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $day = substr((string)$q['expiry_date'], 0, 10);
            $out[] = ['key' => 'quote:' . $q['id'], 'title' => $q['quote_number'] . ' expires',
                      'sub' => trim(($q['customer'] ?: '') . ' · ' . self::money($q['total'], $q['currency']), ' ·'),
                      'start' => $day, 'end' => $day, 'url' => '/qi/quote_view.php?id=' . (int)$q['id'],
                      'status' => $day < $today ? 'overdue' : null];
        }
        return $out;
    }

    private function deals(string $from, string $to, string $today): array
    {
        $st = $this->db->prepare("SELECT o.id, o.title, o.close_date, o.amount, o.stage, a.name AS account
                                    FROM crm_opportunities o LEFT JOIN crm_accounts a ON a.id = o.account_id
                                   WHERE o.company_id = ? AND o.owner_id = ? AND o.stage NOT IN ('won','lost')
                                     AND o.close_date BETWEEN ? AND ?
                                ORDER BY o.close_date LIMIT 500");
        $st->execute([$this->repo->companyId, $this->repo->userId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $day = substr((string)$o['close_date'], 0, 10);
            $out[] = ['key' => 'deal:' . $o['id'], 'title' => $o['title'] . ' closes',
                      'sub' => trim(($o['account'] ?: '') . ' · ' . self::money($o['amount'], null) . ' · ' . ucfirst($o['stage']), ' ·'),
                      'start' => $day, 'end' => $day, 'url' => '/crm/opp_view.php?opp_id=' . (int)$o['id'],
                      'status' => $day < $today ? 'overdue' : null];
        }
        return $out;
    }

    private function payroll(string $from, string $to, string $today): array
    {
        $st = $this->db->prepare("SELECT id, name, run_number, pay_date, status FROM pay_runs
                                   WHERE company_id = ? AND pay_date BETWEEN ? AND ? ORDER BY pay_date LIMIT 200");
        $st->execute([$this->repo->companyId, $from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $day = substr((string)$r['pay_date'], 0, 10);
            $done = in_array($r['status'], ['locked', 'posted'], true);
            $out[] = ['key' => 'payrun:' . $r['id'], 'title' => 'Pay day · ' . $r['name'],
                      'sub' => $r['run_number'] . ' · ' . ucfirst($r['status']),
                      'start' => $day, 'end' => $day, 'url' => '/payroll/run_view.php?id=' . (int)$r['id'],
                      'status' => $done ? 'done' : null];
        }
        return $out;
    }

    /** "R 12 500.00" (ZAR) or "USD 1 200.00". */
    public static function money($amount, ?string $currency): string
    {
        $cur = strtoupper((string)$currency);
        $sym = ($cur === '' || $cur === 'ZAR') ? 'R' : $cur;
        return $sym . ' ' . number_format((float)$amount, 2, '.', ' ');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $k = $table . '.' . $column;
        if (!array_key_exists($k, self::$columns)) {
            $st = $this->db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            self::$columns[$k] = (bool)$st->fetchColumn();
        }
        return self::$columns[$k];
    }
}
