<?php
// /projects/api/items.php
//   GET  ?id=9                                   one item with subitems, watchers, dependencies and links
//   GET  ?board_id=3&ids=9,10                    fresh payloads (live updates)
//   POST {action:'create', board_id, group_id, title, values{column_id: value}, where:'top'|'bottom', email_id?}
//   POST {action:'rename', id, title}            POST {action:'describe', id, text}
//   POST {action:'set', id, column_id, value}    one cell
//   POST {action:'bulk_set', board_id, ids[], column_id, value}
//   POST {action:'move', board_id, ids[], group_id, order[]?}
//   POST {action:'reorder', board_id, group_id, order[]}
//   POST {action:'duplicate', id}
//   POST {action:'archive'|'restore'|'delete', board_id, ids[]}
//   POST {action:'move_board', board_id, ids[], to_board_id, group_id?}
//   POST {action:'watch', id, on}
//   POST {action:'dep_add', from, to, type?, lag?}   ("to" waits on "from")
//   POST {action:'dep_remove', dep_id}
//   POST {action:'link_email', id, email_id}
require_once __DIR__ . '/_bootstrap.php';

$items = new ItemRepo($PR);

if (!pr_is_post()) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) pr_json(['item' => $items->detail($id)]);
    $boardId = (int)($_GET['board_id'] ?? 0);
    $PR->requireBoard($boardId, 1);
    pr_json(['items' => $items->payload($boardId, pr_ids((string)($_GET['ids'] ?? ''), 500))]);
}

$in = pr_input();
$action = pr_str($in, 'action', 30);
$id = pr_int($in, 'id');
$boardId = pr_int($in, 'board_id');
$ids = pr_ids($in['ids'] ?? []);

switch ($action) {
    case 'create':
        pr_rate_limit('pr-write', 600, 600);
        $values = is_array($in['values'] ?? null) ? $in['values'] : [];
        $emailId = pr_int($in, 'email_id');
        if ($emailId) ProjectLink::ownEmail($PR, $emailId); // check before creating anything
        $item = $items->create($boardId, pr_int($in, 'group_id') ?: null, pr_str($in, 'title', 500), $values, pr_str($in, 'where', 10) ?: 'bottom',
                               ['description' => isset($in['description']) ? (string)$in['description'] : null] + ($emailId ? ['source' => 'email'] : []));
        if ($emailId && !empty($item['id'])) ProjectLink::linkEmail($PR, 'item', (int)$item['id'], $emailId);
        pr_json(['item' => $item]);

    case 'rename':
        pr_rate_limit('pr-write', 600, 600);
        pr_json(['item' => $items->rename($id, pr_str($in, 'title', 500))]);

    case 'describe':
        $items->describe($id, (string)($in['text'] ?? ''));
        pr_json();

    case 'set':
        pr_rate_limit('pr-write', 600, 600);
        pr_json($items->setValue($id, pr_int($in, 'column_id'), $in['value'] ?? null));

    case 'bulk_set':
        pr_rate_limit('pr-write', 600, 600);
        pr_json($items->setValues($boardId, $ids, pr_int($in, 'column_id'), $in['value'] ?? null));

    case 'move':
        $items->move($boardId, $ids, pr_int($in, 'group_id'), isset($in['order']) ? pr_ids($in['order']) : null);
        pr_json(['items' => $items->payload($boardId, $ids)]);

    case 'reorder':
        $items->reorder($boardId, pr_int($in, 'group_id'), pr_ids($in['order'] ?? []));
        pr_json();

    case 'duplicate':
        pr_rate_limit('pr-write', 600, 600);
        pr_json(['item' => $items->duplicate($id)]);

    case 'archive':
    case 'restore':
        $done = $items->archive($boardId, $ids, $action === 'archive');
        pr_json(['ids' => $done] + ($action === 'restore' ? ['items' => $items->payload($boardId, $done)] : []));

    case 'delete':
        pr_unlink_files($items->delete($boardId, $ids));
        pr_json();

    case 'move_board':
        $moved = $items->moveToBoard($boardId, $ids, pr_int($in, 'to_board_id'), pr_int($in, 'group_id') ?: null);
        pr_json(['ids' => $moved]);

    case 'watch':
        $items->watch($id, !empty($in['on']));
        pr_json();

    case 'dep_add':
        pr_json(['dep_id' => $items->addDependency(pr_int($in, 'from'), pr_int($in, 'to'), pr_str($in, 'type', 2) ?: 'FS', pr_int($in, 'lag'))]);

    case 'dep_remove':
        $items->removeDependency(pr_int($in, 'dep_id'));
        pr_json();

    case 'link_email':
        ProjectLink::linkEmail($PR, 'item', $id, pr_int($in, 'email_id'));
        pr_json();
}
pr_error('Unknown action');
