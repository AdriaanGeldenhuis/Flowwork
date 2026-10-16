<?php
// logout.php — signs out THIS device only. Other phones, tablets and
// computers stay signed in; they can be signed out from /account/.
require_once __DIR__ . '/init.php';

if (!empty($_SESSION['user_id'])) {
  // Log the logout
  try {
    $stmt = $DB->prepare("INSERT INTO audit_log
                          (company_id, user_id, action, details, ip, created_at)
                          SELECT company_id, id, 'user_logout', ?, ?, NOW()
                          FROM users WHERE id = ?");
    $stmt->execute([
      json_encode(['method' => 'manual', 'device' => fw_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))]),
      $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      $_SESSION['user_id']
    ]);
  } catch (Exception $e) {
    error_log("Logout audit log error: " . $e->getMessage());
  }
}

// End this device's session row, its "keep me signed in" cookie and the PHP session.
try {
  fw_auth_end($DB, 'logout');
} catch (Throwable $e) {
  error_log('[auth] logout failed: ' . $e->getMessage());
  $_SESSION = [];
  if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

redirect('/login.php?msg=logged_out');
