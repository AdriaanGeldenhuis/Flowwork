<?php
require_once __DIR__ . '/../init.php';

// Already logged in? Redirect to home
if (!empty($_SESSION['user_id'])) redirect('/home.php');

$token = $_GET['token'] ?? '';
$err = '';
$success = false;

// Verify token exists and is valid
$resetData = null;
if ($token) {
  $stmt = $DB->prepare("
    SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.email 
    FROM password_resets pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.token = ? AND pr.used = 0
  ");
  $stmt->execute([$token]);
  $resetData = $stmt->fetch();

  if (!$resetData) {
    $err = 'Invalid or expired reset link. Please request a new one.';
  } elseif (strtotime($resetData['expires_at']) < time()) {
    $err = 'This reset link has expired. Please request a new one.';
  }
}

// Process password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetData && !$err) {
  $password = $_POST['password'] ?? '';
  $passwordConfirm = $_POST['password_confirm'] ?? '';

  if (!$password || !$passwordConfirm) {
    $err = 'Please enter and confirm your new password.';
  } elseif ($password !== $passwordConfirm) {
    $err = 'Passwords do not match.';
  } elseif (strlen($password) < 8) {
    $err = 'Password must be at least 8 characters.';
  } elseif (!preg_match('/[A-Z]/', $password)) {
    $err = 'Password must contain at least one uppercase letter.';
  } elseif (!preg_match('/[a-z]/', $password)) {
    $err = 'Password must contain at least one lowercase letter.';
  } elseif (!preg_match('/[0-9]/', $password)) {
    $err = 'Password must contain at least one number.';
  } else {
    try {
      $DB->beginTransaction();

      // Update password
      $passwordHash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $DB->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
      $stmt->execute([$passwordHash, $resetData['user_id']]);

      // Mark token as used
      $stmt = $DB->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
      $stmt->execute([$resetData['id']]);

      // Invalidate all sessions for this user (force re-login)
      $stmt = $DB->prepare("UPDATE users SET session_token = NULL WHERE id = ?");
      $stmt->execute([$resetData['user_id']]);

      // Log the password reset
      $stmt = $DB->prepare("INSERT INTO audit_log 
                            (company_id, user_id, action, details, ip, created_at) 
                            SELECT company_id, id, 'password_reset', ?, ?, NOW() 
                            FROM users WHERE id = ?");
      $stmt->execute([
        json_encode(['method' => 'email_link']),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $resetData['user_id']
      ]);

      $DB->commit();
      $success = true;

    } catch (Exception $e) {
      if ($DB->inTransaction()) {
        $DB->rollBack();
      }
      error_log("Password reset error: " . $e->getMessage());
      $err = 'An error occurred. Please try again.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set New Password – Flowwork</title>
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
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 12L4 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <?php if ($success): ?>
      <!-- Success State -->
      <h1 class="fw-auth__title">Password reset successful</h1>
      <p class="fw-auth__subtitle">Your password has been updated</p>

      <div class="fw-auth__message fw-auth__message--success">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
          <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Your password has been successfully reset. You can now sign in with your new password.
      </div>

      <a href="/login.php?msg=reset_success" class="fw-auth__button" style="display:block;text-align:center;text-decoration:none;">
        Continue to Sign In
      </a>

    <?php elseif ($err): ?>
      <!-- Error State -->
      <h1 class="fw-auth__title">Unable to reset password</h1>
      <p class="fw-auth__subtitle">This reset link is invalid or has expired</p>

      <div class="fw-auth__message fw-auth__message--error">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
          <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <circle cx="12" cy="16" r="1" fill="currentColor"/>
        </svg>
        <?= e($err) ?>
      </div>

      <a href="/shared/password_reset_request.php" class="fw-auth__button" style="display:block;text-align:center;text-decoration:none;">
        Request New Reset Link
      </a>

      <div class="fw-auth__links">
        <a href="/login.php" class="fw-auth__link">← Back to sign in</a>
      </div>

    <?php else: ?>
      <!-- Form State -->
      <h1 class="fw-auth__title">Set new password</h1>
      <p class="fw-auth__subtitle">
        <?php if ($resetData): ?>
          Resetting password for <strong><?= e($resetData['email']) ?></strong>
        <?php else: ?>
          Enter your new password below
        <?php endif; ?>
      </p>

      <!-- Form -->
      <form method="POST" action="" data-validate>
        <!-- Password -->
        <div class="fw-auth__form-group">
          <label class="fw-auth__label" for="password">
            New Password <span class="fw-auth__label-required">*</span>
          </label>
          <div class="fw-auth__input-wrapper">
            <input 
              type="password" 
              id="password" 
              name="password" 
              class="fw-auth__input fw-auth__input--password" 
              required 
              autofocus
              autocomplete="new-password"
              placeholder="Min. 8 characters"
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
          <div class="fw-auth__password-strength">
            <div class="fw-auth__password-strength-bar"></div>
            <div class="fw-auth__password-strength-text"></div>
          </div>
          <div class="fw-auth__field-help">
            Use at least 8 characters with mixed case, numbers, and symbols
          </div>
        </div>

        <!-- Confirm Password -->
        <div class="fw-auth__form-group">
          <label class="fw-auth__label" for="password_confirm">
            Confirm New Password <span class="fw-auth__label-required">*</span>
          </label>
          <div class="fw-auth__input-wrapper">
            <input 
              type="password" 
              id="password_confirm" 
              name="password_confirm" 
              class="fw-auth__input fw-auth__input--password" 
              required 
              autocomplete="new-password"
              placeholder="Re-enter password"
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

        <!-- Submit Button -->
        <button type="submit" class="fw-auth__button">
          Reset Password
        </button>
      </form>

      <div class="fw-auth__links">
        <a href="/login.php" class="fw-auth__link">← Back to sign in</a>
      </div>
    <?php endif; ?>
  </div>

  <script src="/shared/auth.js?v=<?= time() ?>"></script>
</body>
</html>