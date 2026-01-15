<?php
/**
 * Password Reset Email Template
 * Variables available: $firstName, $resetLink
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Your Password</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background-color: #f5f6f8;
    }
    .email-wrapper {
      width: 100%;
      background-color: #f5f6f8;
      padding: 40px 20px;
    }
    .email-container {
      max-width: 600px;
      margin: 0 auto;
      background-color: #ffffff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }
    .email-header {
      background: linear-gradient(135deg, #8b5cf6, #06b6d4);
      padding: 40px 30px;
      text-align: center;
    }
    .email-logo {
      width: 64px;
      height: 64px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      margin: 0 auto 16px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .email-header h1 {
      margin: 0;
      color: #ffffff;
      font-size: 24px;
      font-weight: 700;
    }
    .email-body {
      padding: 40px 30px;
    }
    .email-body p {
      margin: 0 0 16px;
      color: #1a1d29;
      font-size: 16px;
      line-height: 1.6;
    }
    .email-button {
      display: inline-block;
      padding: 16px 32px;
      background: #8b5cf6;
      color: #ffffff;
      text-decoration: none;
      border-radius: 12px;
      font-weight: 600;
      font-size: 16px;
      margin: 24px 0;
      box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
    }
    .email-button:hover {
      background: #7c3aed;
    }
    .email-link {
      word-break: break-all;
      color: #8b5cf6;
      font-size: 14px;
      margin: 16px 0;
      padding: 12px;
      background: #f5f6f8;
      border-radius: 8px;
    }
    .email-footer {
      padding: 30px;
      background: #f5f6f8;
      text-align: center;
      font-size: 14px;
      color: #6b7280;
    }
    .email-footer a {
      color: #8b5cf6;
      text-decoration: none;
    }
    .email-divider {
      height: 1px;
      background: #e5e7eb;
      margin: 24px 0;
    }
    .email-warning {
      padding: 16px;
      background: #fff7ed;
      border-left: 4px solid #f59e0b;
      border-radius: 8px;
      margin: 24px 0;
    }
    .email-warning p {
      margin: 0;
      color: #92400e;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="email-wrapper">
    <div class="email-container">
      <!-- Header -->
      <div class="email-header">
        <div class="email-logo">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M12 12L20 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M12 12V21" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M12 12L4 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <h1>Reset Your Password</h1>
      </div>

      <!-- Body -->
      <div class="email-body">
        <p>Hi <?= htmlspecialchars($firstName) ?>,</p>
        
        <p>You recently requested to reset your password for your Flowwork account. Click the button below to reset it.</p>
        
        <center>
          <a href="<?= htmlspecialchars($resetLink) ?>" class="email-button">Reset Password</a>
        </center>
        
        <p style="font-size: 14px; color: #6b7280;">Or copy and paste this link into your browser:</p>
        <div class="email-link"><?= htmlspecialchars($resetLink) ?></div>
        
        <div class="email-warning">
          <p><strong>⚠️ Security Notice:</strong> This password reset link will expire in 24 hours. If you didn't request this reset, please ignore this email or contact our support team.</p>
        </div>
        
        <div class="email-divider"></div>
        
        <p style="font-size: 14px; color: #6b7280;">
          If you have any questions or need assistance, please don't hesitate to contact our support team.
        </p>
        
        <p style="margin-top: 24px;">
          <strong>Best regards,</strong><br>
          The Flowwork Team
        </p>
      </div>

      <!-- Footer -->
      <div class="email-footer">
        <p style="margin: 0 0 8px;">
          <a href="https://flowwork.co.za">Flowwork</a> | 
          <a href="https://flowwork.co.za/help">Help Center</a> | 
          <a href="https://flowwork.co.za/contact">Contact Support</a>
        </p>
        <p style="margin: 0; font-size: 12px;">
          © <?= date('Y') ?> Flowwork. All rights reserved.
        </p>
        <p style="margin: 8px 0 0; font-size: 12px;">
          This email was sent to <?= htmlspecialchars($email) ?> because you requested a password reset.
        </p>
      </div>
    </div>
  </div>
</body>
</html>