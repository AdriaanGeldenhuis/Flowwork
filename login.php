<?php
require_once __DIR__ . '/init.php';

$redirectTo = fw_safe_redirect($_GET['redirect'] ?? null);
if (!empty($_SESSION['user_id'])) redirect($redirectTo);

header('Cache-Control: no-store');
$isApp = fw_auth_is_app();
$err = '';
$msg = '';
$msgType = 'success';

// Why this device is on the sign-in page (from the link, or from the device check).
$notice = (string)($_GET['msg'] ?? ($_SESSION['auth_notice'] ?? ''));
unset($_SESSION['auth_notice']);
$msgs = [
  'session_expired'     => ['Your session expired. Please sign in again.', 'info'],
  'logged_out'          => ['You have been signed out of this device.', 'success'],
  'account_suspended'   => ['Your account is suspended.', 'error'],
  'reset_success'       => ['Password reset successful. Please sign in.', 'success'],
  'verified'            => ['Email verified successfully. Please sign in.', 'success'],
  'signed_out_remote'   => ['This device was signed out from another device.', 'info'],
  'password_changed'    => ['Your password was changed, so this device was signed out. Please sign in again.', 'info'],
  'signed_in_elsewhere' => ['You signed in on another device. Your company allows one signed-in device per user.', 'info'],
  'invalid_user'        => ['Please sign in again.', 'info'],
];
if (isset($msgs[$notice])) [$msg, $msgType] = $msgs[$notice];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $msg = '';
  $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
  $pass  = (string)($_POST['password'] ?? '');
  // The app always keeps you signed in; in a browser it is the checkbox.
  $remember = $isApp || !empty($_POST['remember']);
  $ip = fw_auth_ip();

  if (!hash_equals(Csrf::token(), (string)($_POST['csrf_token'] ?? ''))) {
    $err = 'This sign-in page expired. Please try again.';
  } elseif ($email === '' || $pass === '' || strlen($email) > 255 || strlen($pass) > 4096) {
    $err = 'Please enter email and password.';
  } else {
    $stmt = $DB->prepare("SELECT id, email, password_hash, company_id, status, first_name, last_name
                          FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    [$maxAttempts, $lockMinutes] = fw_login_limits($DB, $u ? (int)$u['company_id'] : null);
    $wait = fw_login_wait($DB, $email, $ip, $maxAttempts, $lockMinutes);

    if ($wait > 0) {
      $mins = (int)ceil($wait / 60);
      $err = 'Too many sign-in attempts. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
    } elseif (!$u || !password_verify($pass, (string)$u['password_hash'])) {
      // Same work whether or not the account exists, so timing does not reveal it.
      if (!$u) password_verify($pass, '$2y$10$CUpLPInlM21BCvOecw4qfuE9YD9zE8P6rV9Fd0Tmlaup/AN9hgMjW');
      fw_login_failed($DB, $email, $ip);
      $err = 'Invalid email or password.';
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
        // Successful login: a new signed-in device; other devices stay signed in.
        fw_login_succeeded($DB, $email, $ip);
        fw_auth_begin($DB, $u, $remember);

        // Log successful login
        $stmt = $DB->prepare("INSERT INTO audit_log 
                              (company_id, user_id, action, details, ip, created_at) 
                              VALUES (?, ?, 'user_login', ?, ?, NOW())");
        $stmt->execute([
          $u['company_id'], 
          $u['id'], 
          json_encode(['email' => $email, 'device' => fw_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))]),
          $ip
        ]);

        redirect($redirectTo);
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
      <div class="fw-auth__message fw-auth__message--<?= e($msgType) ?>" role="status"<?= $msgType === 'info' ? ' data-sticky' : '' ?>>
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
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
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
        <?php if ($isApp): ?>
        <span class="fw-auth__checkbox-label" style="margin:0;font-size:13px;opacity:.8;">You'll stay signed in</span>
        <?php else: ?>
        <div class="fw-auth__checkbox-wrapper" style="margin:0;">
          <input type="checkbox" id="remember" name="remember" value="1" class="fw-auth__checkbox"
                 <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['remember'])) ? 'checked' : '' ?>>
          <label for="remember" class="fw-auth__checkbox-label">Keep me signed in</label>
        </div>
        <?php endif; ?>
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