<?php
// config.php
// Values can be overridden via environment variables (preferred — see
// SECURITY-REMEDIATION.md). The literals below are the production fallback
// until the host is switched to env-based configuration.
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'https://www.flowwork.app');

// Database
define('DB_HOST', getenv('DB_HOST') ?: 'dedi321.cpt1.host-h.net');
define('DB_NAME', getenv('DB_NAME') ?: 'flowwwqmnt_db1');
define('DB_USER', getenv('DB_USER') ?: 'flowwwqmnt_1');
define('DB_PASS', getenv('DB_PASS') ?: '3CLkvJsAM52Xvh7Urf2E');

// Session
const SESSION_NAME       = 'FLOWWORKSESSID';
const SESSION_LIFETIME   = 86400;   // 24 hours
const REMEMBER_ME_EXPIRY = 604800;  // 7 days

// Error handling
if (APP_ENV === 'production') {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
  ini_set('error_log', __DIR__ . '/php-error.log');
} else {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}
