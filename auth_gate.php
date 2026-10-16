<?php
// auth_gate.php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/companies.php';

// Emit JSON (not a 302 redirect) for XHR / JSON callers so an expired session
// inside a fetch() surfaces as a proper 401 instead of the browser following the
// redirect to the HTML login page and the client throwing a misleading "Network
// error" when it tries to JSON-parse it. Page loads (no XHR/JSON signal) still
// redirect as before.
if (!function_exists('fw_wants_json')) {
    function fw_wants_json(): bool {
        $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        return $xhr || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }
}
if (!function_exists('fw_auth_fail')) {
    function fw_auth_fail(string $redirectUrl): void {
        if (fw_wants_json()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please sign in again.']);
            exit;
        }
        if (function_exists('redirect')) { redirect($redirectUrl); }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

if (empty($_SESSION['user_id'])) {
  // "Keep me signed in": bring this device back with its own remember cookie.
  try {
    fw_auth_restore($DB);
  } catch (Throwable $e) {
    error_log('[auth] remember-me restore failed: ' . $e->getMessage());
  }

  if (empty($_SESSION['user_id'])) {
    $notice = (string)($_SESSION['auth_notice'] ?? '');
    fw_auth_fail('/login.php?' . ($notice !== '' ? 'msg=' . urlencode($notice) . '&' : '')
               . 'redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/home.php'));
  }
}

// The device itself was verified in init.php (fw_auth_check). Signing in on
// another device no longer ends this one; see includes/auth_sessions.php.
$stmt = $DB->prepare("SELECT company_id, status FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
  fw_auth_end($DB, 'invalid_user');
  fw_auth_fail('/login.php?msg=invalid_user');
}

// Suspended user: end every device, not just this one.
if ($user['status'] !== 'active') {
  fw_auth_revoke_all($DB, (int)$_SESSION['user_id'], 'suspended');
  fw_auth_end($DB, 'suspended');
  fw_auth_fail('/login.php?msg=account_suspended');
}

// Set company context — honour an active-company switch if the user
// still has access via user_companies, otherwise fall back to their
// primary company on users.company_id.
$_SESSION['company_id'] = fw_resolve_active_company_id(
    (int)$_SESSION['user_id'],
    (int)$user['company_id']
);

// Refresh per-tenant role into the session so legacy callers that read
// $_SESSION['role'] (projects, finance permissions, etc.) see the role
// for the *active* company, including admin promotions made via
// /admin/users.php. Falls back to users.role for legacy accounts that
// pre-date user_companies.
$_SESSION['role'] = fw_user_role_in_company(
    (int)$_SESSION['user_id'],
    (int)$_SESSION['company_id']
) ?? 'viewer';

// CSRF protection for state-changing requests
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    Csrf::validate();
}