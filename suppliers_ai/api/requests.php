<?php
// /suppliers_ai/api/requests.php — requests for quotes.
//   GET  op=list&status=&mine=&q=      op=get&id=      op=compose&id=
//   POST op=create {title, details, quantity, location, category, requirements[], needed_by, quote_by, budget, query_id, suppliers[{candidate_id|account_id}]}
//        op=update {id, …fields}       op=add {id, suppliers[]}       op=remove {recipient_id}
//        op=send {recipient_id, mailbox_id, to[], subject, body, kind: ask|reminder|thanks}
//        op=mark {recipient_id, how: whatsapp|phone|other|declined|undeclined|unasked}
//        op=quote {recipient_id, amount, vat: incl|excl|none, lead_days, valid_until, notes}
//        op=award {recipient_id}       op=unaward {id}       op=state {id, to: close|cancel|reopen|delete|restore}
//        op=po {id}                    op=draft {id} (AI writes the email)      op=refresh {id} (who replied)
require_once __DIR__ . '/_bootstrap.php';

$in = sa_is_post() ? sa_input() : $_GET;
$id = sa_int($in['id'] ?? 0);
$rid = sa_int($in['recipient_id'] ?? 0);
$get = fn(int $id) => SaRequests::get($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id);

switch (sa_op()) {
    case 'list':
        sa_json(['requests' => SaRequests::list($DB, $SA_COMPANY_ID, $SA_USER_ID, ['status' => (string)($in['status'] ?? 'active'), 'mine' => !empty($in['mine']), 'q' => $in['q'] ?? '']),
                 'counts' => SaRequests::counts($DB, $SA_COMPANY_ID, $SA_USER_ID)]);
    case 'get':
        sa_json($get($id));
    case 'compose':
        sa_json(SaRequests::compose($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id));
}

sa_post_only();
switch (sa_op()) {
    case 'create':
        sa_rate_limit('sa-create', 30, 600);
        sa_json(SaRequests::create($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $in));
    case 'update':
        sa_json(SaRequests::update($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id, $in));
    case 'add':
        sa_json(SaRequests::addSuppliers($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id, (array)($in['suppliers'] ?? [])));
    case 'remove':
        sa_json(SaRequests::removeSupplier($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $rid));
    case 'send':
        sa_json(SaRequests::send($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $rid, $in));
    case 'mark':
        sa_json(SaRequests::mark($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $rid, (string)($in['how'] ?? '')));
    case 'quote':
        sa_json(SaRequests::quote($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $rid, $in));
    case 'award':
        sa_json(SaRequests::award($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $rid));
    case 'unaward':
        sa_json(SaRequests::unaward($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id));
    case 'state':
        sa_json(SaRequests::setState($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id, (string)($in['to'] ?? '')));
    case 'po':
        sa_json(SaPo::create($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id));
    case 'draft':
        sa_json(SaRequests::draft($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, $id));
    case 'refresh':
        SaRequests::row($DB, $SA_COMPANY_ID, $id);
        $found = SaRequests::refresh($DB, $SA_COMPANY_ID, $id);
        sa_json(['found' => count($found)] + $get($id));
}
sa_error('Unknown request.', 404);
