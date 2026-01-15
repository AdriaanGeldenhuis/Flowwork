<?php
require_once __DIR__ . '/init.php';

if (!empty($_SESSION['user_id'])) redirect('/home.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $firstName   = trim($_POST['first_name'] ?? '');
  $lastName    = trim($_POST['last_name'] ?? '');
  $email       = trim($_POST['email'] ?? '');
  $pass        = $_POST['password'] ?? '';
  $passConfirm = $_POST['password_confirm'] ?? '';
  $companyName = trim($_POST['company_name'] ?? '');
  $businessType= $_POST['business_type'] ?? 'construction';
  $planId      = (int)($_POST['plan_id'] ?? 1);
  $agreeTerms  = isset($_POST['agree_terms']);

  // Validation
  if (!$firstName || !$lastName || !$email || !$pass || !$passConfirm || !$companyName) {
    $errors[] = 'All fields are required.';
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address.';
  }
  if ($pass !== $passConfirm) {
    $errors[] = 'Passwords do not match.';
  }
  if (strlen($pass) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }
  // Password strength check
  if ($pass && !preg_match('/[A-Z]/', $pass)) {
    $errors[] = 'Password must contain at least one uppercase letter.';
  }
  if ($pass && !preg_match('/[a-z]/', $pass)) {
    $errors[] = 'Password must contain at least one lowercase letter.';
  }
  if ($pass && !preg_match('/[0-9]/', $pass)) {
    $errors[] = 'Password must contain at least one number.';
  }
  if (!$agreeTerms) {
    $errors[] = 'You must agree to the Terms of Service and Privacy Policy.';
  }
  if (!in_array($businessType, ['construction', 'postal', 'hairdresser'])) {
    $businessType = 'construction';
  }
  if (!in_array($planId, [1, 2, 3])) {
    $planId = 1;
  }

  // Check email uniqueness
  if (empty($errors)) {
    $stmt = $DB->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      $errors[] = 'An account with this email already exists.';
    }
  }

  if (empty($errors)) {
    try {
      $DB->beginTransaction();

      // Get plan limits
      $stmt = $DB->prepare("SELECT max_users, max_companies FROM plans WHERE id = ?");
      $stmt->execute([$planId]);
      $plan = $stmt->fetch();
      if (!$plan) {
        throw new Exception('Invalid plan selected.');
      }

      // 1. Create company
      $stmt = $DB->prepare("INSERT INTO companies 
                            (name, business_type, plan_id, max_users, max_companies, 
                             subscription_active, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())");
      $stmt->execute([
        $companyName,
        $businessType,
        $planId,
        $plan['max_users'],
        $plan['max_companies']
      ]);
      $companyId = $DB->lastInsertId();

      // 2. Create user (admin)
      $passwordHash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $DB->prepare("INSERT INTO users 
                            (company_id, email, password_hash, first_name, last_name, 
                             role, is_seat, status, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, 'admin', 1, 'active', NOW(), NOW())");
      $stmt->execute([$companyId, $email, $passwordHash, $firstName, $lastName]);
      $userId = $DB->lastInsertId();

      // 3. Create user_companies link
      $stmt = $DB->prepare("INSERT INTO user_companies 
                            (user_id, company_id, role, created_at) 
                            VALUES (?, ?, 'admin', NOW())");
      $stmt->execute([$userId, $companyId]);

      // 4. Create subscription record
      $stmt = $DB->prepare("INSERT INTO subscriptions 
                            (company_id, plan_id, status, current_period_end, created_at, updated_at) 
                            VALUES (?, ?, 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())");
      $stmt->execute([$companyId, $planId]);

      // 5. Log registration
      $stmt = $DB->prepare("INSERT INTO audit_log 
                            (company_id, user_id, action, details, ip, created_at) 
                            VALUES (?, ?, 'user_registered', ?, ?, NOW())");
      $stmt->execute([
        $companyId,
        $userId,
        json_encode(['email' => $email, 'plan_id' => $planId]),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
      ]);

      $DB->commit();

      // Auto-login
      session_regenerate_id(true);
      $_SESSION['user_id'] = $userId;
      $_SESSION['company_id'] = $companyId;
      $_SESSION['user_first_name'] = $firstName;
      $_SESSION['user_last_name'] = $lastName;

      $token = bin2hex(random_bytes(32));
      $_SESSION['sess_token'] = $token;

      $DB->prepare("UPDATE users SET session_token = ? WHERE id = ?")->execute([$token, $userId]);

      redirect('/home.php');

    } catch (Exception $e) {
      if ($DB->inTransaction()) {
        $DB->rollBack();
      }
      error_log("Registration error: " . $e->getMessage());
      $errors[] = 'Registration failed. Please try again.';
    }
  }
}

// Fetch plans
$plansStmt = $DB->query("SELECT id, code, name, max_users, max_companies, price_monthly_cents 
                         FROM plans ORDER BY id");
$plans = $plansStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account – Flowwork</title>
  <link rel="stylesheet" href="/shared/auth.css?v=<?= time() ?>">
  <style>
    .fw-auth__card { max-width: 720px; }
  </style>
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
    <h1 class="fw-auth__title">Create your account</h1>
    <p class="fw-auth__subtitle">Start your free trial – no credit card required</p>

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
      <div class="fw-auth__message fw-auth__message--error">
        <?php foreach ($errors as $error): ?>
          <div style="margin-bottom:4px;">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:6px;">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
              <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <circle cx="12" cy="16" r="1" fill="currentColor"/>
            </svg>
            <?= e($error) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" data-validate>
      <!-- Name Row -->
      <div class="fw-auth__row">
        <div class="fw-auth__form-group" style="margin:0;">
          <label class="fw-auth__label" for="first_name">
            First Name <span class="fw-auth__label-required">*</span>
          </label>
          <input 
            type="text" 
            id="first_name" 
            name="first_name" 
            class="fw-auth__input" 
            required 
            autocomplete="given-name"
            value="<?= e($_POST['first_name'] ?? '') ?>"
            placeholder="John"
          >
        </div>
        <div class="fw-auth__form-group" style="margin:0;">
          <label class="fw-auth__label" for="last_name">
            Last Name <span class="fw-auth__label-required">*</span>
          </label>
          <input 
            type="text" 
            id="last_name" 
            name="last_name" 
            class="fw-auth__input" 
            required 
            autocomplete="family-name"
            value="<?= e($_POST['last_name'] ?? '') ?>"
            placeholder="Doe"
          >
        </div>
      </div>

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
          autocomplete="email"
          value="<?= e($_POST['email'] ?? '') ?>"
          placeholder="you@company.com"
        >
      </div>

      <!-- Password Row -->
      <div class="fw-auth__row">
        <div class="fw-auth__form-group" style="margin:0;">
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
        </div>
        <div class="fw-auth__form-group" style="margin:0;">
          <label class="fw-auth__label" for="password_confirm">
            Confirm Password <span class="fw-auth__label-required">*</span>
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
      </div>

      <!-- Company Name -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label" for="company_name">
          Company Name <span class="fw-auth__label-required">*</span>
        </label>
        <input 
          type="text" 
          id="company_name" 
          name="company_name" 
          class="fw-auth__input" 
          required 
          autocomplete="organization"
          value="<?= e($_POST['company_name'] ?? '') ?>"
          placeholder="Your Company Ltd"
        >
      </div>

      <!-- Business Type -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label" for="business_type">
          Business Type <span class="fw-auth__label-required">*</span>
        </label>
        <select 
          id="business_type" 
          name="business_type" 
          class="fw-auth__select" 
          required
        >
          <option value="construction" <?= ($_POST['business_type'] ?? '') === 'construction' ? 'selected' : '' ?>>Construction</option>
          <option value="postal" <?= ($_POST['business_type'] ?? '') === 'postal' ? 'selected' : '' ?>>Postal / Courier</option>
          <option value="hairdresser" <?= ($_POST['business_type'] ?? '') === 'hairdresser' ? 'selected' : '' ?>>Hairdresser / Salon</option>
        </select>
      </div>

      <!-- Plan Selection -->
      <div class="fw-auth__form-group">
        <label class="fw-auth__label">
          Choose Your Plan <span class="fw-auth__label-required">*</span>
        </label>
        <div class="fw-auth__plans">
          <?php foreach ($plans as $plan): ?>
            <label class="fw-auth__plan-card <?= ($_POST['plan_id'] ?? 1) == $plan['id'] ? 'fw-auth__plan-card--selected' : '' ?>">
              <input 
                type="radio" 
                name="plan_id"
		value="<?= $plan['id'] ?>" 
                <?= ($_POST['plan_id'] ?? 1) == $plan['id'] ? 'checked' : '' ?>
                required
              >
              <div class="fw-auth__plan-name"><?= e($plan['name']) ?></div>
              <div class="fw-auth__plan-price">
                <span class="fw-auth__plan-price-currency">R</span><?= number_format($plan['price_monthly_cents'] / 100, 0) ?>
                <span style="font-size:14px;font-weight:400;color:var(--fw-text-muted);">/mo</span>
              </div>
              <ul class="fw-auth__plan-features">
                <li class="fw-auth__plan-feature">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                  <?= $plan['max_users'] ?> user<?= $plan['max_users'] > 1 ? 's' : '' ?>
                </li>
                <li class="fw-auth__plan-feature">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                  <?= $plan['max_companies'] ?> compan<?= $plan['max_companies'] > 1 ? 'ies' : 'y' ?>
                </li>
                <li class="fw-auth__plan-feature">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                  All features included
                </li>
              </ul>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Terms & Conditions -->
      <div class="fw-auth__checkbox-wrapper">
        <input type="checkbox" id="agree_terms" name="agree_terms" class="fw-auth__checkbox" required>
        <label for="agree_terms" class="fw-auth__checkbox-label">
          I agree to the <a href="/terms" target="_blank">Terms of Service</a> and <a href="/privacy" target="_blank">Privacy Policy</a>
        </label>
      </div>

      <!-- Submit Button -->
      <button type="submit" class="fw-auth__button">
        Create Account
      </button>
    </form>

    <!-- Links -->
    <div class="fw-auth__links">
      Already have an account? <a href="/login.php" class="fw-auth__link">Sign in</a>
    </div>
  </div>

  <script src="/shared/auth.js?v=<?= time() ?>"></script>
</body>
</html>