<?php
/**
 * Email Sender Class
 * Handles sending emails via SMTP or PHP mail()
 */

require_once __DIR__ . '/email_config.php';

class EmailSender {
  
  /**
   * Send an email
   */
  public function send($to, $subject, $body, $isHtml = true, $attachments = []) {
    try {
      if (EMAIL_METHOD === 'smtp') {
        return $this->sendViaSMTP($to, $subject, $body, $isHtml, $attachments);
      } else {
        return $this->sendViaPHPMail($to, $subject, $body, $isHtml);
      }
    } catch (Exception $e) {
      $this->logError("Email send failed: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Send password reset email
   */
  public function sendPasswordReset($email, $firstName, $resetLink) {
    $subject = 'Reset your Flowwork password';
    
    // Load HTML template
    ob_start();
    include EMAIL_TEMPLATES_DIR . '/password_reset.php';
    $htmlBody = ob_get_clean();
    
    // Plain text alternative
    $textBody = "Hi {$firstName},\n\n";
    $textBody .= "You requested to reset your password for your Flowwork account.\n\n";
    $textBody .= "Click the link below to reset your password:\n";
    $textBody .= $resetLink . "\n\n";
    $textBody .= "This link will expire in 24 hours.\n\n";
    $textBody .= "If you didn't request this, please ignore this email.\n\n";
    $textBody .= "Best regards,\n";
    $textBody .= "The Flowwork Team";
    
    return $this->send($email, $subject, $htmlBody, true);
  }

  /**
   * Send via SMTP using PHPMailer or similar
   * For simplicity, using basic socket connection
   */
  private function sendViaSMTP($to, $subject, $body, $isHtml, $attachments) {
    // For production, use PHPMailer library
    // This is a simplified version for demonstration
    
    if (!SMTP_USERNAME || !SMTP_PASSWORD) {
      throw new Exception('SMTP credentials not configured');
    }

    // In production, use PHPMailer:
    // require 'vendor/autoload.php';
    // $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    // ... configure and send
    
    // For now, fallback to PHP mail() with proper headers
    return $this->sendViaPHPMail($to, $subject, $body, $isHtml);
  }

  /**
   * Send via PHP mail() function
   */
  private function sendViaPHPMail($to, $subject, $body, $isHtml) {
    $headers = [];
    $headers[] = 'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM_ADDRESS . '>';
    $headers[] = 'Reply-To: ' . EMAIL_REPLY_TO;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = 'MIME-Version: 1.0';
    
    if ($isHtml) {
      $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
      $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    
    $success = mail($to, $subject, $body, implode("\r\n", $headers));
    
    if ($success) {
      $this->logEmail($to, $subject, 'sent');
    } else {
      $this->logEmail($to, $subject, 'failed');
    }
    
    return $success;
  }

  /**
   * Validate email address
   */
  public function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
  }

  /**
   * Log email activity
   */
  private function logEmail($to, $subject, $status) {
    if (!EMAIL_LOG_ENABLED) return;
    
    $logDir = dirname(EMAIL_LOG_FILE);
    if (!is_dir($logDir)) {
      mkdir($logDir, 0755, true);
    }
    
    $logEntry = sprintf(
      "[%s] TO: %s | SUBJECT: %s | STATUS: %s\n",
      date('Y-m-d H:i:s'),
      $to,
      $subject,
      $status
    );
    
    file_put_contents(EMAIL_LOG_FILE, $logEntry, FILE_APPEND);
  }

  /**
   * Log errors
   */
  private function logError($message) {
    error_log("EmailSender Error: " . $message);
    if (EMAIL_LOG_ENABLED) {
      $this->logEmail('N/A', 'N/A', 'error: ' . $message);
    }
  }
}