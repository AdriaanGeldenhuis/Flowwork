<?php
// /receipts/api/bill.php — a receipt's bill.
//   GET  op=load&id                  receipt, reading, form (bill or first draft), supplier, checks, lookups, queue
//   GET  op=supplier&id              a supplier's VAT number, compliance, open purchase orders
//   GET  op=suppliers&q              suppliers for the picker
//   GET  op=items&board              tasks on a project board
//   POST op=check     id, form       the checks and totals for the form as it stands
//   POST op=save      id, form       save the draft bill (Finance status "review")
//   POST op=post      id, form       save, check and post (and record the payment when paid on the spot)
//   POST op=delete_draft id          delete the draft bill, keep the receipt
//   POST op=override  id, code, reason   (admins) post past a block, on record
//   POST op=supplier_new  name, vat_no, email, phone[, confirm_duplicate]
//   POST op=supplier_vat  supplier_id, vat
require_once __DIR__ . '/_bootstrap.php';

$op = rc_op();

/** The review payload: the receipt with its form, checks and lookups. */
function rc_review(PDO $db, int $c, int $u, string $role, int $id): array
{
    $data = RcBills::load($db, $c, $u, $role, $id);
    $row = RcBills::row($db, $c, $id);
    $data['checks'] = RcBills::checks($db, $c, $u, $role, $row, $data['form']);
    $data['lookups'] = RcBills::lookups($db, $c, $u, $role);
    $board = (int)($data['form']['board_id'] ?? 0);
    if ($board && !in_array($board, array_column($data['lookups']['boards'], 'id'), true)) {
        $label = RcBills::boardLabel($db, $c, $board);
        if ($label !== '') $data['lookups']['boards'][] = ['id' => $board, 'label' => $label . ' (on this bill)', 'project' => ''];
    }
    $data['items'] = $board ? ProjectLink::pickerItems($db, $c, $u, $role, $board) : [];
    return $data;
}

if (!rc_is_post()) {
    switch ($op) {
        case 'load':
            rc_json(rc_review($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, rc_int($_GET['id'] ?? 0)));
        case 'supplier':
            $sid = rc_int($_GET['id'] ?? 0);
            $s = RcBills::supplierInfo($DB, $RC_COMPANY_ID, $sid);
            if (!$s) rc_error('That supplier was not found.', 404);
            // What was learned from this supplier's earlier bills: their account and project board.
            $map = RcLearn::map($DB, $RC_COMPANY_ID);
            [$acc, $why] = RcLearn::accountFor($DB, $RC_COMPANY_ID, $map, $sid, '');
            $board = RcLearn::boardFor($map, $sid);
            if ($board && !ProjectLink::canSeeBoard($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $board)) $board = null;
            $s['learned'] = ['account_id' => $acc, 'source' => $why, 'board_id' => $board];
            rc_json(['supplier' => $s]);
        case 'suppliers':
            $q = trim((string)($_GET['q'] ?? ''));
            $args = [$RC_COMPANY_ID];
            $where = "a.company_id = ? AND a.type = 'supplier' AND a.deleted_at IS NULL";
            if ($q !== '') {
                $where .= ' AND (a.name LIKE ? OR a.vat_no LIKE ? OR a.legal_name LIKE ?)';
                $like = '%' . RcKit::likeEscape($q) . '%';
                array_push($args, $like, $like, $like);
            }
            $st = $DB->prepare("SELECT a.id, a.name, a.vat_no, a.status, (SELECT MAX(b.issue_date) FROM ap_bills b WHERE b.company_id = a.company_id AND b.supplier_id = a.id) last_bill
                                  FROM crm_accounts a WHERE $where ORDER BY (a.name LIKE ?) DESC, last_bill IS NULL, last_bill DESC, a.name LIMIT 30");
            $st->execute(array_merge($args, [RcKit::likeEscape($q) . '%']));
            rc_json(['suppliers' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'vat_no' => (string)($r['vat_no'] ?? ''),
                                                          'status' => (string)$r['status'], 'last' => $r['last_bill']], $st->fetchAll(PDO::FETCH_ASSOC))]);
        case 'items':
            rc_json(['items' => ProjectLink::pickerItems($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, rc_int($_GET['board'] ?? 0))]);
    }
    rc_error('Unknown request.', 400);
}

$in = rc_input();
$id = rc_int($in['id'] ?? 0);
$form = is_array($in['form'] ?? null) ? $in['form'] : [];
switch ($op) {
    case 'check':
        $row = RcBills::need($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id);
        rc_json(RcBills::checks($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $row, $form));
    case 'save':
        $res = RcBills::save($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id, $form);
        rc_json(['saved' => $res, 'review' => rc_review($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id)]);
    case 'post':
        rc_rate_limit('rc-post', 60, 60);
        try {
            $res = RcBills::post($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id, $form);
        } catch (RcBlocked $e) {
            rc_error($e->getMessage(), 409, ['checks' => $e->checks, 'review' => rc_review($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id)]);
        }
        rc_json(['posted' => $res, 'review' => rc_review($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id)]);
    case 'delete_draft':
        RcBills::deleteDraft($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id);
        rc_json(['review' => rc_review($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id)]);
    case 'override':
        RcBills::override($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id, (string)($in['code'] ?? ''), (string)($in['reason'] ?? ''));
        $row = RcBills::need($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id);
        rc_json(RcBills::checks($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $row, $form));
    case 'supplier_new':
        rc_json(RcBills::createSupplier($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $in));
    case 'supplier_vat':
        RcBills::saveSupplierVat($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, rc_int($in['supplier_id'] ?? 0), (string)($in['vat'] ?? ''));
        rc_json(['supplier' => RcBills::supplierInfo($DB, $RC_COMPANY_ID, rc_int($in['supplier_id'] ?? 0))]);
}
rc_error('Unknown request.', 400);
