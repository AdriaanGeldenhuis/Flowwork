<?php
// config.example.php
//
// Template for config.php. Copy this to config.php and fill in real values,
// OR (preferred) set the values as environment variables on the host and read
// them with getenv() so no secret ever lives in a file. config.php is
// git-ignored — never commit real credentials.

const APP_ENV      = 'production';
const APP_BASE_URL = 'https://www.flowwork.app';

// Database — prefer environment variables in production.
define('DB_HOST', getenv('DB_HOST') ?: 'your-db-host');
define('DB_NAME', getenv('DB_NAME') ?: 'your-db-name');
define('DB_USER', getenv('DB_USER') ?: 'your-db-user');
define('DB_PASS', getenv('DB_PASS') ?: 'your-db-password');

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
