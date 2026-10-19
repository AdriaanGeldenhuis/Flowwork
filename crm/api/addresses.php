<?php
// POST /crm/api/addresses.php — {op: save, id?, account_id, type, line1, line2, city, region, postal_code, country} · {op: delete, id}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';

crm_require_post();
crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_json(['id' => CrmAccounts::saveAddress($DB, $CRM_COMPANY_ID, $in)]);
    case 'delete':
        CrmAccounts::deleteAddress($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0));
        crm_json();
}
crm_error('Unknown action');
