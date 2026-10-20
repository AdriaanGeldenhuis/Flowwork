<?php
// /shopping/api/templates.php — reusable lists.
//   GET  op=list
//   POST op=save {id?, name, purpose, text}      one item a line, read like a typed list
//   POST op=use {id, name, shared, needed_by}    a new list from the template
//   POST op=delete {id}
require_once __DIR__ . '/_bootstrap.php';

$op = sh_op();
$in = sh_is_post() ? sh_input() : $_GET;
$id = sh_int($in['id'] ?? 0);
[$C, $U, $R] = [$SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE];

switch ($op) {
    case 'list':
        sh_json(['templates' => ShTemplates::all($DB, $C, $U, $R)]);

    case 'save':
        sh_post_only();
        $tid = ShTemplates::save($DB, $C, $U, $R, array_intersect_key($in, array_flip(['id', 'name', 'purpose', 'text'])));
        sh_json(['id' => $tid, 'templates' => ShTemplates::all($DB, $C, $U, $R)]);

    case 'use':
        sh_post_only();
        $listId = ShTemplates::use($DB, $C, $U, $R, $id, $in);
        sh_json(['list_id' => $listId, 'url' => '/shopping/list.php?id=' . $listId]);

    case 'delete':
        sh_post_only();
        ShTemplates::delete($DB, $C, $U, $R, $id);
        sh_json(['templates' => ShTemplates::all($DB, $C, $U, $R)]);
}
sh_error('Unknown request.', 400);
