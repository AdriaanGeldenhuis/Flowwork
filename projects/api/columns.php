<?php
// /projects/api/columns.php
//   POST {action:'create', board_id, type, name?, after?, config?, description?}
//   POST {action:'update', id, name?, width?, description?, hidden?, type?, config?}
//   POST {action:'reorder', board_id, ids[]}
//   POST {action:'duplicate', id}
//   POST {action:'delete', id}
// Responses carry the column (and, when values may have changed — formulas,
// renamed status labels — the board's fresh items).
require_once __DIR__ . '/_bootstrap.php';
pr_require_post();

$boards = new BoardRepo($PR);
$in = pr_input();
$action = pr_str($in, 'action', 30);
$id = pr_int($in, 'id');

$freshItems = function (int $boardId) use ($boards) {
    return $boards->itemsPayload($boardId, $boards->columnRows($boardId), null);
};
$boardOf = function (int $columnId) use ($DB, $PR_COMPANY_ID): int {
    $st = $DB->prepare("SELECT board_id FROM board_columns WHERE column_id = ? AND company_id = ?");
    $st->execute([$columnId, $PR_COMPANY_ID]);
    return (int)$st->fetchColumn();
};

switch ($action) {
    case 'create':
        pr_rate_limit('pr-struct', 300, 600);
        $boardId = pr_int($in, 'board_id');
        $col = $boards->createColumn($boardId, $in);
        pr_json(['column' => $col] + (in_array($col['type'], ['formula', 'progress'], true) ? ['items' => $freshItems($boardId)] : []));

    case 'update':
        $col = $boards->updateColumn($id, array_intersect_key($in, array_flip(['name', 'width', 'description', 'hidden', 'type', 'config'])));
        $reload = isset($in['config']) || isset($in['name']);
        pr_json(['column' => $col] + ($reload ? ['items' => $freshItems($boardOf($id))] : []));

    case 'reorder':
        $boards->reorderColumns(pr_int($in, 'board_id'), pr_ids($in['ids'] ?? []));
        pr_json();

    case 'duplicate':
        pr_rate_limit('pr-struct', 300, 600);
        $col = $boards->duplicateColumn($id);
        pr_json(['column' => $col, 'items' => $freshItems($boardOf($col['id']))]);

    case 'delete':
        $boardId = $boardOf($id);
        $boards->deleteColumn($id);
        pr_json(['items' => $boardId ? $freshItems($boardId) : []]);
}
pr_error('Unknown action');
