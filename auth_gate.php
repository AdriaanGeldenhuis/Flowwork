<?php
// auth_gate.php
require_once __DIR__ . '/init.php';

if (empty($_SESSION['user_id'])) {
  // Try auto-login via "Remember Me" cookie
  if (!empty($_COOKIE['fw_remember'])) {
    $tokenHash = hash('sha256', $_COOKIE['fw_remember']);
    $stmt = $DB->prepare(
      "SELECT rt.user_id, u.company_id, u.status, u.first_name, u.last_name
       FROM remember_tokens rt
       JOIN users u ON u.id = rt.user_id
       WHERE rt.token_hash = ? AND rt.expires_at > NOW()"
    );
    $stmt->execute([$tokenHash]);
    $rem = $stmt->fetch();

    if ($rem && $rem['status'] === 'active') {
      // Check subscription
      $stmt2 = $DB->prepare("SELECT subscription_active FROM companies WHERE id = ?");
      $stmt2->execute([$rem['company_id']]);
      $comp = $stmt2->fetch();

      if ($comp && $comp['subscription_active']) {
        // Restore session
        session_regenerate_id(true);
        $_SESSION['user_id']         = (int)$rem['user_id'];
        $_SESSION['company_id']      = (int)$rem['company_id'];
        $_SESSION['user_first_name'] = $rem['first_name'];
        $_SESSION['user_last_name']  = $rem['last_name'];

        // Issue new session token
        $sessToken = bin2hex(random_bytes(32));
        $_SESSION['sess_token'] = $sessToken;
        $DB->prepare("UPDATE users SET session_token = ?, last_login_at = NOW() WHERE id = ?")
           ->execute([$sessToken, $rem['user_id']]);

        // Rotate remember token for security
        $newRememberToken = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newRememberToken);
        $DB->prepare("UPDATE remember_tokens SET token_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE user_id = ?")
           ->execute([$newTokenHash, $rem['user_id']]);

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('fw_remember', $newRememberToken, [
          'expires'  => time() + (7 * 24 * 60 * 60),
          'path'     => '/',
          'secure'   => $https,
          'httponly'  => true,
          'samesite' => 'Lax',
        ]);

        // Continue to the requested page (don't redirect, fall through to normal auth checks below)
      }
    }
  }

  // If still no session after remember-me attempt, redirect to login
  if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
  }
}

// Single-session enforcement: check DB token matches session
$stmt = $DB->prepare("SELECT session_token, company_id, status 
                      FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
  session_unset();
  session_destroy();
  redirect('/login.php?msg=invalid_user');
}

// Token mismatch = logged in elsewhere
if (empty($_SESSION['sess_token']) || $user['session_token'] !== $_SESSION['sess_token']) {
  session_unset();
  session_destroy();
  redirect('/login.php?msg=session_expired');
}

// Suspended user
if ($user['status'] !== 'active') {
  session_unset();
  session_destroy();
  redirect('/login.php?msg=account_suspended');
}

// Set company context
$_SESSION['company_id'] = (int)$user['company_id'];

// CSRF protection for state-changing requests
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    Csrf::validate();
}