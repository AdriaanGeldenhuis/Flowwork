<?php
// /crm/api/deals.php — the pipeline.
//   GET   (board / list) q= &owner=me|id &account= &close=overdue|month|quarter|none &include=board|open|all|won|lost &closed_days=
//   GET   id=…   one deal with its activity, tasks, history, project, contacts and custom values
//   POST  {op: save, id?, account_id, title, amount, stage, probability, close_date, owner_id, contact_id, notes, loss_reason, custom{}}
//         {op: move, id, stage, order[], loss_reason?} · {op: delete, id}
//         {op: convert, id, template, name?, start_date?, end_date?}   won deal → project
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmDeals.php';
require_once __DIR__ . '/../lib/CrmActivity.php';
require_once __DIR__ . '/../lib/CrmTasks.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';

if (!crm_is_post()) {
    $id = crm_int($_GET['id'] ?? 0);
    if ($id > 0) {
        $deal = CrmDeals::get($DB, $CRM_COMPANY_ID, $id);
        if (!$deal) crm_error('Deal not found', 404);
        $st = $DB->prepare("SELECT * FROM crm_contacts WHERE company_id = ? AND account_id = ? ORDER BY is_primary DESC, first_name");
        $st->execute([$CRM_COMPANY_ID, $deal['account_id']]);
        $defs = CrmFields::defs($DB, $CRM_COMPANY_ID, 'deal');
        $acc = CrmAccounts::row($DB, $CRM_COMPANY_ID, $deal['account_id']);
        crm_json([
            'deal' => $deal,
            'can_edit' => CrmDeals::canEdit(['owner_id' => $deal['owner_id']], $CRM_USER_ID, $CRM_ROLE) && crm_can_write(),
            'account' => ['id' => (int)$acc['id'], 'name' => $acc['name'], 'type' => $acc['type'], 'email' => (string)$acc['email'], 'phone' => (string)$acc['phone']],
            'contacts' => array_map([CrmAccounts::class, 'contactShape'], $st->fetchAll(PDO::FETCH_ASSOC)),
            'activity' => CrmActivity::forDeal($DB, $CRM_COMPANY_ID, $id),
            'tasks' => CrmTasks::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, ['scope' => 'all', 'deal' => $id, 'when' => 'all'])['rows'],
            'history' => CrmDeals::history($DB, $CRM_COMPANY_ID, $id),
            'project' => CrmDeals::project($DB, $CRM_COMPANY_ID, $id),
            'project_templates' => CrmDeals::templates(),
            'custom' => CrmFields::valuesFor($DB, $CRM_COMPANY_ID, $defs, $id),
        ]);
    }
    crm_json(CrmDeals::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $_GET));
}

crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_rate_limit('crm_deal_save', 120, 60);
        crm_json(['deal' => CrmDeals::save($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $in)]);
    case 'move':
        crm_json(['deal' => CrmDeals::move($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0), (string)($in['stage'] ?? ''),
                                           (array)($in['order'] ?? []), array_key_exists('loss_reason', $in) ? (string)$in['loss_reason'] : null)]);
    case 'delete':
        CrmDeals::delete($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0));
        crm_json();
    case 'convert':
        crm_rate_limit('crm_deal_convert', 10, 60);
        crm_json(CrmDeals::convert($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, crm_int($in['id'] ?? 0), $in));
}
crm_error('Unknown action');
