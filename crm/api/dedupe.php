<?php
// /crm/api/dedupe.php — duplicate customers and suppliers (admin).
//   GET   open suggestions
//   GET   op=compare &left= &right=      both accounts side by side
//   POST  {op: scan, type} · {op: merge, keep, other, pick{field: keep|other}, candidate} · {op: dismiss, id}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmDedupe.php';

crm_require_admin();
if (!crm_is_post()) {
    if (($_GET['op'] ?? '') === 'compare') {
        crm_json(['accounts' => CrmDedupe::compare($DB, $CRM_COMPANY_ID, crm_int($_GET['left'] ?? 0), crm_int($_GET['right'] ?? 0))]);
    }
    crm_json(['candidates' => CrmDedupe::candidates($DB, $CRM_COMPANY_ID)]);
}
$in = crm_input();
switch (crm_op()) {
    case 'scan':
        crm_rate_limit('crm_dedupe_scan', 6, 300);
        @set_time_limit(120);
        $r = CrmDedupe::scan($DB, $CRM_COMPANY_ID, (string)($in['type'] ?? 'all'));
        crm_json($r + ['candidates' => CrmDedupe::candidates($DB, $CRM_COMPANY_ID)]);
    case 'merge':
        CrmDedupe::merge($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_int($in['keep'] ?? 0), crm_int($in['other'] ?? 0), (array)($in['pick'] ?? []), crm_int($in['candidate'] ?? 0));
        crm_json(['candidates' => CrmDedupe::candidates($DB, $CRM_COMPANY_ID)]);
    case 'dismiss':
        CrmDedupe::dismiss($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0));
        crm_json(['candidates' => CrmDedupe::candidates($DB, $CRM_COMPANY_ID)]);
}
crm_error('Unknown action');
