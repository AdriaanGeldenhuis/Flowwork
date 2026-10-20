<?php
/**
 * FlowDriveSso — opens FlowDrive (flowdrive.co.za) already signed in as the
 * current Flowwork user, in the company they are working in.
 *
 * Flowwork and FlowDrive share one database but live on different domains,
 * so they cannot share a cookie. FlowDrive starts the hand-over:
 *
 *   1. {FLOWDRIVE_URL}/sso.php remembers a random "state" in the browser's
 *      Drive session and sends the person to /drive/?state=… here.
 *   2. /drive/ (behind auth_gate, so Flowwork's own sign-in and "keep me
 *      signed in" apply) stores a one-time token in fd_sso_tokens — only its
 *      SHA-256 — bound to that state, the user, the active company and this
 *      device's user_sessions row, and sends the person back to
 *      {FLOWDRIVE_URL}/sso.php?token=…&state=…
 *   3. FlowDrive checks the state is its own, spends the token (single use,
 *      two minutes) and starts its session. Signing this device out of
 *      Flowwork later signs it out of Drive too (Drive watches the row).
 *
 * The token only ever travels to the fixed FLOWDRIVE_URL, never to an address
 * taken from the request. FlowDrive's side: inc/sso.php in its repository.
 */
final class FlowDriveSso
{
    /** Seconds a token may wait to be spent. */
    public const TTL = 120;

    public static function baseUrl(): string
    {
        $url = defined('FLOWDRIVE_URL') ? (string)FLOWDRIVE_URL : (string)(getenv('FLOWDRIVE_URL') ?: 'https://flowdrive.co.za');
        return rtrim($url, '/');
    }

    /** FlowDrive's own sign-in hand-over, optionally landing on a Drive path. */
    public static function startUrl(string $next = '/'): string
    {
        return self::baseUrl() . '/sso.php' . ($next !== '/' ? '?next=' . rawurlencode($next) : '');
    }

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `fd_sso_tokens` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `token_hash` char(64) NOT NULL,
              `state` char(32) NOT NULL,
              `user_id` int(11) NOT NULL,
              `company_id` int(11) NOT NULL,
              `auth_sid` bigint(20) unsigned DEFAULT NULL,
              `ip` varchar(45) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `expires_at` datetime NOT NULL,
              `used_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_fd_sso_token` (`token_hash`),
              KEY `idx_fd_sso_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Store a token for $state and return the FlowDrive URL that spends it.
     * $theme ('light'|'dark') carries the person's Flowwork theme across.
     */
    public static function issue(PDO $db, int $userId, int $companyId, ?int $authSid, string $state, string $theme): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $state)) {
            throw new InvalidArgumentException('Invalid state');
        }
        $token = bin2hex(random_bytes(32));
        $insert = function () use ($db, $token, $state, $userId, $companyId, $authSid) {
            $db->prepare("INSERT INTO fd_sso_tokens (token_hash, state, user_id, company_id, auth_sid, ip, expires_at)
                          VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
               ->execute([hash('sha256', $token), $state, $userId, $companyId, $authSid,
                          substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), self::TTL]);
        };
        try {
            $insert();
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
            self::install($db);
            $insert();
        }
        return self::baseUrl() . '/sso.php?' . http_build_query([
            'token' => $token,
            'state' => $state,
            'theme' => $theme === 'light' ? 'light' : 'dark',
        ]);
    }
}
