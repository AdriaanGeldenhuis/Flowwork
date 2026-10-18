<?php
// /projects/api/changes.php — live updates for an open board.
//   GET ?board_id=3&since=<cursor>
// Answers with what changed after the cursor: fresh payloads of changed items,
// ids that left the board (archived, deleted, moved away), the events (for
// "Anna changed Status" toasts) and whether the board's structure changed
// (columns, groups, settings) so the page reloads it.
require_once __DIR__ . '/_bootstrap.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$since = max(0, (int)($_GET['since'] ?? 0));
$PR->requireBoard($boardId, 1);

$st = $DB->prepare("SELECT id, item_id, user_id, action, details FROM board_audit_log WHERE board_id = ? AND id > ? ORDER BY id LIMIT 501");
$st->execute([$boardId, $since]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) pr_json(['cursor' => $since, 'events' => [], 'items' => [], 'removed' => [], 'structural' => false]);

$structural = count($rows) > 500;
$rows = array_slice($rows, 0, 500);
$changed = [];
$removed = [];
$events = [];
$structuralActions = ['column_created', 'column_renamed', 'column_updated', 'column_deleted', 'group_created', 'group_renamed', 'group_deleted',
                      'groups_reordered', 'board_renamed', 'board_updated', 'board_archived', 'board_restored', 'board_moved', 'automation_saved'];
foreach ($rows as $r) {
    $d = json_decode((string)$r['details'], true) ?: [];
    if (in_array($r['action'], $structuralActions, true)) $structural = true;
    $iid = $r['item_id'] ? (int)$r['item_id'] : (int)($d['item_id'] ?? 0);
    if ($iid) {
        if (in_array($r['action'], ['item_deleted', 'item_archived'], true) || ($r['action'] === 'item_moved_board' && empty($d['arrived']))) {
            $removed[$iid] = true;
            unset($changed[$iid]);
        } else {
            $changed[$iid] = true;
            unset($removed[$iid]);
        }
    }
    $events[] = ['id' => (int)$r['id'], 'action' => $r['action'], 'item_id' => $iid ?: null, 'user_id' => (int)$r['user_id'],
                 'user' => $PR->personName((int)$r['user_id']), 'column' => $d['column'] ?? null, 'to' => $d['to'] ?? null, 'title' => $d['title'] ?? null];
}
$items = (new ItemRepo($PR))->payload($boardId, array_keys($changed));
$present = array_flip(array_map(fn($i) => $i['id'], $items));
foreach (array_keys($changed) as $iid) if (!isset($present[$iid])) $removed[$iid] = true;

pr_json(['cursor' => (int)end($rows)['id'], 'events' => array_slice($events, -50), 'items' => $items,
         'removed' => array_map('intval', array_keys($removed)), 'structural' => $structural]);
