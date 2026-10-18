<?php
// projects/lib/Automations.php
//
// Board automations: "When <trigger>, then <action>" recipes stored in
// board_automations. They run right after the change that triggers them, as
// the person who made that change (whose access was already checked), up to
// three levels deep so recipes cannot loop forever. Each run is logged in
// board_automation_logs.
//
// Triggers (trigger_type / trigger_config):
//   status_changed   {column_id, to: label key | null for any}
//   column_changed   {column_id, to: value | null for any}
//   person_assigned  {column_id}
//   item_created     {group_id | null}
//   item_moved       {group_id}
// Actions (action_type / action_config):
//   move_to_group    {group_id}
//   set_value        {column_id, value}            (status, priority, dropdown, checkbox, text …)
//   assign           {column_id, user_id | 'creator' | 'actor'}
//   set_date         {column_id, days}             (today + days)
//   clear            {column_id}
//   notify           {to: 'people' | 'creator' | [user ids], message}
//   archive          {}

final class Automations
{
    public const TRIGGERS = ['status_changed', 'column_changed', 'person_assigned', 'item_created', 'item_moved'];
    public const ACTIONS = ['move_to_group', 'set_value', 'assign', 'set_date', 'clear', 'notify', 'archive'];

    /** Recipes for the "Add automation" gallery: [trigger, trigger cfg hint, action, action cfg hint, title]. */
    public const RECIPES = [
        ['status_changed', 'move_to_group', 'When status changes to something, move the item to a group'],
        ['status_changed', 'notify', 'When status changes to something, notify someone'],
        ['person_assigned', 'set_value', 'When someone is assigned, set a status'],
        ['item_created', 'assign', 'When an item is created, assign the person who created it'],
        ['item_created', 'set_date', 'When an item is created, set a due date a few days out'],
        ['status_changed', 'set_date', 'When status changes to something, stamp today’s date'],
        ['item_moved', 'set_value', 'When an item moves to a group, set a status'],
        ['status_changed', 'archive', 'When status changes to something, archive the item'],
        ['column_changed', 'notify', 'When a column changes, notify someone'],
    ];

    /** A board's active recipes, loaded once per request (forget() after they change). */
    private static array $rules = [];
    /**
     * One change and everything its recipes set off (a "chain"): how deep we are,
     * how many actions ran and which recipe already ran on which item. A recipe
     * runs at most once per item in a chain, and a chain stops after MAX_ACTIONS.
     */
    private static int $level = 0;
    private static int $actions = 0;
    private static array $seen = [];
    private const MAX_ACTIONS = 100;
    /** A bulk edit starts a chain per item: all chains of one request stop after MAX_TOTAL. */
    private static int $total = 0;
    private const MAX_TOTAL = 5000;

    public static function forget(int $boardId): void
    {
        unset(self::$rules[$boardId]);
    }

    private static function rulesOf(PDO $db, int $boardId): array
    {
        if (!isset(self::$rules[$boardId])) {
            $st = $db->prepare("SELECT * FROM board_automations WHERE board_id = ? AND is_active = 1 ORDER BY id");
            $st->execute([$boardId]);
            self::$rules[$boardId] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return self::$rules[$boardId];
    }

    /**
     * Run the recipes an event matches. @return int[] items the recipes changed
     * @param array $ctx event details: column_id, type, old, new (value_changed) / group_id; board_id when known
     */
    public static function fire(ProjectRepo $repo, string $event, int $itemId, array $ctx = []): array
    {
        try {
            // Most boards have no recipes: skip the item lookup when the caller knows the board.
            if (!empty($ctx['board_id']) && !self::rulesOf($repo->db, (int)$ctx['board_id'])) return [];
            $st = $repo->db->prepare("SELECT i.*, b.company_id AS b_company FROM board_items i JOIN project_boards b ON b.board_id = i.board_id
                                       WHERE i.id = ? AND i.company_id = ?");
            $st->execute([$itemId, $repo->companyId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!$item) return [];
            $rules = self::rulesOf($repo->db, (int)$item['board_id']);
        } catch (Throwable $e) {
            error_log('[projects] automations load: ' . $e->getMessage());
            return [];
        }
        if (!$rules) return [];
        if (self::$level === 0) { self::$actions = 0; self::$seen = []; }
        self::$level++;
        $touched = [];
        try {
            foreach ($rules as $r) {
                $tc = json_decode((string)$r['trigger_config'], true) ?: [];
                if (!self::matches($r['trigger_type'], $tc, $event, $ctx)) continue;
                $key = (int)$r['id'] . ':' . $itemId;
                if (isset(self::$seen[$key])) continue; // already ran on this item in this chain: no loops
                self::$seen[$key] = true;
                $ac = json_decode((string)$r['action_config'], true) ?: [];
                $ok = true;
                $err = null;
                $stop = null;
                if (++self::$actions > self::MAX_ACTIONS) $stop = 'Automation limit reached for this change (' . self::MAX_ACTIONS . ' actions).';
                elseif (++self::$total > self::MAX_TOTAL) $stop = 'Automation limit reached for this request (' . self::MAX_TOTAL . ' actions).';
                if ($stop) {
                    $ok = false;
                    $err = $stop;
                } else {
                    try {
                        self::act($repo, $r, $ac, $item);
                        $touched[] = $itemId;
                    } catch (Throwable $e) {
                        $ok = false;
                        $err = mb_substr($e->getMessage(), 0, 500);
                        if (!($e instanceof ProjectUserError)) error_log('[projects] automation ' . $r['id'] . ': ' . $e->getMessage());
                    }
                }
                try {
                    $repo->db->prepare("INSERT INTO board_automation_logs (automation_id, item_id, executed_at, success, error_message) VALUES (?, ?, NOW(), ?, ?)")
                        ->execute([(int)$r['id'], $itemId, $ok ? 1 : 0, $err]);
                } catch (Throwable $e) { /* logging is best effort */ }
                // An archived item takes no more actions; a chain over its limit takes none at all.
                if ($stop || ($ok && $r['action_type'] === 'archive')) break;
            }
        } finally {
            self::$level--;
        }
        return array_values(array_unique($touched));
    }

    private static function matches(string $type, array $tc, string $event, array $ctx): bool
    {
        switch ($type) {
            case 'status_changed':
            case 'column_changed':
                if ($event !== 'value_changed' || (int)($tc['column_id'] ?? 0) !== (int)($ctx['column_id'] ?? -1)) return false;
                if ($type === 'status_changed' && ($ctx['type'] ?? '') !== 'status') return false;
                $to = $tc['to'] ?? null;
                if ($to === null || $to === '') return ($ctx['new'] ?? null) !== null;
                if (($ctx['type'] ?? '') === 'checkbox') return ($ctx['new'] === '1') === (bool)$to;
                return strcasecmp((string)($ctx['new'] ?? ''), (string)$to) === 0;
            case 'person_assigned':
                if ($event !== 'value_changed' || ($ctx['type'] ?? '') !== 'people') return false;
                if (!empty($tc['column_id']) && (int)$tc['column_id'] !== (int)$ctx['column_id']) return false;
                $before = array_filter(array_map('intval', explode(',', (string)($ctx['old'] ?? ''))));
                $after = array_filter(array_map('intval', explode(',', (string)($ctx['new'] ?? ''))));
                return (bool)array_diff($after, $before);
            case 'item_created':
                return $event === 'item_created' && (empty($tc['group_id']) || (int)$tc['group_id'] === (int)($ctx['group_id'] ?? 0));
            case 'item_moved':
                return $event === 'item_moved' && (int)($tc['group_id'] ?? 0) === (int)($ctx['group_id'] ?? -1);
        }
        return false;
    }

    private static function act(ProjectRepo $repo, array $rule, array $ac, array $item): void
    {
        $items = new ItemRepo($repo);
        $boardId = (int)$item['board_id'];
        $iid = (int)$item['id'];
        switch ($rule['action_type']) {
            case 'move_to_group':
                $items->move($boardId, [$iid], (int)($ac['group_id'] ?? 0));
                return;
            case 'set_value':
                $items->automationSet($boardId, $iid, (int)($ac['column_id'] ?? 0), $ac['value'] ?? null);
                return;
            case 'clear':
                $items->automationSet($boardId, $iid, (int)($ac['column_id'] ?? 0), null);
                return;
            case 'assign':
                $who = $ac['user_id'] ?? 'actor';
                $uid = $who === 'creator' ? (int)$item['created_by'] : ($who === 'actor' ? $repo->userId : (int)$who);
                $cid = (int)($ac['column_id'] ?? 0);
                $st = $repo->db->prepare("SELECT value FROM board_item_values WHERE item_id = ? AND column_id = ?");
                $st->execute([$iid, $cid]);
                $cur = array_filter(array_map('intval', explode(',', (string)$st->fetchColumn())));
                if (!in_array($uid, $cur, true)) $items->automationSet($boardId, $iid, $cid, array_merge($cur, [$uid]));
                return;
            case 'set_date':
                $days = max(-3650, min(3650, (int)($ac['days'] ?? 0)));
                $items->automationSet($boardId, $iid, (int)($ac['column_id'] ?? 0), date('Y-m-d', strtotime(ProjectRepo::today() . ' ' . ($days >= 0 ? '+' : '') . $days . ' days')));
                return;
            case 'notify':
                ProjectNotify::automation($repo, $item, $ac, (string)$rule['name']);
                return;
            case 'archive':
                $items->archive($boardId, [$iid], true);
                return;
        }
        throw new ProjectUserError('Unknown automation action.');
    }

    // ================================================================= management

    public static function list(ProjectRepo $repo, int $boardId): array
    {
        $repo->requireBoard($boardId, 1);
        $st = $repo->db->prepare("SELECT a.*, (SELECT COUNT(*) FROM board_automation_logs l WHERE l.automation_id = a.id) AS runs,
                                         (SELECT MAX(executed_at) FROM board_automation_logs l WHERE l.automation_id = a.id) AS last_run,
                                         (SELECT COUNT(*) FROM board_automation_logs l WHERE l.automation_id = a.id AND l.success = 0) AS failures
                                    FROM board_automations a WHERE a.board_id = ? ORDER BY a.id");
        $st->execute([$boardId]);
        return array_map(fn($a) => [
            'id' => (int)$a['id'], 'name' => $a['name'], 'active' => (bool)$a['is_active'],
            'trigger' => $a['trigger_type'], 'trigger_config' => json_decode((string)$a['trigger_config'], true) ?: (object)[],
            'action' => $a['action_type'], 'action_config' => json_decode((string)$a['action_config'], true) ?: (object)[],
            'runs' => (int)$a['runs'], 'failures' => (int)$a['failures'], 'last_run' => $a['last_run'], 'by' => $repo->personName((int)$a['created_by']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Create or update a recipe after checking it refers to this board's columns and groups. */
    public static function save(ProjectRepo $repo, int $boardId, array $in): int
    {
        $repo->requireBoard($boardId, 3);
        $trigger = (string)($in['trigger'] ?? '');
        $action = (string)($in['action'] ?? '');
        if (!in_array($trigger, self::TRIGGERS, true) || !in_array($action, self::ACTIONS, true)) throw new ProjectUserError('Choose a trigger and an action.');
        $tc = is_array($in['trigger_config'] ?? null) ? $in['trigger_config'] : [];
        $ac = is_array($in['action_config'] ?? null) ? $in['action_config'] : [];
        $cols = [];
        foreach ((new BoardRepo($repo))->columnRows($boardId) as $c) $cols[(int)$c['column_id']] = $c;
        $st = $repo->db->prepare("SELECT id FROM board_groups WHERE board_id = ?");
        $st->execute([$boardId]);
        $groups = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $needCol = function (array $cfg, ?array $types = null) use ($cols): array {
            $c = $cols[(int)($cfg['column_id'] ?? 0)] ?? null;
            if (!$c || ($types && !in_array($c['type'], $types, true))) throw new ProjectUserError('Choose a column for this automation.');
            return $c;
        };
        $needGroup = function ($gid) use ($groups): int {
            if (!in_array((int)$gid, $groups, true)) throw new ProjectUserError('Choose a group for this automation.');
            return (int)$gid;
        };
        // Trigger config
        $t = [];
        switch ($trigger) {
            case 'status_changed':
                $c = $needCol($tc, ['status']);
                $t = ['column_id' => (int)$c['column_id'], 'to' => isset($tc['to']) && $tc['to'] !== '' ? (string)$tc['to'] : null];
                if ($t['to'] !== null && !Columns::statusOf(Columns::config($c), $t['to'])) throw new ProjectUserError('That label is not on the status column.');
                break;
            case 'column_changed':
                $c = $needCol($tc);
                $t = ['column_id' => (int)$c['column_id'], 'to' => isset($tc['to']) && $tc['to'] !== '' ? mb_substr((string)$tc['to'], 0, 200) : null];
                break;
            case 'person_assigned':
                $c = $needCol($tc, ['people']);
                $t = ['column_id' => (int)$c['column_id']];
                break;
            case 'item_created':
                $t = ['group_id' => !empty($tc['group_id']) ? $needGroup($tc['group_id']) : null];
                break;
            case 'item_moved':
                $t = ['group_id' => $needGroup($tc['group_id'] ?? 0)];
                break;
        }
        // Action config
        $a = [];
        switch ($action) {
            case 'move_to_group':
                $a = ['group_id' => $needGroup($ac['group_id'] ?? 0)];
                break;
            case 'set_value':
                $c = $needCol($ac);
                if (in_array($c['type'], Columns::COMPUTED, true)) throw new ProjectUserError('That column is calculated and cannot be set.');
                Columns::encodeValue($c, $ac['value'] ?? null, []); // validates
                $a = ['column_id' => (int)$c['column_id'], 'value' => $ac['value'] ?? null];
                break;
            case 'clear':
                $c = $needCol($ac);
                $a = ['column_id' => (int)$c['column_id']];
                break;
            case 'assign':
                $c = $needCol($ac, ['people']);
                $who = $ac['user_id'] ?? 'actor';
                if (!in_array($who, ['creator', 'actor'], true) && !isset($repo->people()[(int)$who])) throw new ProjectUserError('Choose who to assign.');
                $a = ['column_id' => (int)$c['column_id'], 'user_id' => in_array($who, ['creator', 'actor'], true) ? $who : (int)$who];
                break;
            case 'set_date':
                $c = $needCol($ac, ['date']);
                $a = ['column_id' => (int)$c['column_id'], 'days' => max(-3650, min(3650, (int)($ac['days'] ?? 0)))];
                break;
            case 'notify':
                $to = $ac['to'] ?? 'people';
                if (is_array($to)) $to = array_values(array_filter(array_map('intval', $to), fn($u) => isset($repo->people()[$u])));
                elseif (!in_array($to, ['people', 'creator'], true)) $to = 'people';
                if ($to === []) throw new ProjectUserError('Choose who to notify.');
                $a = ['to' => $to, 'message' => mb_substr(trim((string)($ac['message'] ?? '')), 0, 300)];
                break;
            case 'archive':
                $a = [];
                break;
        }
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 255) ?: 'Automation';
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            self::requireOnBoard($repo, $boardId, $id);
            $repo->db->prepare("UPDATE board_automations SET name = ?, trigger_type = ?, trigger_config = ?, action_type = ?, action_config = ?, is_active = ? WHERE id = ? AND board_id = ?")
                ->execute([$name, $trigger, json_encode($t), $action, json_encode($a, JSON_UNESCAPED_UNICODE), empty($in['active']) && array_key_exists('active', $in) ? 0 : 1, $id, $boardId]);
        } else {
            $st = $repo->db->prepare("SELECT COUNT(*) FROM board_automations WHERE board_id = ?");
            $st->execute([$boardId]);
            if ((int)$st->fetchColumn() >= 50) throw new ProjectUserError('A board can have up to 50 automations.');
            $repo->db->prepare("INSERT INTO board_automations (board_id, name, trigger_type, trigger_config, action_type, action_config, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())")
                ->execute([$boardId, $name, $trigger, json_encode($t), $action, json_encode($a, JSON_UNESCAPED_UNICODE), $repo->userId]);
            $id = (int)$repo->db->lastInsertId();
        }
        self::forget($boardId);
        Activity::log($repo->db, $repo->companyId, $repo->userId, $boardId, null, 'automation_saved', ['name' => $name]);
        return $id;
    }

    /** @return string the recipe's name; throws unless it is on this board */
    private static function requireOnBoard(ProjectRepo $repo, int $boardId, int $id): string
    {
        $st = $repo->db->prepare("SELECT name FROM board_automations WHERE id = ? AND board_id = ?");
        $st->execute([$id, $boardId]);
        $name = $st->fetchColumn();
        if ($name === false) throw new ProjectUserError('Automation not found.');
        return (string)$name;
    }

    public static function toggle(ProjectRepo $repo, int $boardId, int $id, bool $on): void
    {
        $repo->requireBoard($boardId, 3);
        $name = self::requireOnBoard($repo, $boardId, $id);
        $repo->db->prepare("UPDATE board_automations SET is_active = ? WHERE id = ? AND board_id = ?")->execute([$on ? 1 : 0, $id, $boardId]);
        self::forget($boardId);
        Activity::log($repo->db, $repo->companyId, $repo->userId, $boardId, null, 'automation_toggled', ['name' => $name, 'active' => $on]);
    }

    public static function delete(ProjectRepo $repo, int $boardId, int $id): void
    {
        $repo->requireBoard($boardId, 3);
        $name = self::requireOnBoard($repo, $boardId, $id);
        $repo->db->prepare("DELETE FROM board_automation_logs WHERE automation_id = ?")->execute([$id]);
        $repo->db->prepare("DELETE FROM board_automations WHERE id = ? AND board_id = ?")->execute([$id, $boardId]);
        self::forget($boardId);
        Activity::log($repo->db, $repo->companyId, $repo->userId, $boardId, null, 'automation_deleted', ['name' => $name]);
    }
}
