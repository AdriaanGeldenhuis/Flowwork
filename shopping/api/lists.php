<?php
// /shopping/api/lists.php — shopping lists.
//   GET  op=overview                 the cards, the counts, needed soon, recent buys, this month by aisle
//   GET  op=get&id=                  one list: card, items, who is shopping, links, activity, change stamp
//   GET  op=version&id=              the change stamp alone (pages poll it to stay in step)
//   GET  op=search&q=                lists and items for "Find a list or item"
//   GET  op=export&id=               the list as CSV
//   GET  op=suppliers                CRM suppliers for a purchase order (admins, bookkeepers)
//   GET  op=projects                 projects this person can add things to
//   POST op=create {name, purpose, shared, budget, needed_by, project_id, notes, text}
//   POST op=update {id, …fields}     op=delete {id}   op=restore {id}   op=duplicate {id, name}
//   POST op=shop {id, store_id}      start (or join) the shopping trip   op=shop_store {id, store_id}
//   POST op=finish {id, leftover: keep|move|remove}   op=reopen {id}
//   POST op=share {id, days, can_check, can_edit}     op=unshare {id, token_id}
//   POST op=template {id, name}      save the list as a template
//   POST op=po {id, supplier_id, lines: [{id, price}]}  a draft purchase order
require_once __DIR__ . '/_bootstrap.php';

$op = sh_op();
$in = sh_is_post() ? sh_input() : $_GET;
$id = sh_int($in['id'] ?? 0);
[$C, $U, $R] = [$SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE];

switch ($op) {
    case 'overview':
        sh_json(['lists' => ShLists::cards($DB, $C, $U, $R), 'nav' => ShLists::navCounts($DB, $C, $U)] + ShLists::overview($DB, $C, $U, $R));

    case 'get':
        sh_json(ShLists::detail($DB, $C, $U, $R, $id));

    case 'version':
        ShLists::access($DB, $C, $U, $R, $id);
        sh_json(['version' => ShLists::version($DB, $id)]);

    case 'search':
        sh_json(ShLists::search($DB, $C, $U, (string)($in['q'] ?? '')));

    case 'export':
        $csv = ShLists::csv($DB, $C, $U, $R, $id);
        $file = trim(preg_replace('/[^A-Za-z0-9 ._-]+/', '', $csv['name']) ?: 'shopping-list') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($csv['rows'] as $row) {
            // A cell that starts like a formula is kept as text in spreadsheets.
            fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row));
        }
        fclose($out);
        exit;

    case 'suppliers':
        if (!ShKit::canOrder($R)) throw new ShForbidden('Only admins and bookkeepers can make purchase orders.');
        sh_json(['suppliers' => ShPo::suppliers($DB, $C)]);

    case 'projects':
        require_once __DIR__ . '/../../projects/lib/autoload.php';
        sh_json(['projects' => array_map(fn($p) => ['id' => $p['id'], 'label' => $p['label']], ProjectLink::pickerProjects($DB, $C, $U, $R))]);

    case 'create':
        sh_post_only();
        $items = ShParse::items((string)($in['text'] ?? ''), ShKit::MAX_LINES_PER_ADD);
        $newId = ShLists::create($DB, $C, $U, $R, $in, $items);
        sh_json(['id' => $newId, 'url' => '/shopping/list.php?id=' . $newId, 'items' => count($items)]);

    case 'update':
        sh_post_only();
        ShLists::update($DB, $C, $U, $R, $id, array_intersect_key($in, array_flip(['name', 'purpose', 'shared', 'budget', 'needed_by', 'notes', 'project_id', 'store_id'])));
        sh_json(['list' => ShLists::cards($DB, $C, $U, $R, $id)[0] ?? null, 'version' => ShLists::version($DB, $id)]);

    case 'delete':
        sh_post_only();
        ShLists::delete($DB, $C, $U, $R, $id);
        sh_json(['deleted' => $id]);

    case 'restore':
        sh_post_only();
        ShLists::restore($DB, $C, $U, $R, $id);
        sh_json(['restored' => $id, 'url' => '/shopping/list.php?id=' . $id]);

    case 'duplicate':
        sh_post_only();
        $newId = ShLists::duplicate($DB, $C, $U, $R, $id, ShKit::text($in['name'] ?? '', 120));
        sh_json(['id' => $newId, 'url' => '/shopping/list.php?id=' . $newId]);

    case 'shop':
        sh_post_only();
        $r = ShLists::startShopping($DB, $C, $U, $R, $id, $in['store_id'] ?? null);
        sh_json($r + ShLists::detail($DB, $C, $U, $R, $id));

    case 'shop_store':
        sh_post_only();
        ShLists::setShoppingStore($DB, $C, $U, $R, $id, $in['store_id'] ?? null);
        sh_json(ShLists::detail($DB, $C, $U, $R, $id));

    case 'finish':
        sh_post_only();
        $left = in_array($in['leftover'] ?? 'keep', ['keep', 'move', 'remove'], true) ? $in['leftover'] : 'keep';
        $r = ShLists::finish($DB, $C, $U, $R, $id, $left);
        sh_json(['finish' => $r] + ShLists::detail($DB, $C, $U, $R, $id));

    case 'reopen':
        sh_post_only();
        ShLists::reopen($DB, $C, $U, $R, $id);
        sh_json(ShLists::detail($DB, $C, $U, $R, $id));

    case 'share':
        sh_post_only();
        sh_rate_limit('sh-share', 20, 3600);
        sh_json(ShShare::create($DB, $C, $U, $R, $id, $in));

    case 'unshare':
        sh_post_only();
        sh_json(['shares' => ShShare::revoke($DB, $C, $U, $R, $id, sh_int($in['token_id'] ?? 0))]);

    case 'template':
        sh_post_only();
        $tid = ShTemplates::fromList($DB, $C, $U, $R, $id, ShKit::text($in['name'] ?? '', 120));
        sh_json(['template_id' => $tid, 'url' => '/shopping/templates.php#t' . $tid]);

    case 'po':
        sh_post_only();
        sh_rate_limit('sh-po', 30, 3600);
        sh_json(ShPo::create($DB, $C, $U, $R, $id, sh_int($in['supplier_id'] ?? 0), is_array($in['lines'] ?? null) ? $in['lines'] : []) + ['detail' => ShLists::detail($DB, $C, $U, $R, $id)]);
}
sh_error('Unknown request.', 400);
