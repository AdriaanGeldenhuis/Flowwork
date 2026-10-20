<?php
// /shopping/api/stores.php — stores, the price book and "Where to buy".
//   GET  op=list                     the company's stores, with what was bought there
//   GET  op=prices&q=                the price book: last and best price of everything bought
//   GET  op=suggest&item_id=         where to buy one item (from what the company already knows)
//   POST op=ai {item_id}             ideas from AI (when an admin switched them on)
//   POST op=plan {item_id, store_id | crm_id}   plan to buy the item there
//   POST op=save {id?, name, phone, email, website, address, categories[], preferred, crm_id}
//   POST op=delete {id}
require_once __DIR__ . '/_bootstrap.php';

$op = sh_op();
$in = sh_is_post() ? sh_input() : $_GET;
[$C, $U, $R] = [$SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE];

switch ($op) {
    case 'list':
        sh_json(['stores' => ShStores::all($DB, $C, $U)]);

    case 'prices':
        sh_json(['prices' => ShStores::priceBook($DB, $C, $U, (string)($in['q'] ?? ''))]);

    case 'suggest':
        sh_json(ShStores::suggest($DB, $C, $U, $R, sh_int($in['item_id'] ?? 0)));

    case 'ai':
        sh_post_only();
        sh_rate_limit('sh-ai', 20, 600);
        sh_json(ShStores::ai($DB, $C, $U, $R, sh_int($in['item_id'] ?? 0)));

    case 'plan':
        sh_post_only();
        sh_json(['item' => ShStores::plan($DB, $C, $U, $R, sh_int($in['item_id'] ?? 0), $in['store_id'] ?? null, $in['crm_id'] ?? null)]);

    case 'save':
        sh_post_only();
        $id = ShStores::save($DB, $C, $U, $R, $in);
        sh_json(['id' => $id, 'stores' => ShStores::all($DB, $C, $U)]);

    case 'delete':
        sh_post_only();
        ShStores::delete($DB, $C, $R, sh_int($in['id'] ?? 0));
        sh_json(['stores' => ShStores::all($DB, $C, $U)]);
}
sh_error('Unknown request.', 400);
