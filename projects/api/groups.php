<?php
// /projects/api/groups.php
//   POST {action:'create', board_id, name, color, where:'top'|'bottom'}
//   POST {action:'update', id, name?, color?}
//   POST {action:'reorder', board_id, ids[]}
//   POST {action:'duplicate', id}
//   POST {action:'delete', id}                     items are archived (restorable)
//   POST {action:'collapse', board_id, id, collapsed}   per user
require_once __DIR__ . '/_bootstrap.php';
pr_require_post();

$boards = new BoardRepo($PR);
$in = pr_input();
$action = pr_str($in, 'action', 30);
$id = pr_int($in, 'id');

switch ($action) {
    case 'create':
        pr_rate_limit('pr-struct', 300, 600);
        pr_json(['group' => $boards->createGroup(pr_int($in, 'board_id'), pr_str($in, 'name', 255), pr_str($in, 'color', 7) ?: null, pr_str($in, 'where', 10) ?: 'top')]);

    case 'update':
        pr_json(['group' => $boards->updateGroup($id, array_intersect_key($in, array_flip(['name', 'color'])))]);

    case 'reorder':
        $boards->reorderGroups(pr_int($in, 'board_id'), pr_ids($in['ids'] ?? []));
        pr_json();

    case 'duplicate':
        pr_rate_limit('pr-struct', 300, 600);
        pr_json($boards->duplicateGroup($id));

    case 'delete':
        $boards->deleteGroup($id);
        pr_json();

    case 'collapse':
        $boardId = pr_int($in, 'board_id');
        $PR->requireBoard($boardId, 1);
        $prefs = $PR->prefs('board', $boardId);
        $set = array_map('intval', is_array($prefs['collapsed'] ?? null) ? $prefs['collapsed'] : []);
        if (!isset($prefs['collapsed'])) {
            foreach ($boards->groupRows($boardId) as $g) if ((int)$g['collapsed']) $set[] = (int)$g['id'];
        }
        $set = array_values(array_diff($set, [$id]));
        if (!empty($in['collapsed'])) $set[] = $id;
        if (isset($in['all'])) $set = !empty($in['all']) ? array_map(fn($g) => (int)$g['id'], $boards->groupRows($boardId)) : [];
        $PR->savePrefs('board', $boardId, ['collapsed' => array_values(array_unique($set))]);
        pr_json(['collapsed' => array_values(array_unique($set))]);
}
pr_error('Unknown action');
