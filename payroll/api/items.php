<?php
// /payroll/api/items.php — pay items (earnings, deductions, reimbursements, benefits, contributions).
//   GET  ?op=list                                  → {rows, suggested}
//   POST {op:'save', id?, name, code, type, taxable, uif, sdl, pre_tax, irregular, source_code, gl, active}
//   POST {op:'remove', id}                         → {result: deleted|archived}
//   POST {op:'defaults', codes?}                   → add the usual South African items
// Pay items decide how everyone's pay is taxed, so changing them is for admins.
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
if (!pay_is_post()) {
    if ((pay_op() ?: 'list') === 'list') pay_json(PayItems::list($DB, $C) + ['accounts' => PayItems::chartCodes($DB, $C)]);
    pay_error('Unknown action');
}
pay_require_admin();
$in = pay_input();
switch (pay_op()) {
    case 'save':
        $id = PayItems::save($DB, $C, $PAY_USER_ID, $in);
        pay_json(['id' => $id] + PayItems::list($DB, $C));
    case 'remove':
        $res = PayItems::remove($DB, $C, $PAY_USER_ID, pay_int($in['id'] ?? 0));
        pay_json(['result' => $res] + PayItems::list($DB, $C));
    case 'defaults':
        $n = PayItems::addDefaults($DB, $C, $PAY_USER_ID, array_map('strval', (array)($in['codes'] ?? [])));
        pay_json(['added' => $n] + PayItems::list($DB, $C));
}
pay_error('Unknown action');
