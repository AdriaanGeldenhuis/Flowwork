<?php
// crm/lib/CrmTasks.php
//
// Follow-ups and to-dos (crm_tasks), on an account, a deal and/or a contact,
// assigned to someone, with an optional reminder (sent by CrmNotify from the
// background tick as a bell + phone notification). They also show on the
// assignee's calendar (CalendarSources 'followups').

require_once __DIR__ . '/Crm.php';

final class CrmTasks
{
    public const REMIND = ['none' => null, 'at' => 0, '15m' => 15, '1h' => 60, '1d' => 1440];

    private const FROM = " FROM crm_tasks t
                         LEFT JOIN crm_accounts a ON a.id = t.account_id AND a.company_id = t.company_id
                         LEFT JOIN crm_opportunities o ON o.id = t.opportunity_id AND o.company_id = t.company_id
                         LEFT JOIN crm_contacts c ON c.id = t.contact_id AND c.company_id = t.company_id";
    private const SELECT = "SELECT t.*, a.name AS account_name, a.type AS account_type, o.title AS deal_title,
                                   CONCAT_WS(' ', c.first_name, c.last_name) AS contact_name" . self::FROM;

    public static function shape(PDO $db, int $companyId, array $t): array
    {
        $due = $t['due_at'];
        $state = 'open';
        if ($t['done_at']) $state = 'done';
        elseif ($due) {
            $today = date('Y-m-d');
            $d = substr((string)$due, 0, 10);
            if ($d < $today || (!(int)$t['due_all_day'] && (string)$due < date('Y-m-d H:i:s'))) $state = 'overdue';
            elseif ($d === $today) $state = 'today';
        }
        return [
            'id' => (int)$t['id'], 'title' => (string)$t['title'], 'notes' => (string)($t['notes'] ?? ''),
            'due_at' => $due, 'all_day' => (bool)$t['due_all_day'], 'priority' => (string)$t['priority'], 'state' => $state,
            'done' => (bool)$t['done_at'], 'done_at' => $t['done_at'], 'done_by' => $t['done_by'] ? Crm::personName($db, $companyId, (int)$t['done_by']) : '',
            'assigned_to' => $t['assigned_to'] !== null ? (int)$t['assigned_to'] : null,
            'assignee' => $t['assigned_to'] ? Crm::personName($db, $companyId, (int)$t['assigned_to']) : '',
            'account' => $t['account_id'] ? ['id' => (int)$t['account_id'], 'name' => (string)($t['account_name'] ?? ''), 'type' => (string)($t['account_type'] ?? '')] : null,
            'deal' => $t['opportunity_id'] ? ['id' => (int)$t['opportunity_id'], 'title' => (string)($t['deal_title'] ?? '')] : null,
            'contact' => $t['contact_id'] ? ['id' => (int)$t['contact_id'], 'name' => trim((string)($t['contact_name'] ?? ''))] : null,
            'remind' => self::remindKey($t), 'created_by' => Crm::personName($db, $companyId, (int)$t['created_by']), 'created_at' => $t['created_at'],
        ];
    }

    private static function remindKey(array $t): string
    {
        if (!$t['remind_at'] || !$t['due_at']) return 'none';
        $mins = (int)round((strtotime((string)$t['due_at']) - strtotime((string)$t['remind_at'])) / 60);
        foreach (self::REMIND as $k => $m) if ($m !== null && $m === $mins) return $k;
        return 'at';
    }

    /**
     * $p: scope (mine|all|user id), when (open|overdue|today|week|later|nodate|done), account, deal, q, limit
     */
    public static function list(PDO $db, int $companyId, int $userId, array $p): array
    {
        $where = " WHERE t.company_id = ? AND (t.account_id IS NULL OR a.deleted_at IS NULL)";
        $params = [$companyId];
        $scope = (string)($p['scope'] ?? 'mine');
        if ($scope === 'mine') { $where .= " AND t.assigned_to = ?"; $params[] = $userId; }
        elseif ((int)$scope > 0) { $where .= " AND t.assigned_to = ?"; $params[] = (int)$scope; }
        if ((int)($p['account'] ?? 0) > 0) { $where .= " AND t.account_id = ?"; $params[] = (int)$p['account']; }
        if ((int)($p['deal'] ?? 0) > 0) { $where .= " AND t.opportunity_id = ?"; $params[] = (int)$p['deal']; }
        $q = mb_substr(trim((string)($p['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $like = '%' . Crm::likeEscape($q) . '%';
            $where .= " AND (t.title LIKE ? OR t.notes LIKE ? OR a.name LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $weekEnd = date('Y-m-d', strtotime('+7 days'));
        $conds = [
            'open'    => "t.done_at IS NULL",
            'overdue' => "t.done_at IS NULL AND t.due_at IS NOT NULL AND (DATE(t.due_at) < '$today' OR (t.due_all_day = 0 AND t.due_at < '$now'))",
            'today'   => "t.done_at IS NULL AND DATE(t.due_at) = '$today' AND (t.due_all_day = 1 OR t.due_at >= '$now')",
            'week'    => "t.done_at IS NULL AND DATE(t.due_at) > '$today' AND DATE(t.due_at) <= '$weekEnd'",
            'later'   => "t.done_at IS NULL AND DATE(t.due_at) > '$weekEnd'",
            'nodate'  => "t.done_at IS NULL AND t.due_at IS NULL",
            'done'    => "t.done_at IS NOT NULL",
            'all'     => "1=1",
        ];
        // counts use the same filters (scope, account, q) without the "when"
        $counts = [];
        $st = $db->prepare("SELECT " . implode(', ', array_map(fn($k, $c) => "SUM($c) AS `$k`", array_keys($conds), $conds)) . self::FROM . $where);
        $st->execute($params);
        foreach (($st->fetch(PDO::FETCH_ASSOC) ?: []) as $k => $v) $counts[$k] = (int)$v;
        $when = isset($conds[$p['when'] ?? '']) ? $p['when'] : 'open';
        $order = $when === 'done' ? 't.done_at DESC' : "(t.due_at IS NULL), t.due_at, FIELD(t.priority, 'high','normal','low'), t.id";
        $limit = max(1, min(500, (int)($p['limit'] ?? 300)));
        $st = $db->prepare(self::SELECT . $where . " AND " . $conds[$when] . " ORDER BY $order LIMIT $limit");
        $st->execute($params);
        return ['rows' => array_map(fn($t) => self::shape($db, $companyId, $t), $st->fetchAll(PDO::FETCH_ASSOC)), 'counts' => $counts];
    }

    public static function get(PDO $db, int $companyId, int $id): ?array
    {
        $st = $db->prepare(self::SELECT . " WHERE t.id = ? AND t.company_id = ?");
        $st->execute([$id, $companyId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        return $t ? self::shape($db, $companyId, $t) : null;
    }

    /** Create or update. $in: id, title, notes, due_date, due_time, priority, assigned_to, account_id, opportunity_id, contact_id, remind */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM crm_tasks WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new CrmUserError('Task not found.');
            if (!self::canEdit($cur, $userId, $role)) throw new CrmForbidden('Only the person it is assigned to, its creator or an admin can change this task.');
        }
        $has = fn($k) => array_key_exists($k, $in);
        $v = [];
        if (!$cur || $has('title')) {
            $t = mb_substr(trim((string)($in['title'] ?? '')), 0, 190);
            if ($t === '') throw new CrmUserError('Say what needs doing.');
            $v['title'] = $t;
        }
        if (!$cur || $has('notes')) $v['notes'] = mb_substr(trim((string)($in['notes'] ?? '')), 0, 5000) ?: null;
        if (!$cur || $has('priority')) $v['priority'] = in_array($in['priority'] ?? '', ['low', 'normal', 'high'], true) ? $in['priority'] : 'normal';
        if (!$cur || $has('due_date') || $has('due_time')) {
            $date = trim((string)($in['due_date'] ?? ''));
            $time = trim((string)($in['due_time'] ?? ''));
            if ($date === '') {
                $v['due_at'] = null;
                $v['due_all_day'] = 0;
            } else {
                if (!Crm::ymd($date)) throw new CrmUserError('The due date is not a valid date.');
                if ($time !== '') {
                    $dt = Crm::datetime($date . ' ' . $time);
                    if ($dt === null) throw new CrmUserError('The time is not valid.');
                    $v['due_at'] = $dt;
                    $v['due_all_day'] = 0;
                } else {
                    $v['due_at'] = $date . ' 09:00:00';
                    $v['due_all_day'] = 1;
                }
            }
        }
        if (!$cur || $has('assigned_to')) {
            $a = (int)($in['assigned_to'] ?? 0) ?: $userId;
            if (!isset(Crm::people($db, $companyId)[$a])) throw new CrmUserError('Assign the task to someone in your company.');
            $v['assigned_to'] = $a;
        }
        // Links (each must belong to this company; the contact and deal follow the account)
        $accountId = $has('account_id') ? (int)$in['account_id'] : (int)($cur['account_id'] ?? 0);
        $dealId = $has('opportunity_id') ? (int)$in['opportunity_id'] : (int)($cur['opportunity_id'] ?? 0);
        $contactId = $has('contact_id') ? (int)$in['contact_id'] : (int)($cur['contact_id'] ?? 0);
        if ($dealId > 0) {
            $st = $db->prepare("SELECT account_id FROM crm_opportunities WHERE id = ? AND company_id = ?");
            $st->execute([$dealId, $companyId]);
            $acc = $st->fetchColumn();
            if (!$acc) throw new CrmUserError('Deal not found.');
            $accountId = (int)$acc;
        }
        if ($accountId > 0) {
            $st = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
            $st->execute([$accountId, $companyId]);
            if (!$st->fetchColumn()) throw new CrmUserError('Account not found.');
        }
        if ($contactId > 0) {
            $st = $db->prepare("SELECT account_id FROM crm_contacts WHERE id = ? AND company_id = ?");
            $st->execute([$contactId, $companyId]);
            $ca = (int)$st->fetchColumn();
            if (!$ca || ($accountId > 0 && $ca !== $accountId)) $contactId = 0;
            elseif ($accountId === 0) $accountId = $ca;
        }
        if (!$cur || $has('account_id') || $has('opportunity_id') || $has('contact_id')) {
            $v['account_id'] = $accountId ?: null;
            $v['opportunity_id'] = $dealId ?: null;
            $v['contact_id'] = $contactId ?: null;
        }
        // Reminder
        $due = array_key_exists('due_at', $v) ? $v['due_at'] : ($cur['due_at'] ?? null);
        $allDay = array_key_exists('due_all_day', $v) ? (int)$v['due_all_day'] : (int)($cur['due_all_day'] ?? 0);
        if (!$cur || $has('remind') || array_key_exists('due_at', $v)) {
            $key = $has('remind') ? (string)$in['remind'] : ($cur ? self::remindKey($cur) : 'none');
            $mins = self::REMIND[$key] ?? null;
            $remindAt = null;
            if ($due && $mins !== null) {
                // all-day tasks remind at 08:00 on the day (or the day before for '1d')
                $base = $allDay ? substr((string)$due, 0, 10) . ' 08:00:00' : (string)$due;
                $remindAt = date('Y-m-d H:i:s', strtotime($base) - ($allDay && $mins < 1440 ? 0 : $mins * 60));
            }
            $v['remind_at'] = $remindAt;
            if (!$cur || $remindAt !== $cur['remind_at']) $v['reminded_at'] = null;
        }

        if ($cur) {
            if ($v) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
                $db->prepare("UPDATE crm_tasks SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute(array_merge(array_values($v), [$id, $companyId]));
            }
        } else {
            $v += ['company_id' => $companyId, 'created_by' => $userId];
            $cols = array_keys($v);
            $db->prepare("INSERT INTO crm_tasks (`" . implode('`, `', $cols) . "`, created_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())")
               ->execute(array_values($v));
            $id = (int)$db->lastInsertId();
        }
        $task = self::get($db, $companyId, $id);
        $assignee = (int)($v['assigned_to'] ?? 0);
        if ($assignee > 0 && $assignee !== $userId && (!$cur || $assignee !== (int)$cur['assigned_to'])) {
            require_once __DIR__ . '/CrmAccounts.php';
            CrmAccounts::notify($db, $companyId, $assignee, 'crm_task_assigned', Crm::personName($db, $companyId, $userId) . ' gave you a task: ' . $task['title'],
                                trim(($task['account']['name'] ?? '') . ($task['due_at'] ? ' · due ' . date($task['all_day'] ? 'j M' : 'j M H:i', strtotime($task['due_at'])) : ''), ' ·'),
                                self::url($task));
        }
        return $task;
    }

    public static function canEdit(array $t, int $userId, string $role): bool
    {
        return Crm::isAdmin($role) || (int)$t['assigned_to'] === $userId || (int)$t['created_by'] === $userId || !$t['assigned_to'];
    }

    public static function setDone(PDO $db, int $companyId, int $userId, string $role, int $id, bool $done): array
    {
        $st = $db->prepare("SELECT * FROM crm_tasks WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new CrmUserError('Task not found.');
        if (!self::canEdit($t, $userId, $role)) throw new CrmForbidden('Only the person it is assigned to, its creator or an admin can tick this task.');
        $db->prepare("UPDATE crm_tasks SET done_at = ?, done_by = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
           ->execute([$done ? date('Y-m-d H:i:s') : null, $done ? $userId : null, $id, $companyId]);
        return self::get($db, $companyId, $id);
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        $st = $db->prepare("SELECT * FROM crm_tasks WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new CrmUserError('Task not found.');
        if (!self::canEdit($t, $userId, $role)) throw new CrmForbidden('Only the person it is assigned to, its creator or an admin can delete this task.');
        $db->prepare("DELETE FROM crm_tasks WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
    }

    public static function url(array $task): string
    {
        if (!empty($task['deal']['id'])) return '/crm/opp_view.php?id=' . (int)$task['deal']['id'];
        if (!empty($task['account']['id'])) return '/crm/account_view.php?id=' . (int)$task['account']['id'] . '#tasks';
        return '/crm/#tasks';
    }

    /** Open tasks in [from, to] for the calendar (assigned to $userId). */
    public static function forCalendar(PDO $db, int $companyId, int $userId, string $from, string $to): array
    {
        $st = $db->prepare(self::SELECT . " WHERE t.company_id = ? AND t.assigned_to = ? AND t.done_at IS NULL AND t.due_at IS NOT NULL
                                              AND DATE(t.due_at) BETWEEN ? AND ? AND (t.account_id IS NULL OR a.deleted_at IS NULL)
                                         ORDER BY t.due_at LIMIT 500");
        $st->execute([$companyId, $userId, $from, $to]);
        return array_map(fn($t) => self::shape($db, $companyId, $t), $st->fetchAll(PDO::FETCH_ASSOC));
    }
}
