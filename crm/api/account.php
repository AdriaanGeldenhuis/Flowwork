<?php
// GET /crm/api/account.php?id=… — one customer or supplier.
//   part=        (default) the account with contacts, addresses, money, health, compliance state, custom values
//   part=timeline &kind= &before=    the activity timeline
//   part=documents                   quotes, invoices, credit notes, recurring, bills, purchase orders, projects
//   part=deals                       the account's deals
//   part=tasks &when=                the account's tasks
//   part=compliance                  documents with live status
//   part=meetings                    upcoming calendar events linked to the account
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmAccounts.php';
require_once __DIR__ . '/../lib/CrmActivity.php';
require_once __DIR__ . '/../lib/CrmLinked.php';
require_once __DIR__ . '/../lib/CrmDeals.php';
require_once __DIR__ . '/../lib/CrmTasks.php';
require_once __DIR__ . '/../lib/CrmCompliance.php';

$id = crm_int($_GET['id'] ?? 0);
$row = CrmAccounts::row($DB, $CRM_COMPANY_ID, $id);
if (!$row) crm_error('Account not found', 404);
$part = (string)($_GET['part'] ?? '');

switch ($part) {
    case 'timeline':
        $row['created_by_name'] = Crm::personName($DB, $CRM_COMPANY_ID, $row['created_by'] !== null ? (int)$row['created_by'] : null);
        crm_json(CrmActivity::timeline($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $row, $_GET));
    case 'documents':
        crm_json(['documents' => CrmLinked::documents($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $row)]);
    case 'deals':
        crm_json(CrmDeals::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, ['account' => $id, 'include' => 'all']) + ['project_templates' => CrmDeals::templates()]);
    case 'tasks':
        crm_json(CrmTasks::list($DB, $CRM_COMPANY_ID, $CRM_USER_ID, ['scope' => 'all', 'account' => $id, 'when' => (string)($_GET['when'] ?? 'open')]));
    case 'compliance':
        CrmCompliance::refreshStored($DB, $CRM_COMPANY_ID);
        crm_json(['docs' => CrmCompliance::docs($DB, $CRM_COMPANY_ID, $id), 'state' => CrmCompliance::state($DB, $CRM_COMPANY_ID, $id, (string)$row['type']),
                  'types' => CrmCompliance::types($DB, $CRM_COMPANY_ID), 'policy' => CrmCompliance::policy($DB, $CRM_COMPANY_ID)]);
    case 'meetings':
        $events = [];
        try {
            require_once __DIR__ . '/../../calendar/lib/autoload.php';
            $events = CalendarLink::upcomingFor($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, 'account', $id, 6);
        } catch (Throwable $e) {
            error_log('[crm] meetings: ' . $e->getMessage());
        }
        crm_json(['events' => $events]);
}
crm_json(['account' => CrmAccounts::full($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $id)]);
