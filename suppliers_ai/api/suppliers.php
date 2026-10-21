<?php
// /suppliers_ai/api/suppliers.php — the suppliers in a search.
//   POST op=add {candidate_id, link?, confirm?}    a Google result becomes a CRM supplier (a prospect), or is linked to one you have
//   POST op=log {candidate_id, action}             call | whatsapp | email | website | directions | view | shortlist
//   GET  op=crm&q=                                 CRM suppliers, for adding to a request by hand
require_once __DIR__ . '/_bootstrap.php';

switch (sa_op()) {
    case 'add':
        sa_post_only();
        $in = sa_input();
        sa_json(SaSearch::addToCrm($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, sa_int($in['candidate_id'] ?? 0), sa_int($in['link'] ?? 0), !empty($in['confirm'])));
    case 'log':
        sa_post_only();
        $in = sa_input();
        SaSearch::log($DB, $SA_COMPANY_ID, $SA_USER_ID, sa_int($in['candidate_id'] ?? 0), (string)($in['action'] ?? ''));
        sa_json();
    case 'crm':
        sa_json(['suppliers' => SaSearch::crmLookup($DB, $SA_COMPANY_ID, (string)($_GET['q'] ?? ''))]);
}
sa_error('Unknown request.', 404);
