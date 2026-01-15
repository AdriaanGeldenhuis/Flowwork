<?php
require_once __DIR__ . '/init.php';

if (!empty($_SESSION['user_id'])) {
  // Log the logout
  try {
    $stmt = $DB->prepare("INSERT INTO audit_log 
                          (company_id, user_id, action, details, ip, created_at) 
                          SELECT company_id, id, 'user_logout', ?, ?, NOW() 
                          FROM users WHERE id = ?");
    $stmt->execute([
      json_encode(['method' => 'manual']),
      $_SERVER['REMOTE_ADDR'] ?? 'unknown',
      $_SESSION['user_id']
    ]);
  } catch (Exception $e) {
    error_log("Logout audit log error: " . $e->getMessage());
  }

  // Clear session token from database
  $DB->prepare("UPDATE users SET session_token = NULL WHERE id = ?")
     ->execute([$_SESSION['user_id']]);
}

// Destroy session
session_unset();
session_destroy();

// Destroy session cookie
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 3600, 
            $params['path'], $params['domain'], 
            $params['secure'], $params['httponly']);
}

// Clear remember me cookie if exists
if (isset($_COOKIE['fw_remember'])) {
  setcookie('fw_remember', '', time() - 3600, '/', '', true, true);
}

redirect('/login.php?msg=logged_out');