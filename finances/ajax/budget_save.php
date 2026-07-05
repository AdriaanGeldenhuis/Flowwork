<?php
// /finances/ajax/budget_save.php
//
// LEGACY ENDPOINT — permanently disabled (410 Gone).
//
// This endpoint had no CSRF validation and no company-ownership check on
// account_id (it inserted arbitrary gl_account_id values into gl_budgets).
// A repo-wide search found no client callers: the budgets page
// (/finances/budgets/edit.php) posts to the replacement endpoint
// /finances/budgets/api/save.php, which validates account ownership,
// enforces period locks and scopes everything by company_id.
//
// Use /finances/budgets/api/save.php instead.

// Bootstrap session/auth in the standard order so the response is only served
// to authenticated app users (init.php starts the FLOWWORKSESSID session).
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}

http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'ok'    => false,
    'error' => 'This endpoint has been removed. Use /finances/budgets/api/save.php instead.'
]);
exit;
