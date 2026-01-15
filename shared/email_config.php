<?php
/**
 * Email Configuration
 * Configure SMTP settings and email defaults
 */

// Email sending method: 'smtp' or 'mail'
define('EMAIL_METHOD', 'smtp');

// SMTP Configuration (if using SMTP)
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'

// From email defaults
define('EMAIL_FROM_ADDRESS', getenv('EMAIL_FROM') ?: 'noreply@flowwork.co.za');
define('EMAIL_FROM_NAME', 'Flowwork');

// Reply-to email
define('EMAIL_REPLY_TO', getenv('EMAIL_REPLY_TO') ?: 'support@flowwork.co.za');

// Email templates directory
define('EMAIL_TEMPLATES_DIR', __DIR__ . '/email_templates');

// Enable email logging
define('EMAIL_LOG_ENABLED', true);
define('EMAIL_LOG_FILE', __DIR__ . '/../logs/email.log');

// Email queue (for future implementation)
define('EMAIL_QUEUE_ENABLED', false);