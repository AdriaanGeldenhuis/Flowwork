<?php
// /payroll/api/runs.php — pay runs.
//   GET  ?op=list&stage=open|final|booked|all&frequency=    → {rows, counts}
//   GET  ?op=get&id=                                         → {run}
//   GET  ?op=person&id=&employee=                            → {person}
//   GET  ?op=suggest&frequency=                              → {suggest} the next period and pay day
//   POST {op:'create', frequency, name, period_start, period_end, pay_date, notes}
//   POST {op:'recalc'|'approve'|'finalise'|'post'|'delete'|'reopen', id}  (finalise takes email: bool)
//   POST {op:'add_people', id, employee_ids?} | {op:'remove_person', id, employee_id}
//   POST {op:'adjust', id, employee_id, kind: hours|overtime|item, qty, when, payitem_id, amount, rate, description, note}
//   POST {op:'adjust_remove', id, line_id} | {op:'rename', id, name, notes}
//   POST {op:'email', id, employee_id?, resend?}             → email payslips
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
if (!pay_is_post()) {
    $id = pay_int($_GET['id'] ?? 0);
    switch (pay_op() ?: 'list') {
        case 'list': pay_json(PayRuns::list($DB, $C, $_GET));
        case 'get':
            $run = PayRuns::get($DB, $C, $id);
            $run['missing'] = array_map(fn($e) => ['id' => (int)$e['id'], 'name' => $e['name'], 'no' => $e['employee_no']], $run['missing']);
            pay_json(['run' => $run]);
        case 'person': pay_json(['person' => PayRuns::person($DB, $C, $id, pay_int($_GET['employee'] ?? 0))]);
        case 'suggest':
            $s = PayKit::settings($DB, $C);
            $f = isset(PayKit::FREQ[PayKit::key($_GET['frequency'] ?? '')]) ? $_GET['frequency'] : $s['default_frequency'];
            pay_json(['suggest' => PayKit::suggest($DB, $C, $f, $s)]);
    }
    pay_error('Unknown action');
}

$in = pay_input();
$id = pay_int($in['id'] ?? 0);
$U = $PAY_USER_ID;
switch (pay_op()) {
    case 'create':
        $res = PayRuns::create($DB, $C, $U, $in);
        pay_json($res);
    case 'recalc':
        $n = PayRuns::recalc($DB, $C, $U, $id);
        pay_json(['people' => $n]);
    case 'add_people':
        $n = PayRuns::addPeople($DB, $C, $U, $id, array_map('intval', (array)($in['employee_ids'] ?? [])));
        pay_json(['added' => $n]);
    case 'remove_person':
        PayRuns::removePerson($DB, $C, $U, $id, pay_int($in['employee_id'] ?? 0));
        pay_json();
    case 'adjust':
        $res = PayRuns::addAdjustment($DB, $C, $U, $id, pay_int($in['employee_id'] ?? 0), $in);
        pay_json(['added' => $res, 'person' => PayRuns::person($DB, $C, $id, pay_int($in['employee_id'] ?? 0))]);
    case 'adjust_remove':
        $eid = pay_int($in['employee_id'] ?? 0);
        PayRuns::removeAdjustment($DB, $C, $U, $id, pay_int($in['line_id'] ?? 0));
        pay_json($eid ? ['person' => PayRuns::person($DB, $C, $id, $eid)] : []);
    case 'rename':
        PayRuns::rename($DB, $C, $U, $id, PayKit::key($in['name'] ?? ''), PayKit::key($in['notes'] ?? ''));
        pay_json();
    case 'approve':
        PayRuns::approve($DB, $C, $U, $id);
        pay_json();
    case 'reopen':
        PayRuns::reopen($DB, $C, $U, $PAY_ROLE, $id);
        pay_json();
    case 'finalise':
        pay_rate_limit('pay-finalise', 20, 600);
        pay_json(['result' => PayRuns::finalise($DB, $C, $U, $id, !empty($in['email']))]);
    case 'post':
        PayRuns::post($DB, $C, $U, $id);
        pay_json();
    case 'delete':
        PayRuns::delete($DB, $C, $U, $id);
        pay_json();
    case 'email':
        pay_rate_limit('pay-email', 10, 600);
        $eid = pay_int($in['employee_id'] ?? 0) ?: null;
        pay_json(['result' => PayPayslips::emailAll($DB, $C, $U, $id, empty($in['resend']), $eid)]);
}
pay_error('Unknown action');
