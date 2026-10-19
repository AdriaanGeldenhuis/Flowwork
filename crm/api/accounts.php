<?php
// /crm/api/accounts.php — customer and supplier lists and changes.
//   GET   type=customer|supplier &status= &q= &tag= &industry= &region= &owner= &sort= &dir= &page= &per=
//   POST  {op: save, ...account}            create or update (returns duplicates instead when they need confirming)
//         {op: delete|restore, ids[]}       soft delete / restore
//         {op: purge, id}                   remove a deleted account for good (admin, nothing in the books)
//         {op: bulk, ids[], action, value}  status | owner | preferred | tag_add | tag_remove | delete | restore
//         {op: tag_add, id, name} · {op: tag_remove, id, tag_id} · {op: preferred, id, on}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';

if (!crm_is_post()) {
    crm_json(CrmAccounts::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $_GET));
}

crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_rate_limit('crm_account_save', 120, 60);
        $res = CrmAccounts::save($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $in);
        if (!empty($res['duplicates'])) crm_json(['duplicates' => $res['duplicates']]);
        $acc = CrmAccounts::row($DB, $CRM_COMPANY_ID, $res['id']);
        crm_json(['id' => $res['id'], 'created' => $res['created'], 'account' => CrmAccounts::decorate($DB, $CRM_COMPANY_ID, (string)$acc['type'], [$acc + ['last_activity' => null]])[0]]);
    case 'delete':
    case 'restore':
        $ids = array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0]));
        crm_json(['changed' => CrmAccounts::setDeleted($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $ids, crm_op() === 'delete')]);
    case 'purge':
        crm_require_admin();
        CrmAccounts::purge($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_int($in['id'] ?? 0));
        crm_json();
    case 'bulk':
        crm_json(['changed' => CrmAccounts::bulk($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, (array)($in['ids'] ?? []), (string)($in['action'] ?? ''), $in['value'] ?? null)]);
    case 'tag_add':
        if (Crm::rules($DB, $CRM_COMPANY_ID)['crm_enable_tags'] === '0') throw new CrmUserError('Tags are switched off in CRM settings.');
        crm_json(['tag' => CrmAccounts::addTag($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0), (string)($in['name'] ?? ''), $in['color'] ?? null)]);
    case 'tag_remove':
        CrmAccounts::removeTag($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0), crm_int($in['tag_id'] ?? 0));
        crm_json();
    case 'preferred':
        CrmAccounts::save($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, ['id' => crm_int($in['id'] ?? 0), 'preferred' => !empty($in['on']) ? 1 : 0]);
        crm_json();
}
crm_error('Unknown action');
