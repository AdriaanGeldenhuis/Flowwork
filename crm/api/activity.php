<?php
// /crm/api/activity.php — notes, calls, emails, meetings, visits, WhatsApps and issues.
//   GET   type= &by=me|id &account_type= &q= &before= &limit=   the company-wide feed
//   POST  {op: save, id?, account_id, type, subject, body, contact_id, opportunity_id, at, pinned, followup{}}
//         {op: pin, id, on} · {op: delete, id}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmActivity.php';

if (!crm_is_post()) {
    crm_json(CrmActivity::feed($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $_GET));
}
crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_rate_limit('crm_activity', 120, 60);
        crm_json(CrmActivity::save($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $in));
    case 'pin':
        // Anyone who can write may pin or unpin a note on the account.
        $st = $DB->prepare("UPDATE crm_interactions SET pinned = ? WHERE id = ? AND company_id = ?");
        $st->execute([!empty($in['on']) ? 1 : 0, crm_int($in['id'] ?? 0), $CRM_COMPANY_ID]);
        crm_json();
    case 'delete':
        CrmActivity::delete($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0));
        crm_json();
}
crm_error('Unknown action');
