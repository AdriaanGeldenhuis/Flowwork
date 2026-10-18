<?php
// projects/lib/ProjectNotify.php
//
// Project notifications — the bell and the phone (FlowWork › Projects /
// Mentions) through fw_notify():
//   project_assigned  someone put you on an item (people column or subitem)
//   mention           someone @mentioned you in an update
//   project_update    an update or reply on an item you are on or watching
//   project_added     you were added to a project
//   project_due       an item you are on is due today / is overdue (daily job)
//   project_auto      a board automation's "notify" action
// Nobody is notified about their own actions.

final class ProjectNotify
{
    private static function send(PDO $db, int $companyId, int $userId, string $type, string $title, string $message, string $url, array $push = []): void
    {
        if (!function_exists('fw_notify')) require_once ProjectPaths::root() . '/includes/notify.php';
        try {
            fw_notify($db, $companyId, $userId, $type, $title, $message, $url, $push + ['ch' => 'projects']);
        } catch (Throwable $e) {
            error_log('[projects] notify ' . $type . ': ' . $e->getMessage());
        }
    }

    private static function item(ProjectRepo $repo, int $itemId): ?array
    {
        $st = $repo->db->prepare("SELECT i.id, i.title, i.board_id, i.created_by, b.title AS board, p.name AS project
                                    FROM board_items i JOIN project_boards b ON b.board_id = i.board_id
                                    JOIN projects p ON p.project_id = b.project_id
                                   WHERE i.id = ? AND i.company_id = ?");
        $st->execute([$itemId, $repo->companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Only people who can still open the board hear about it. */
    private static function canOpen(ProjectRepo $repo, int $userId, int $boardId): bool
    {
        try {
            return ProjectRepo::forUser($repo->db, $repo->companyId, $userId)->boardLevel($boardId) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function excerpt(string $body, int $max = 140): string
    {
        $t = preg_replace('/@\[([^\]]+)\]\(\d+\)/u', '@$1', $body);
        return Activity::clip($t, $max);
    }

    public static function assigned(ProjectRepo $repo, int $itemId, array $userIds): void
    {
        $it = self::item($repo, $itemId);
        if (!$it) return;
        $me = $repo->personName($repo->userId) ?: 'Someone';
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0 || $uid === $repo->userId || !self::canOpen($repo, $uid, (int)$it['board_id'])) continue;
            self::send($repo->db, $repo->companyId, $uid, 'project_assigned', $me . ' assigned you', $it['title'] . ' · ' . $it['board'] . ' · ' . $it['project'],
                       ProjectLink::itemUrl((int)$it['board_id'], $itemId), ['who' => $me, 'sub' => $it['project']]);
        }
    }

    public static function subitemAssigned(ProjectRepo $repo, int $itemId, int $userId, string $subTitle): void
    {
        $it = self::item($repo, $itemId);
        if (!$it || $userId === $repo->userId || !self::canOpen($repo, $userId, (int)$it['board_id'])) return;
        $me = $repo->personName($repo->userId) ?: 'Someone';
        self::send($repo->db, $repo->companyId, $userId, 'project_assigned', $me . ' gave you a subitem', $subTitle . ' — on ' . $it['title'] . ' · ' . $it['board'],
                   ProjectLink::itemUrl((int)$it['board_id'], $itemId), ['who' => $me, 'sub' => $it['project']]);
    }

    /** A new update: @mentions, then everyone on or watching the item. */
    public static function update(ProjectRepo $repo, array $item, int $commentId, string $body, array $mentioned, ?int $parentId): void
    {
        $it = self::item($repo, (int)$item['id']);
        if (!$it) return;
        $me = $repo->personName($repo->userId) ?: 'Someone';
        $url = ProjectLink::itemUrl((int)$it['board_id'], (int)$it['id'], 'updates');
        $text = self::excerpt($body);
        $told = [$repo->userId => true];
        foreach ($mentioned as $uid) {
            if (isset($told[$uid]) || !self::canOpen($repo, $uid, (int)$it['board_id'])) continue;
            $told[$uid] = true;
            self::send($repo->db, $repo->companyId, $uid, 'mention', $me . ' mentioned you', '“' . $it['title'] . '”: ' . $text, $url,
                       ['who' => $me, 'sub' => $it['board'], 'grp' => 'projects']);
        }
        $st = $repo->db->prepare("SELECT user_id FROM board_item_people WHERE item_id = ? UNION SELECT user_id FROM board_watchers WHERE item_id = ?");
        $st->execute([(int)$it['id'], (int)$it['id']]);
        $audience = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $audience[] = (int)$it['created_by'];
        if ($parentId) {
            $st = $repo->db->prepare("SELECT user_id FROM board_item_comments WHERE id = ? OR parent_id = ?");
            $st->execute([$parentId, $parentId]);
            $audience = array_merge($audience, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
        foreach (array_unique($audience) as $uid) {
            if ($uid <= 0 || isset($told[$uid]) || !self::canOpen($repo, $uid, (int)$it['board_id'])) continue;
            $told[$uid] = true;
            self::send($repo->db, $repo->companyId, $uid, 'project_update', $me . ($parentId ? ' replied on ' : ' posted on ') . $it['title'], $text, $url,
                       ['who' => $me, 'sub' => $it['board']]);
        }
    }

    public static function addedToProject(ProjectRepo $repo, int $projectId, int $userId, string $role = 'member'): void
    {
        $p = $repo->projectRow($projectId);
        if (!$p || $userId === $repo->userId) return;
        $me = $repo->personName($repo->userId) ?: 'Someone';
        $as = ['owner' => 'an owner', 'manager' => 'a manager', 'viewer' => 'a viewer'][$role] ?? 'a member';
        self::send($repo->db, $repo->companyId, $userId, 'project_added', $me . ' added you to ' . $p['name'], 'You are ' . $as . ' of the project.',
                   ProjectLink::projectUrl($projectId), ['who' => $me]);
    }

    /** Automation "notify" action. */
    public static function automation(ProjectRepo $repo, array $item, array $cfg, string $ruleName): void
    {
        $it = self::item($repo, (int)$item['id']);
        if (!$it) return;
        $to = $cfg['to'] ?? 'people';
        if ($to === 'creator') $users = [(int)$it['created_by']];
        elseif (is_array($to)) $users = array_map('intval', $to);
        else {
            $st = $repo->db->prepare("SELECT user_id FROM board_item_people WHERE item_id = ?");
            $st->execute([(int)$it['id']]);
            $users = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $msg = trim((string)($cfg['message'] ?? '')) ?: $ruleName;
        foreach (array_unique($users) as $uid) {
            if ($uid <= 0 || !self::canOpen($repo, $uid, (int)$it['board_id'])) continue;
            self::send($repo->db, $repo->companyId, $uid, 'project_auto', $it['title'], $msg . ' · ' . $it['board'], ProjectLink::itemUrl((int)$it['board_id'], (int)$it['id']));
        }
    }

    /**
     * Daily reminders (from the tick / cron): items due today, and items that
     * became overdue yesterday, to everyone on them — once per item per day,
     * after 07:00. Done items and archived boards are skipped, as are boards
     * whose due-date column has reminders switched off.
     * @return int notifications sent
     */
    public static function sendDueReminders(PDO $db, int $limit = 400): int
    {
        if ((int)date('G') < 7) return 0;
        ProjectSchema::ensure($db);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $st = $db->prepare("SELECT i.id, i.title, i.board_id, i.company_id, i.due_date, b.title AS board, p.name AS project
                              FROM board_items i
                              JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                              JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                             WHERE i.due_date IN (?, ?) AND i.progress < 100 AND (i.archived = 0 OR i.archived IS NULL)
                               AND (b.archived = 0 OR b.archived IS NULL) AND p.status NOT IN ('completed', 'cancelled', 'archived')
                               AND (i.reminded_on IS NULL OR i.reminded_on < ?)
                          ORDER BY i.due_date DESC LIMIT " . (int)$limit);
        $st->execute([$today, $yesterday, $today]);
        $sent = 0;
        $boardCols = [];
        $mark = $db->prepare("UPDATE board_items SET reminded_on = ?, updated_at = updated_at WHERE id = ?");
        $people = $db->prepare("SELECT user_id FROM board_item_people WHERE item_id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $mark->execute([$today, (int)$it['id']]);
            $bid = (int)$it['board_id'];
            if (!isset($boardCols[$bid])) {
                $c = $db->prepare("SELECT * FROM board_columns WHERE board_id = ? ORDER BY position, column_id");
                $c->execute([$bid]);
                $date = Columns::primaryDateColumn($c->fetchAll(PDO::FETCH_ASSOC));
                $boardCols[$bid] = $date ? !empty(Columns::config($date)['reminders']) : true;
            }
            if (!$boardCols[$bid]) continue;
            $people->execute([(int)$it['id']]);
            $due = $it['due_date'] === $today;
            foreach (array_map('intval', $people->fetchAll(PDO::FETCH_COLUMN)) as $uid) {
                self::send($db, (int)$it['company_id'], $uid, 'project_due', ($due ? 'Due today: ' : 'Overdue: ') . $it['title'],
                           $it['board'] . ' · ' . $it['project'], ProjectLink::itemUrl($bid, (int)$it['id']), ['sub' => $it['project']]);
                $sent++;
            }
        }
        return $sent;
    }
}
