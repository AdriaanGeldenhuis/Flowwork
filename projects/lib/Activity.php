<?php
// projects/lib/Activity.php
//
// The activity log (board_audit_log): every change to a board, an item or a
// project, who made it and when. It feeds the activity panels, the item
// history and the live-update cursor (changes.php reads rows after an id).
// Project-level rows have no board; their details carry project_id.

final class Activity
{
    /** Never throws — a failed log line must not undo the change it describes. */
    public static function log(PDO $db, int $companyId, int $userId, ?int $boardId, ?int $itemId, string $action, array $details = []): ?int
    {
        try {
            $db->prepare("INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $boardId ?: null, $itemId ?: null, $userId, mb_substr($action, 0, 100),
                          json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                          mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[projects] activity ' . $action . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Short text for the log ('' stays ''), so long notes do not bloat it. */
    public static function clip(?string $s, int $max = 140): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    public static function cursor(PDO $db, int $boardId): int
    {
        $st = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM board_audit_log WHERE board_id = ?");
        $st->execute([$boardId]);
        return (int)$st->fetchColumn();
    }

    /** Rows for a board, an item or a project (newest first). */
    public static function feed(ProjectRepo $repo, array $scope, int $limit = 50, int $before = 0): array
    {
        $limit = max(1, min(200, $limit));
        $where = ['l.company_id = ?'];
        $args = [$repo->companyId];
        if (!empty($scope['item_id'])) {
            $where[] = 'l.item_id = ?';
            $args[] = (int)$scope['item_id'];
        } elseif (!empty($scope['board_id'])) {
            $where[] = 'l.board_id = ?';
            $args[] = (int)$scope['board_id'];
        } elseif (!empty($scope['project_id'])) {
            $pid = (int)$scope['project_id'];
            if ($repo->projectLevel($pid) >= 1) {
                $where[] = "(l.board_id IN (SELECT board_id FROM project_boards WHERE project_id = ? AND company_id = ?)
                             OR (l.board_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.project_id')) = ?))";
                array_push($args, $pid, $repo->companyId, (string)$pid);
            } else {
                // Board members who are not on the project: only the boards they can open.
                $ids = array_map(fn($b) => (int)$b['id'], $repo->boardsOf($pid, true));
                $where[] = 'l.board_id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
            }
        } else {
            return [];
        }
        if ($before > 0) { $where[] = 'l.id < ?'; $args[] = $before; }
        $st = $repo->db->prepare("SELECT l.id, l.board_id, l.item_id, l.user_id, l.action, l.details, l.created_at,
                                         b.title AS board_title, i.title AS item_title
                                    FROM board_audit_log l
                               LEFT JOIN project_boards b ON b.board_id = l.board_id
                               LEFT JOIN board_items i ON i.id = l.item_id
                                   WHERE " . implode(' AND ', $where) . "
                                ORDER BY l.id DESC LIMIT $limit");
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['details'], true);
            $out[] = ['id' => (int)$r['id'], 'board_id' => $r['board_id'] ? (int)$r['board_id'] : null, 'item_id' => $r['item_id'] ? (int)$r['item_id'] : null,
                      'user_id' => (int)$r['user_id'], 'user' => $repo->personName((int)$r['user_id']), 'action' => $r['action'],
                      'details' => is_array($d) ? $d : [], 'at' => $r['created_at'],
                      'board' => $r['board_title'], 'item' => $r['item_title'] ?? (is_array($d) ? ($d['title'] ?? null) : null)];
        }
        return $out;
    }
}
