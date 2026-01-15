<?php
require_once __DIR__ . '/../../init.php';
header('Content-Type: application/json');

// Rate limiting: Check how many requests from this IP in the last hour
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = 3; // Max 3 requests per hour per IP
$rateLimitWindow = 3600; // 1 hour in seconds

// Simple file-based rate limiting (you could use Redis/Memcached in production)
$rateLimitFile = sys_get_temp_dir() . '/fw_reset_rate_' . md5($ip) . '.txt';
$attempts = [];

if (file_exists($rateLimitFile)) {
  $attempts = json_decode(file_get_contents($rateLimitFile), true) ?: [];
  // Filter out old attempts
  $attempts = array_filter($attempts, function($timestamp) use ($rateLimitWindow) {
    return $timestamp > (time() - $rateLimitWindow);
  });
}

if (count($attempts) >= $rateLimit) {
  echo json_encode([
    'ok' => false,
    'message' => 'Too many reset requests. Please try again later.'
  ]);
  exit;
}

// Get email from request
$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode([
    'ok' => false,
    'message' => 'Please provide a valid email address.'
  ]);
  exit;
}

try {
  // Check if user exists (but don't reveal if they don't)
  $stmt = $DB->prepare("SELECT id, email, first_name FROM users WHERE email = ? AND status = 'active'");
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if ($user) {
    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours

    // Store token
    $stmt = $DB->prepare("INSERT INTO password_resets 
                          (user_id, token, expires_at, used, created_at) 
                          VALUES (?, ?, ?, 0, NOW())");
    $stmt->execute([$user['id'], $token, $expiresAt]);

    // Send email
    require_once __DIR__ . '/../email_sender.php';
    $emailSender = new EmailSender();
    $resetLink = 'https://' . $_SERVER['HTTP_HOST'] . '/shared/password_reset_verify.php?token=' . urlencode($token);
    
    $emailSent = $emailSender->sendPasswordReset(
      $user['email'],
      $user['first_name'],
      $resetLink
    );

    if (!$emailSent) {
      error_log("Failed to send password reset email to: " . $user['email']);
    }

    // Log the request
    $stmt = $DB->prepare("INSERT INTO audit_log 
                          (company_id, user_id, action, details, ip, created_at) 
                          SELECT company_id, id, 'password_reset_requested', ?, ?, NOW() 
                          FROM users WHERE id = ?");
    $stmt->execute([
      json_encode(['email' => $email]),
      $ip,
      $user['id']
    ]);
  }

  // Record rate limit attempt
  $attempts[] = time();
  file_put_contents($rateLimitFile, json_encode($attempts));

  // Always return success to prevent email enumeration
  echo json_encode([
    'ok' => true,
    'message' => 'If an account exists with this email, a reset link has been sent.'
  ]);

} catch (Exception $e) {
  error_log("Password reset request error: " . $e->getMessage());
  echo json_encode([
    'ok' => false,
    'message' => 'An error occurred. Please try again later.'
  ]);
}