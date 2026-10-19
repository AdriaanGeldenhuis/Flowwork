<?php
// /crm/api/tags.php — the company's tags.
//   GET   all tags with how many accounts carry each
//   POST  {op: save, id, name, color} · {op: delete, id}      (admin)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';

if (!crm_is_post()) crm_json(['tags' => CrmAccounts::allTags($DB, $CRM_COMPANY_ID)]);
crm_require_admin();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        CrmAccounts::saveTag($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0), (string)($in['name'] ?? ''), (string)($in['color'] ?? ''));
        crm_json(['tags' => CrmAccounts::allTags($DB, $CRM_COMPANY_ID)]);
    case 'delete':
        CrmAccounts::deleteTag($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0));
        crm_json(['tags' => CrmAccounts::allTags($DB, $CRM_COMPANY_ID)]);
}
crm_error('Unknown action');
