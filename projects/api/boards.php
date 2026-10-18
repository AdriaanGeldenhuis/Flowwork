<?php
// /projects/api/boards.php
//   GET  ?id=3                     the whole board (columns, groups, items, views, prefs, cursor)
//   GET  ?id=3&archived=1          the board's archived items
//   GET  ?templates=1              board and project templates
//   POST {action:'create', project_id, title, template, default_view, description}
//   POST {action:'update', id, title?, description?, default_view?, item_noun?, type?}
//   POST {action:'archive'|'restore'|'delete', id}
//   POST {action:'duplicate', id, with_items, project_id?}
//   POST {action:'move', id, project_id}
//   POST {action:'star', id, on}
//   POST {action:'prefs', id, prefs{}}   per-user: view, hidden columns, collapsed groups, sort, kanban column …
require_once __DIR__ . '/_bootstrap.php';

$boards = new BoardRepo($PR);

if (!pr_is_post()) {
    if (!empty($_GET['templates'])) pr_json(Templates::catalogue());
    $id = (int)($_GET['id'] ?? 0);
    if (!empty($_GET['archived'])) {
        $PR->requireBoard($id, 1);
        pr_json(['items' => $boards->itemsPayload($id, $boards->columnRows($id), null, true)]);
    }
    $data = $boards->load($id);
    $PR->touch('board', $id);
    pr_json($data);
}

$in = pr_input();
$action = pr_str($in, 'action', 30);
$id = pr_int($in, 'id');

switch ($action) {
    case 'create':
        pr_rate_limit('pr-board', 120, 600);
        $newId = $boards->createBoard(pr_int($in, 'project_id'), $in);
        pr_json(['id' => $newId, 'url' => ProjectLink::boardUrl($newId)]);

    case 'update':
        $boards->updateBoard($id, $in);
        $PR->forgetBoard($id);
        $b = $PR->requireBoard($id, 1);
        pr_json(['board' => ProjectRepo::boardSummary($b)]);

    case 'archive':
    case 'restore':
        $boards->archiveBoard($id, $action === 'archive');
        pr_json();

    case 'delete':
        pr_unlink_files($boards->deleteBoard($id));
        pr_json();

    case 'duplicate':
        pr_rate_limit('pr-board', 120, 600);
        $b = $PR->requireBoard($id, 1);
        $target = pr_int($in, 'project_id') ?: (int)$b['project_id'];
        $newId = $boards->copyBoard($id, $target, !empty($in['with_items']));
        pr_json(['id' => $newId, 'url' => ProjectLink::boardUrl($newId)]);

    case 'move':
        $boards->moveBoard($id, pr_int($in, 'project_id'));
        pr_json();

    case 'star':
        $PR->star('board', $id, !empty($in['on']));
        pr_json();

    case 'prefs':
        $PR->requireBoard($id, 1);
        $patch = is_array($in['prefs'] ?? null) ? $in['prefs'] : [];
        $allowed = ['view', 'hidden', 'collapsed', 'sort', 'group_by', 'kanban_column', 'calendar_column', 'timeline_column', 'timeline_zoom',
                    'chart', 'person', 'filters', 'widths', 'density', 'show_subitems', 'workload_column'];
        $patch = array_intersect_key($patch, array_flip($allowed));
        pr_json(['prefs' => $PR->savePrefs('board', $id, $patch)]);
}
pr_error('Unknown action');
