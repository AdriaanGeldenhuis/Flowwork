<?php
// /finances/api/payroll.php — pay runs in the books and what is owed to SARS (EMP201).
//   GET  ?op=runs&status=all|to_post|posted|open
//   POST {op: post, id}   post a locked run to the books
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinPayroll.php';

fin_require_module('payroll');
$C = $FIN_COMPANY_ID;

if (!fin_is_post()) {
    if (($_GET['op'] ?? 'runs') === 'runs') fin_json(FinPayroll::runs($DB, $C, $_GET));
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
if (fin_op() === 'post') {
    FinPayroll::post($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0));
    fin_json(FinPayroll::runs($DB, $C, []));
}
fin_error('Unknown action');
