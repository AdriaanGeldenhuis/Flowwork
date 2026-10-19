<?php
// /crm/api/email.php — email a customer or supplier through Mail.
//   GET   account_id= &contact_id=    recipients, mailboxes, templates
//   POST  {account_id, contact_id, opportunity_id, mailbox_id, to, cc, subject, body}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmMail.php';

if (!crm_is_post()) {
    crm_json(CrmMail::compose($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_int($_GET['account_id'] ?? 0), crm_int($_GET['contact_id'] ?? 0)));
}
crm_require_writer();
crm_rate_limit('crm_email_send', 30, 300);
$res = CrmMail::send($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_input());
if (!$res['ok']) crm_error((string)$res['error'], 422, ['email_id' => $res['email_id']]);
crm_json($res);
