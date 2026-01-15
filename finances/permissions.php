<?php
// permissions.php – shared finance role helper

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php'; // ensures $_SESSION has user_id/company_id

/**
 * Get current user's role. If it's not in the session, fetch from DB and cache.
 */
function fw_get_user_role(PDO $DB): string {
    if (empty($_SESSION['role'])) {
        $stmt = $DB->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $role = $stmt->fetchColumn();
        $_SESSION['role'] = $role ? strtolower(trim($role)) : 'member';
    }
    return strtolower((string)$_SESSION['role']);
}

/**
 * Enforce role access. Allow if user role is in $allowed OR user is admin.
 * Redirects to /finances/access_denied.php if not allowed.
 */
function requireRoles(array $allowed): void {
    global $DB; // from init.php
    $userRole = fw_get_user_role($DB);

    // normalize allowed array to lowercase
    $allowed = array_map(static fn($r) => strtolower(trim($r)), $allowed);

    // admin always allowed; otherwise must be in list
    if ($userRole === 'admin' || in_array($userRole, $allowed, true)) {
        return;
    }

    $need = implode(', ', $allowed);
    header('Location: /finances/access_denied.php?need=' . urlencode($need));
    exit;
}
