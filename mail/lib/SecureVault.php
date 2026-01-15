<?php
// mail/lib/SecureVault.php
class SecureVault {
  private static function getKey(): string {
    if (defined('MAIL_SECRET_KEY') && MAIL_SECRET_KEY) {
      return hash('sha256', MAIL_SECRET_KEY, true);
    }
    $env = getenv('MAIL_SECRET_KEY');
    if ($env) return hash('sha256', $env, true);
    // Last resort dev-only key
    return hash('sha256', 'dev-only-CHANGE-ME', true);
  }
  public static function encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $key = self::getKey();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
  }
  public static function decrypt(?string $blob): string {
    if (!$blob) return '';
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 TAG + 1 byte at least
      // assume legacy base64 of plaintext
      $legacy = base64_decode($blob, true);
      return $legacy !== false ? $legacy : '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = self::getKey();
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) return '';
    return $plain;
  }
}