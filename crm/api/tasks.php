<?php
// /crm/api/tasks.php — follow-ups and to-dos.
//   GET   scope=mine|all|<user id> &when=open|overdue|today|week|later|nodate|done|all &account= &deal= &q=
//   POST  {op: save, id?, title, notes, due_date, due_time, priority, assigned_to, account_id, opportunity_id, contact_id, remind}
//         {op: done, id} · {op: undo, id} · {op: delete, id}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmTasks.php';

if (!crm_is_post()) {
    crm_json(CrmTasks::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $_GET));
}
crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_rate_limit('crm_task_save', 120, 60);
        crm_json(['task' => CrmTasks::save($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $in)]);
    case 'done':
    case 'undo':
        crm_json(['task' => CrmTasks::setDone($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0), crm_op() === 'done')]);
    case 'delete':
        CrmTasks::delete($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0));
        crm_json();
}
crm_error('Unknown action');
