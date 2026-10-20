<?php
// /shopping/api/items.php — the things on a list.
//   GET|POST op=parse {text}          how a typed or pasted list will be read (nothing is saved)
//   POST op=add {list_id, text}       add one or many (the same item again adds up)
//   POST op=update {id, name, qty, unit, priority, notes, category, needed_by, project_id, est, store_id}
//   POST op=toggle {id, bought, paid?, store_id?}   in the trolley or out (safe to repeat)
//   POST op=price {id, paid}          what the line cost
//   POST op=remove {id}   op=restore {id}   op=reorder {list_id, ids}
require_once __DIR__ . '/_bootstrap.php';

$op = sh_op();
$in = sh_is_post() ? sh_input() : $_GET;
$id = sh_int($in['id'] ?? 0);
[$C, $U, $R] = [$SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE];

$answer = function (int $listId, array $extra = []) use ($DB) {
    sh_json($extra + ['counts' => ShItems::counts($DB, $listId), 'version' => ShLists::version($DB, $listId)]);
};

switch ($op) {
    case 'parse':
        $items = ShParse::items(mb_substr((string)($in['text'] ?? ''), 0, 20000), ShKit::MAX_LINES_PER_ADD);
        sh_json(['items' => array_map(fn($i) => $i + ['category_label' => ShParse::categoryLabel($i['category']), 'qty_text' => ShKit::qtyText($i['qty'])], $items)]);

    case 'add':
        sh_post_only();
        $list = ShLists::access($DB, $C, $U, $R, sh_int($in['list_id'] ?? 0), 'edit');
        $items = ShParse::items(mb_substr((string)($in['text'] ?? ''), 0, 20000), ShKit::MAX_LINES_PER_ADD);
        if (!$items) throw new ShProblem('Type what you need — one item a line, like “10 bags cement”.', 422);
        $r = ShItems::addMany($DB, $C, $U, $list, $items, ['project_id' => $list['project_id'] ? (int)$list['project_id'] : null]);
        $rows = array_values(array_filter(array_map(fn($i) => ShLists::item($DB, $C, $i), array_merge($r['added'], array_column($r['merged'], 'id')))));
        $answer((int)$list['id'], $r + ['items' => $rows]);

    case 'update':
        sh_post_only();
        $item = ShItems::update($DB, $C, $U, $R, $id, array_intersect_key($in, array_flip(['name', 'qty', 'unit', 'priority', 'notes', 'category', 'needed_by', 'project_id', 'est', 'store_id'])));
        [$raw] = ShItems::access($DB, $C, $U, $R, $id, 'see');
        $answer((int)$raw['list_id'], ['item' => $item]);

    case 'toggle':
        sh_post_only();
        [, $list] = ShItems::access($DB, $C, $U, $R, $id, 'edit');
        $paid = array_key_exists('paid', $in) ? ShKit::price($in['paid'], 'What it cost') : false;
        $store = array_key_exists('store_id', $in) ? $in['store_id'] : false;
        $r = ShItems::toggle($DB, $C, $U, $list, $id, !empty($in['bought']), $paid, $store);
        $answer((int)$list['id'], $r);

    case 'price':
        sh_post_only();
        [$item, $list] = ShItems::access($DB, $C, $U, $R, $id, 'edit');
        if ($item['status'] !== 'bought') throw new ShProblem('Tick it off first, then note what it cost.', 409);
        $r = ShItems::toggle($DB, $C, $U, $list, $id, true, ShKit::price($in['paid'] ?? null, 'What it cost'), array_key_exists('store_id', $in) ? $in['store_id'] : false);
        $answer((int)$list['id'], $r);

    case 'remove':
        sh_post_only();
        [$item] = ShItems::access($DB, $C, $U, $R, $id, 'edit');
        ShItems::remove($DB, $C, $U, $R, $id);
        $answer((int)$item['list_id'], ['removed' => $id]);

    case 'restore':
        sh_post_only();
        $item = ShItems::restore($DB, $C, $U, $R, $id);
        [$raw] = ShItems::access($DB, $C, $U, $R, $id, 'see');
        $answer((int)$raw['list_id'], ['item' => $item]);

    case 'reorder':
        sh_post_only();
        $listId = sh_int($in['list_id'] ?? 0);
        ShItems::reorder($DB, $C, $U, $R, $listId, is_array($in['ids'] ?? null) ? array_slice($in['ids'], 0, ShKit::MAX_ITEMS_PER_LIST) : []);
        $answer($listId);
}
sh_error('Unknown request.', 400);
