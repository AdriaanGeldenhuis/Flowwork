<?php
// config.php
const APP_ENV      = 'production';
const APP_BASE_URL = 'https://www.flowwork.app';

// Database
const DB_HOST = 'dedi321.cpt1.host-h.net';
const DB_NAME = 'flowwwqmnt_db1';
const DB_USER = 'flowwwqmnt_1';
const DB_PASS = '3CLkvJsAM52Xvh7Urf2E';

// Mail secret key used to encrypt/decrypt email account credentials.
// Ideally you should set an environment variable MAIL_SECRET_KEY for production
// to override this default. When defined, SecureVault will use this constant
// to derive an AES-256-GCM key. DO NOT commit your production secret here.
const MAIL_SECRET_KEY = getenv('MAIL_SECRET_KEY') ?: 'CHANGE_ME_IN_PRODUCTION';

// Session
const SESSION_NAME = 'FLOWWORKSESSID';

// Error handling
if (APP_ENV === 'production') {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
  ini_set('error_log', __DIR__ . '/php-error.log');
} else {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}