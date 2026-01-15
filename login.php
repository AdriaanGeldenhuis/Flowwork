<?php
require_once __DIR__ . '/init.php';

if (!empty($_SESSION['user_id'])) redirect('/home.php');

$err = '';
$msg = '';

// Display messages
if (isset($_GET['msg'])) {
  $msgs = [
    'session_expired' => 'Your session expired. Please log in again.',
    'logged_out' => 'You have been logged out.',
    'account_suspended' => 'Your account is suspended.',
    'reset_success' => 'Password reset successful. Please log in.',
    'verified' => 'Email verified successfully. Please log in.',
  ];
  $msg = $msgs[$_GET['msg']] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $remember = isset($_POST['remember']);

  if ($email === '' || $pass === '') {
    $err = 'Please enter email and password.';
  } else {
    $stmt = $DB->prepare("SELECT id, email, password_hash, company_id, status, first_name, last_name
                          FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($pass, $u['password_hash'])) {
      $err = 'Invalid email or password.';
      
      // Log failed attempt
      $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
      error_log("Failed login attempt for email: {$email} from IP: {$ip}");
    } elseif ($u['status'] !== 'active') {
      $err = 'Your account is not active. Please contact support.';
    } else {
      // Check subscription status
      $stmt = $DB->prepare("SELECT subscription_active FROM companies WHERE id = ?");
      $stmt->execute([$u['company_id']]);
      $company = $stmt->fetch();

      if (!$company || !$company['subscription_active']) {
        $err = 'Your subscription is not active. Please contact support.';
      } else {
        // Successful login
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['company_id'] = (int)$u['company_id'];
        $_SESSION['user_first_name'] = $u['first_name'];
        $_SESSION['user_last_name'] = $u['last_name'];

        // Generate single-session token
        $token = bin2hex(random_bytes(32));
        $_SESSION['sess_token'] = $token;

        // Update DB
        $DB->prepare("UPDATE users 
                      SET session_token = ?, last_login_at = NOW() 
                      WHERE id = ?")
           ->execute([$token, $u['id']]);

        // Remember me cookie (optional)
        if ($remember) {
          $rememberToken = bin2hex(random_bytes(32));
          setcookie('fw_remember', $rememberToken, time() + (30 * 24 * 60 * 60), '/', '', true, true);
          // Store remember token in DB (you'd need to add a table for this)
        }

        // Log successful login
        $stmt = $DB->prepare("INSERT INTO audit_log 
                              (company_id, user_id, action, details, ip, created_at) 
                              VALUES (?, ?, 'user_login', ?, ?, NOW())");
        $stmt->execute([
          $u['company_id'], 
          $u['id'], 
          json_encode(['email' => $email]),
          $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $redir = $_GET['redirect'] ?? '/home.php';
        redirect($redir);
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In – Flowwork</title>
  <link rel="stylesheet" href="/shared/auth.css?v=<?= time() ?>">
</head>
<body class="fw-auth" data-theme="light">
  <!-- Theme Toggle -->
  <button class="fw-auth__theme-toggle" id="themeToggle" aria-label="Toggle theme" type="button">
    <svg class="fw-auth__theme-icon fw-auth__theme-icon--light" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
      <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </svg>
    <svg class="fw-auth__theme-icon fw-auth__theme-icon--dark" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>

  <!-- Card -->
  <div class="fw-auth__card">
    <!-- Logo -->
    <div class="fw-auth__logo">
      <img src="https://www.flowwork.app/assets/logo.webp" />
        <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12L4 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </img>
    </div>

    <!-- Title -->
    <h1 class="fw-auth__title">Welcome back</h1>
    <p class="fw-auth__subtitle">Sign in to your Flowwork account</p>

    <!-- Success Message -->
    <?php if ($msg): ?>
      <div class="fw-auth__message fw-auth__message--success">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
          <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?= e($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if ($err): ?>
      <div class="fw-auth__message fw-auth__message--error">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
          <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <circle cx="12" cy="16" r="1" fill="currentColor"/>
        </svg>
        <?= e($err) ?>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" data-validate>
      <!-- Email -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label" for="email">
          Email <span class="fw-auth__label-required">*</span>
        </label>
        <input 
          type="email" 
          id="email" 
          name="email" 
          class="fw-auth__input" 
          required 
          autofocus 
          autocomplete="email"
          value="<?= e($_POST['email'] ?? '') ?>"
          placeholder="you@company.com"
        >
      </div>

      <!-- Password -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label" for="password">
          Password <span class="fw-auth__label-required">*</span>
        </label>
        <div class="fw-auth__input-wrapper">
          <input 
            type="password" 
            id="password" 
            name="password" 
            class="fw-auth__input fw-auth__input--password" 
            required 
            autocomplete="current-password"
            placeholder="Enter your password"
            minlength="8"
          >
          <button type="button" class="fw-auth__password-toggle" aria-label="Show password">
            <svg data-eye-open viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
            </svg>
            <svg data-eye-closed style="display:none;" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Remember Me & Forgot Password -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--fw-spacing-lg);">
        <div class="fw-auth__checkbox-wrapper" style="margin:0;">
          <input type="checkbox" id="remember" name="remember" class="fw-auth__checkbox">
          <label for="remember" class="fw-auth__checkbox-label">Remember me</label>
        </div>
        <a href="/shared/password_reset_request.php" class="fw-auth__link" style="font-size:14px;">Forgot password?</a>
      </div>

      <!-- Submit Button -->
      <button type="submit" class="fw-auth__button">
        Sign In
      </button>
    </form>

    <!-- Links -->
    <div class="fw-auth__links">
      Don't have an account? <a href="/register.php" class="fw-auth__link">Create account</a>
    </div>
  </div>

  <script src="/shared/auth.js?v=<?= time() ?>"></script>
</body>
</html>