<?php
// /receipts/api/receipts.php — receipts as a list, and what can be done to them.
//   GET  op=list      view, q, supplier, from, to, mine, page, sort
//   GET  op=overview  the overview's figures
//   GET  op=export    the filtered list as CSV
//   POST op=note      id, note                  (the sender, or a bookkeeper)
//   POST op=aside     ids[], reason, note       (set aside: not a bill)
//   POST op=restore   ids[]
//   POST op=delete    ids[]                     (not behind a bill in the books)
require_once __DIR__ . '/_bootstrap.php';

$op = rc_op();
$in = rc_is_post() ? rc_input() : $_GET;
$filters = array_intersect_key($in, array_flip(['view', 'q', 'supplier', 'from', 'to', 'mine', 'page', 'sort']));

if (!rc_is_post()) {
    switch ($op) {
        case 'list':
            rc_json(RcReceipts::list($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $filters));
        case 'overview':
            rc_json(['overview' => RcReceipts::overview($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE)]);
        case 'export':
            rc_rate_limit('rc-export', 20, 300);
            RcReceipts::csv($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $filters);
    }
    rc_error('Unknown request.', 400);
}

$ids = array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : [rc_int($in['id'] ?? 0)]);
switch ($op) {
    case 'note':
        RcReceipts::setNote($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, rc_int($in['id'] ?? 0), (string)($in['note'] ?? ''));
        rc_json(['saved' => true]);
    case 'aside':
        rc_json(['done' => RcReceipts::aside($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $ids, (string)($in['reason'] ?? ''), (string)($in['note'] ?? ''))]);
    case 'restore':
        rc_json(['done' => RcReceipts::restore($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $ids)]);
    case 'delete':
        rc_json(['done' => RcReceipts::delete($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $ids)]);
}
rc_error('Unknown request.', 400);
