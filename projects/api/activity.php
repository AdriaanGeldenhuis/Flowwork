<?php
// /projects/api/activity.php
//   GET ?board_id=3 | ?item_id=9 | ?project_id=5   [&before=<id>&limit=50]   newest first
require_once __DIR__ . '/_bootstrap.php';

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$before = (int)($_GET['before'] ?? 0);
if (!empty($_GET['item_id'])) {
    $PR->requireItem((int)$_GET['item_id'], 1);
    pr_json(['activity' => Activity::feed($PR, ['item_id' => (int)$_GET['item_id']], $limit, $before)]);
}
if (!empty($_GET['board_id'])) {
    $PR->requireBoard((int)$_GET['board_id'], 1);
    pr_json(['activity' => Activity::feed($PR, ['board_id' => (int)$_GET['board_id']], $limit, $before)]);
}
if (!empty($_GET['project_id'])) {
    $PR->requireProject((int)$_GET['project_id'], 1);
    pr_json(['activity' => Activity::feed($PR, ['project_id' => (int)$_GET['project_id']], $limit, $before)]);
}
pr_error('Choose a board, item or project.');
