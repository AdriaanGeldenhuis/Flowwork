<?php
// /crm/api/contacts.php — people at customers and suppliers.
//   GET   q= &type= &sort=name|account|recent &page=      the directory across all accounts
//   POST  {op: save, id?, account_id, first_name, last_name, role, email, phone, mobile, notes, primary, custom{}}
//         {op: delete, id} · {op: primary, id}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';

if (!crm_is_post()) {
    crm_json(CrmAccounts::directory($DB, $CRM_COMPANY_ID, $_GET));
}
crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        $id = CrmAccounts::saveContact($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $in);
        $st = $DB->prepare("SELECT * FROM crm_contacts WHERE id = ?");
        $st->execute([$id]);
        crm_json(['contact' => CrmAccounts::contactShape($st->fetch(PDO::FETCH_ASSOC))]);
    case 'delete':
        CrmAccounts::deleteContact($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_int($in['id'] ?? 0));
        crm_json();
    case 'primary':
        $st = $DB->prepare("SELECT * FROM crm_contacts WHERE id = ? AND company_id = ?");
        $st->execute([crm_int($in['id'] ?? 0), $CRM_COMPANY_ID]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new CrmUserError('Contact not found.');
        Crm::atomic($DB, function () use ($DB, $CRM_COMPANY_ID, $c) {
            $DB->prepare("UPDATE crm_contacts SET is_primary = 0 WHERE company_id = ? AND account_id = ?")->execute([$CRM_COMPANY_ID, (int)$c['account_id']]);
            $DB->prepare("UPDATE crm_contacts SET is_primary = 1 WHERE id = ? AND company_id = ?")->execute([(int)$c['id'], $CRM_COMPANY_ID]);
        });
        crm_json();
}
crm_error('Unknown action');
