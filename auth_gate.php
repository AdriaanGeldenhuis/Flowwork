<?php
// auth_gate.php
require_once __DIR__ . '/init.php';

if (empty($_SESSION['user_id'])) {
  header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
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