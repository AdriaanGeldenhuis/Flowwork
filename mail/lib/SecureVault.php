<?php
// mail/lib/SecureVault.php
//
// Mailbox passwords at rest: AES-256-GCM under MAIL_SECRET_KEY (a constant in
// config.php, or the environment). Define it in config.php so web requests and
// the cron workers see the same key.
//
// Passwords saved before a key was configured were encrypted with the built-in
// development key; they still decrypt, and upgrade() re-encrypts them under the
// real key the next time they are used, so setting the key later does not lock
// anyone out.
class SecureVault {
  private const DEV_SECRET = 'dev-only-CHANGE-ME';

  private static function secret(): ?string {
    if (defined('MAIL_SECRET_KEY') && MAIL_SECRET_KEY) return (string)MAIL_SECRET_KEY;
    $env = getenv('MAIL_SECRET_KEY');
    return $env ? (string)$env : null;
  }

  private static function getKey(): string {
    return hash('sha256', self::secret() ?? self::DEV_SECRET, true);
  }

  /** @return string[] keys to try, current first */
  private static function keys(): array {
    $keys = [self::getKey()];
    $dev = hash('sha256', self::DEV_SECRET, true);
    if (!in_array($dev, $keys, true)) $keys[] = $dev;
    return $keys;
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
    return self::open($blob)[0];
  }

  /**
   * Re-encrypt a stored password under the current key when it was stored any
   * other way (development key or legacy base64). Best effort; never throws.
   */
  public static function upgrade(PDO $db, int $accountId, ?string $blob): void {
    if (!$blob || self::secret() === null) return;
    [$plain, $current] = self::open($blob);
    if ($plain === '' || $current) return;
    try {
      $db->prepare("UPDATE email_accounts SET password_encrypted = ? WHERE account_id = ? AND password_encrypted = ?")
         ->execute([self::encrypt($plain), $accountId, $blob]);
    } catch (Throwable $e) {
      error_log('[mail] could not re-encrypt password for account ' . $accountId . ': ' . $e->getMessage());
    }
  }

  /** @return array{0:string, 1:bool} plaintext, and whether it used the current key */
  private static function open(?string $blob): array {
    if (!$blob) return ['', true];
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 TAG + 1 byte at least
      // assume legacy base64 of plaintext
      return [$raw !== false ? $raw : '', false];
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    foreach (self::keys() as $i => $key) {
      $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
      if ($plain !== false) return [$plain, $i === 0];
    }
    // Legacy rows stored base64(plaintext); a long legacy password is >= 29
    // bytes so it lands here. Accept it only if it is printable text.
    if (preg_match('/^[\x20-\x7E]+$/', $raw)) return [$raw, false];
    return ['', false];
  }
}
