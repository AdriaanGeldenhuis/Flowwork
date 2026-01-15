<?php
require_once __DIR__ . '/../init.php';

// Already logged in? Redirect to home
if (!empty($_SESSION['user_id'])) redirect('/home.php');

$msg = '';
$msgType = '';

if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'sent') {
    $msg = 'If an account exists with this email, a password reset link has been sent.';
    $msgType = 'success';
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password – Flowwork</title>
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

    <!-- Title -->
    <h1 class="fw-auth__title">Reset your password</h1>
    <p class="fw-auth__subtitle">Enter your email and we'll send you a reset link</p>

    <!-- Message -->
    <?php if ($msg): ?>
      <div class="fw-auth__message fw-auth__message--<?= $msgType ?>">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
          <?php if ($msgType === 'success'): ?>
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <?php else: ?>
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
            <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <circle cx="12" cy="16" r="1" fill="currentColor"/>
          <?php endif; ?>
        </svg>
        <?= e($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form id="passwordResetForm" method="POST" action="" data-validate>
      <!-- Email -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label" for="email">
          Email Address <span class="fw-auth__label-required">*</span>
        </label>
        <input 
          type="email" 
          id="email" 
          name="email" 
          class="fw-auth__input" 
          required 
          autofocus 
          autocomplete="email"
          placeholder="you@company.com"
        >
        <div class="fw-auth__field-help">
          We'll send a password reset link to this email address.
        </div>
      </div>

      <!-- Submit Button -->
      <button type="submit" class="fw-auth__button">
        Send Reset Link
      </button>
    </form>

    <!-- Links -->
    <div class="fw-auth__links">
      <a href="/login.php" class="fw-auth__link">← Back to sign in</a>
    </div>
    <div class="fw-auth__links" style="margin-top:12px;">
      Don't have an account? <a href="/register.php" class="fw-auth__link">Create account</a>
    </div>
  </div>

  <script src="/shared/auth.js?v=<?= time() ?>"></script>
</body>
</html>