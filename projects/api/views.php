<?php
// /projects/api/views.php — saved board views (filters, sort, hidden columns, view type).
//   POST {action:'save', board_id, id?, name, view, config{}, shared}
//   POST {action:'delete', board_id, id}
require_once __DIR__ . '/_bootstrap.php';
pr_require_post();

$boards = new BoardRepo($PR);
$in = pr_input();
$boardId = pr_int($in, 'board_id');

switch (pr_str($in, 'action', 20)) {
    case 'save':
        pr_rate_limit('pr-struct', 300, 600);
        pr_json(['view' => $boards->saveView($boardId, $in), 'views' => $boards->savedViews($boardId)]);

    case 'delete':
        $boards->deleteView($boardId, pr_int($in, 'id'));
        pr_json(['views' => $boards->savedViews($boardId)]);
}
pr_error('Unknown action');
