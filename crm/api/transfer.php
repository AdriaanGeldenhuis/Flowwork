<?php
// /crm/api/transfer.php — import and export (admin).
//   GET   op=export &what=customers|suppliers|contacts|deals|tasks|activity [&status= &tag= &ids=1,2]   CSV download
//   GET   op=template                                  a CSV to fill in
//   GET   op=history                                   recent imports
//   POST  {op: parse} + file (multipart) or {op: parse, text, name}   → columns, sample rows, suggested mapping
//         {op: run, token, type, mapping{}, existing: skip|update|add, dry}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmTransfer.php';

crm_require_admin();

if (!crm_is_post()) {
    $op = (string)($_GET['op'] ?? '');
    if ($op === 'export' || $op === 'template') {
        if ($op === 'template') {
            $name = 'crm-import-template.csv';
            $csv = CrmTransfer::templateCsv($DB, $CRM_COMPANY_ID);
        } else {
            crm_rate_limit('crm_export', 20, 300);
            $p = $_GET;
            if (!empty($p['ids'])) $p['ids'] = array_slice(array_filter(array_map('intval', explode(',', (string)$p['ids']))), 0, 5000);
            [$name, $csv] = CrmTransfer::export($DB, $CRM_COMPANY_ID, (string)($_GET['what'] ?? ''), $p);
            Crm::audit($DB, $CRM_COMPANY_ID, $CRM_USER_ID, 'crm_export', 'crm_export', 0, ['what' => (string)($_GET['what'] ?? '')]);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }
    crm_json(['history' => CrmTransfer::history($DB, $CRM_COMPANY_ID), 'targets' => CrmTransfer::TARGETS, 'fields' => CrmFields::defs($DB, $CRM_COMPANY_ID, 'account')]);
}

$in = crm_input();
switch (crm_op()) {
    case 'parse':
        crm_rate_limit('crm_import_parse', 20, 300);
        if (!empty($_FILES['file'])) crm_json(CrmTransfer::parse($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $_FILES['file']));
        $text = (string)($in['text'] ?? '');
        if (trim($text) === '') throw new CrmUserError('Choose a CSV file or paste the rows.');
        if (strlen($text) > 5 * 1024 * 1024) throw new CrmUserError('That is too much text (5 MB at most).');
        crm_json(CrmTransfer::parseText($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $text, (string)($in['name'] ?? 'pasted.csv')));
    case 'run':
        crm_rate_limit('crm_import_run', 20, 300);
        @set_time_limit(300);
        crm_json(CrmTransfer::run($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE, $in));
}
crm_error('Unknown action');
