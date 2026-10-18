<?php
// /projects/api/updates.php — the conversation on an item ("Updates").
//   GET  ?item_id=9                          updates, newest first, each with its replies
//   POST {action:'add', item_id, body, parent_id?}   @mentions as @[Full name](user_id)
//   POST {action:'edit', id, body}           own updates only
//   POST {action:'delete', id}               own updates, or board managers
require_once __DIR__ . '/_bootstrap.php';

$items = new ItemRepo($PR);

if (!pr_is_post()) pr_json(['updates' => $items->updates((int)($_GET['item_id'] ?? 0))]);

$in = pr_input();
$action = pr_str($in, 'action', 20);

switch ($action) {
    case 'add':
        pr_rate_limit('pr-update', 120, 600);
        pr_json(['update' => $items->addUpdate(pr_int($in, 'item_id'), (string)($in['body'] ?? ''), pr_int($in, 'parent_id') ?: null)]);

    case 'edit':
        pr_json(['update' => $items->editUpdate(pr_int($in, 'id'), (string)($in['body'] ?? ''))]);

    case 'delete':
        $items->deleteUpdate(pr_int($in, 'id'));
        pr_json();
}
pr_error('Unknown action');
