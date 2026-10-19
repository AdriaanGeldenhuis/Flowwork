<?php
// /crm/api/compliance.php — compliance documents and policy.
//   GET   (no id)            the company-wide picture: counts, accounts by state, documents expiring
//   GET   op=download&id=    the file (company-checked; also serves supplier-portal uploads)
//   POST  {op: save, id?, account_id, type_id, reference, issue_date, expiry_date, notes, auto_expiry} + file (multipart)
//         {op: delete, id}
//         {op: type_save, id?, name, code, expiry_months, required} · {op: type_delete, id}     admin
//         {op: policy, block, reminder_days, badge} · {op: seed}                                 admin
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmCompliance.php';

if (!crm_is_post()) {
    if (($_GET['op'] ?? '') === 'download') {
        $f = CrmCompliance::download($DB, $CRM_COMPANY_ID, crm_int($_GET['id'] ?? 0));
        if (!$f) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This document has no file, or the file is missing on the server.';
            exit;
        }
        [$path, $name, $mime] = $f;
        $inline = ($_GET['inline'] ?? '') === '1' && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($path);
        exit;
    }
    crm_json(CrmCompliance::overview($DB, $CRM_COMPANY_ID));
}

crm_require_writer();
$in = crm_input();
switch (crm_op()) {
    case 'save':
        crm_rate_limit('crm_compliance_save', 60, 60);
        $id = CrmCompliance::saveDoc($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $in, $_FILES['file'] ?? null);
        crm_json(['id' => $id]);
    case 'delete':
        CrmCompliance::deleteDoc($DB, $CRM_COMPANY_ID, $CRM_USER_ID, crm_int($in['id'] ?? 0));
        crm_json();
    case 'type_save':
        crm_require_admin();
        crm_json(['id' => CrmCompliance::saveType($DB, $CRM_COMPANY_ID, $in), 'types' => CrmCompliance::types($DB, $CRM_COMPANY_ID)]);
    case 'type_delete':
        crm_require_admin();
        CrmCompliance::deleteType($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0));
        crm_json(['types' => CrmCompliance::types($DB, $CRM_COMPANY_ID)]);
    case 'seed':
        crm_require_admin();
        CrmCompliance::seedTypes($DB, $CRM_COMPANY_ID);
        crm_json(['types' => CrmCompliance::types($DB, $CRM_COMPANY_ID)]);
    case 'policy':
        crm_require_admin();
        $days = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,;\s]+/', (string)($in['reminder_days'] ?? ''))), fn($d) => $d > 0 && $d <= 365)));
        rsort($days);
        if (!$days) throw new CrmUserError('Give at least one reminder day, for example 30, 14, 7.');
        Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_block_expired_suppliers', !empty($in['block']) ? '1' : '0');
        Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_reminder_days', implode(',', array_slice($days, 0, 6)));
        if (array_key_exists('badge', $in)) Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_compliance_badge_enable', !empty($in['badge']) ? '1' : '0');
        Crm::audit($DB, $CRM_COMPANY_ID, $CRM_USER_ID, 'update', 'crm_compliance_policy', $CRM_COMPANY_ID, ['block' => !empty($in['block']), 'days' => $days]);
        @unlink(dirname(__DIR__, 2) . '/storage/crm/.compliance-' . $CRM_COMPANY_ID . '-' . date('Ymd'));
        crm_json(['policy' => CrmCompliance::policy($DB, $CRM_COMPANY_ID)]);
}
crm_error('Unknown action');
