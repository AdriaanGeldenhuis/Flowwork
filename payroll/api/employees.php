<?php
// /payroll/api/employees.php — the people on the payroll.
//   GET  ?op=list&status=active|left|all&frequency=&q=     → {rows, counts}
//   GET  ?op=get&id=                                       → {employee}
//   GET  ?op=new                                           → {next_number}
//   POST {op:'save', …fields}                              → {id, created, runs, employee}
//   POST {op:'terminate', id, termination_date, termination_reason, note}
//   POST {op:'reinstate', id} | {op:'delete', id}
//   POST {op:'item_save', employee_id, id?, payitem_id, unit, amount|percent, from, to}
//   POST {op:'item_remove', employee_id, id}
// After a change, people in runs being prepared are worked out again; the
// answer's "runs" says which runs changed (or still hold old figures).
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
if (!pay_is_post()) {
    switch (pay_op() ?: 'list') {
        case 'list': pay_json(PayEmployees::list($DB, $C, $_GET));
        case 'get': pay_json(['employee' => PayEmployees::get($DB, $C, pay_int($_GET['id'] ?? 0))]);
        case 'new': pay_json(['next_number' => PayEmployees::nextNumber($DB, $C), 'settings' => ['frequency' => PayKit::settings($DB, $C)['default_frequency']]]);
    }
    pay_error('Unknown action');
}

$in = pay_input();
$id = pay_int($in['id'] ?? 0);
switch (pay_op()) {
    case 'save':
        [$id, $created, $runs] = PayEmployees::save($DB, $C, $PAY_USER_ID, $in);
        pay_json(['id' => $id, 'created' => $created, 'runs' => $runs, 'employee' => PayEmployees::get($DB, $C, $id)]);
    case 'terminate':
        $runs = PayEmployees::terminate($DB, $C, $PAY_USER_ID, $id, trim((string)($in['termination_date'] ?? '')), (string)($in['termination_reason'] ?? ''), trim((string)($in['note'] ?? '')));
        pay_json(['runs' => $runs, 'employee' => PayEmployees::get($DB, $C, $id)]);
    case 'reinstate':
        $runs = PayEmployees::reinstate($DB, $C, $PAY_USER_ID, $id);
        pay_json(['runs' => $runs, 'employee' => PayEmployees::get($DB, $C, $id)]);
    case 'delete':
        PayEmployees::delete($DB, $C, $PAY_USER_ID, $id);
        pay_json();
    case 'item_save':
        $eid = pay_int($in['employee_id'] ?? 0);
        $runs = PayEmployees::saveItem($DB, $C, $PAY_USER_ID, $eid, $in);
        pay_json(['runs' => $runs, 'items' => PayEmployees::items($DB, $C, $eid)]);
    case 'item_remove':
        $eid = pay_int($in['employee_id'] ?? 0);
        $runs = PayEmployees::removeItem($DB, $C, $PAY_USER_ID, $eid, $id);
        pay_json(['runs' => $runs, 'items' => PayEmployees::items($DB, $C, $eid)]);
}
pay_error('Unknown action');
