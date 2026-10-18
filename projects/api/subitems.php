<?php
// /projects/api/subitems.php
//   GET  ?item_id=9
//   POST {action:'add', item_id, title, owner?, due?}
//   POST {action:'update', id, title?, done?, owner?, due?}
//   POST {action:'delete', id}
//   POST {action:'reorder', item_id, order[]}
//   POST {action:'promote', id}           make it an item of its own
// Writes answer with the parent's fresh payload (counts, progress).
require_once __DIR__ . '/_bootstrap.php';

$items = new ItemRepo($PR);

if (!pr_is_post()) pr_json(['subitems' => $items->subitems((int)($_GET['item_id'] ?? 0))]);

$in = pr_input();
$action = pr_str($in, 'action', 20);
$id = pr_int($in, 'id');

$parentOf = function (int $subId) use ($DB, $PR_COMPANY_ID): int {
    $st = $DB->prepare("SELECT parent_item_id FROM board_subitems WHERE id = ? AND company_id = ?");
    $st->execute([$subId, $PR_COMPANY_ID]);
    return (int)$st->fetchColumn();
};
$parentPayload = function (int $itemId) use ($PR, $items): ?array {
    $it = $PR->requireItem($itemId, 1);
    return $items->payload((int)$it['board_id'], [$itemId])[0] ?? null;
};

switch ($action) {
    case 'add':
        pr_rate_limit('pr-write', 600, 600);
        $itemId = pr_int($in, 'item_id');
        $sub = $items->addSubitem($itemId, pr_str($in, 'title', 255), pr_int($in, 'owner') ?: null, pr_str($in, 'due', 10) ?: null);
        pr_json(['subitem' => $sub, 'item' => $parentPayload($itemId)]);

    case 'update':
        $itemId = $parentOf($id);
        $sub = $items->updateSubitem($id, array_intersect_key($in, array_flip(['title', 'done', 'owner', 'due'])));
        pr_json(['subitem' => $sub, 'item' => $parentPayload($itemId)]);

    case 'delete':
        $itemId = $parentOf($id);
        $items->deleteSubitem($id);
        pr_json(['item' => $itemId ? $parentPayload($itemId) : null]);

    case 'reorder':
        $items->reorderSubitems(pr_int($in, 'item_id'), pr_ids($in['order'] ?? []));
        pr_json();

    case 'promote':
        $itemId = $parentOf($id);
        $new = $items->promoteSubitem($id);
        pr_json(['item' => $parentPayload($itemId), 'new_item' => $new]);
}
pr_error('Unknown action');
