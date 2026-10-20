<?php
// /admin/api/settings.php — company settings drawn from the registry in
// /admin/lib/AdminSettings.php.
//   GET  ?area=calendar                      → {values}
//   POST {area, values: {key: value}}        → {changed: [keys], values}
// Every value is checked against the registry; a bad one answers 422 with
// {errors: {key: message}} and nothing is saved.
require_once __DIR__ . '/_bootstrap.php';

if (!admin_is_post()) {
    $area = (string)($_GET['area'] ?? '');
    admin_json(['values' => AdminSettings::load($DB, $ADMIN_COMPANY_ID, $area)]);
}

$in = admin_input();
$area = admin_str($in, 'area', 30);
$values = is_array($in['values'] ?? null) ? $in['values'] : [];
if (!$values) admin_error('Nothing to save.');
admin_rate_limit('admin-settings', 120, 600);

$res = AdminSettings::save($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $ADMIN_ROLE, $area, $values);
admin_json($res);
