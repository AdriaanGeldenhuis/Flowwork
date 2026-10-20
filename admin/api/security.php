<?php
// /admin/api/security.php — sign a single device of a company member out.
//   POST {action:'signout_device', session_id}
// Only devices of accounts that belong to this company (a guest's devices are
// their own company's business). Signing a person out everywhere is
// /admin/api/people.php action=signout.
require_once __DIR__ . '/_bootstrap.php';

admin_require_post();
$in = admin_input();
if (admin_str($in, 'action', 30) !== 'signout_device') admin_error('Unknown action');
admin_rate_limit('admin-security', 120, 600);

$sid = admin_int($in, 'session_id');
$st = $DB->prepare("SELECT s.id, s.user_id, s.device_label, CONCAT_WS(' ', u.first_name, u.last_name) AS name
                      FROM user_sessions s JOIN users u ON u.id = s.user_id AND u.company_id = ?
                     WHERE s.id = ? AND s.revoked_at IS NULL");
$st->execute([$ADMIN_COMPANY_ID, $sid]);
$dev = $st->fetch(PDO::FETCH_ASSOC);
if (!$dev) admin_error('That device is already signed out.', 404);
if ((int)$dev['id'] === (int)($_SESSION['auth_sid'] ?? 0)) admin_error('This is the device you are using. Use Sign out in the menu instead.');

fw_auth_revoke($DB, (int)$dev['user_id'], (int)$dev['id'], 'signed_out');
AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'device_signed_out', ['person' => trim((string)$dev['name']), 'device' => $dev['device_label']], 'user', (int)$dev['user_id']);
admin_json();
