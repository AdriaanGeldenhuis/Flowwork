<?php
// /admin/api/companies.php — add another company under your account.
//   POST {action:'create', name, business_type} → {id}
// The new company takes the plan and limits of your own (primary) company,
// gets its finance foundation and becomes the active company.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/business_types.php';

admin_require_post();
$in = admin_input();
if (admin_str($in, 'action', 20) !== 'create') admin_error('Unknown action');
admin_rate_limit('admin-company-create', 10, 3600);

$name = admin_str($in, 'name', 255);
$type = admin_str($in, 'business_type', 30);
$errors = [];
if ($name === '') $errors['name'] = 'Enter the company’s name.';
if (!in_array($type, fw_business_type_keys(), true)) $errors['business_type'] = 'Choose a kind of business.';
if ($errors) throw new AdminInvalid($errors);

try {
    $newId = fw_create_company_for_user($ADMIN_USER_ID, $name, $type);
} catch (Exception $e) {
    // Plan limits and the like come back as plain sentences.
    admin_error($e->getMessage(), 409);
}
fw_set_active_company($ADMIN_USER_ID, $newId);
AdminKit::audit($DB, $newId, $ADMIN_USER_ID, 'company_create', ['name' => $name, 'from company' => $ADMIN_COMPANY_ID], 'company', $newId);
admin_json(['id' => $newId]);
