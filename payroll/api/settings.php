<?php
// /payroll/api/settings.php — payroll settings and tax tables.
//   GET  ?op=get                     → {settings, tax}
//   POST {op:'save', …values}        → admins only; only the keys sent are changed
//   POST {op:'tax_save', from, brackets: [{from, rate}], rebate_primary, rebate_secondary, rebate_tertiary, medical_main, medical_extra, uif_month}
//   POST {op:'tax_delete', from}     → back to Flowwork's table for that year
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
if (!pay_is_post()) {
    if ((pay_op() ?: 'get') === 'get') pay_json(['settings' => PaySettings::load($DB, $C), 'tax' => PaySettings::taxTables($DB, $C)]);
    pay_error('Unknown action');
}
pay_require_admin();
$in = pay_input();
switch (pay_op()) {
    case 'save':
        unset($in['op']);
        pay_json(['settings' => PaySettings::save($DB, $C, $PAY_USER_ID, $in)]);
    case 'tax_save':
        PaySettings::saveTaxTable($DB, $C, $PAY_USER_ID, $in);
        pay_json(['tax' => PaySettings::taxTables($DB, $C)]);
    case 'tax_delete':
        PaySettings::deleteTaxTable($DB, $C, $PAY_USER_ID, PayKit::key($in['from'] ?? ''));
        pay_json(['tax' => PaySettings::taxTables($DB, $C)]);
}
pay_error('Unknown action');
