<?php
// /includes/auth_sessions.php
//
// Per-device sign-in sessions.
//
// Every browser, tablet and app install that signs in gets its own row in
// `user_sessions`, so signing in on one device never signs another one out.
// The PHP session only remembers which row it belongs to ($_SESSION['auth_sid']);
// each request checks that row (fw_auth_check, called from init.php), so ending
// a row signs that device out everywhere, on its very next request.
//
// A row ends when the user logs out on that device, signs it out from
// "Signed-in devices" (/account/), resets their password, or is suspended by an
// admin. A company can opt in to one device per user (Admin › Security).
//
// "Keep me signed in" is stored on the same row: the fw_remember cookie holds a
// random token whose SHA-256 is remember_hash. When the PHP session has expired,
// the cookie brings that same device back (fw_auth_restore) without a new row.
// The token is not rotated on use: in the Android WebView a rotated cookie can be
// lost when the app is closed before the cookie store is written, which would
// sign the user out. Expiry slides forward while the device is in use.

const FW_AUTH_COOKIE = 'fw_remember';
const FW_AUTH_DEVICE_COOKIE = 'fw_device'; // random id per browser/app install, so a re-login replaces that device's old row
const FW_AUTH_TOUCH_EVERY = 600;      // seconds between last-seen / expiry refreshes
const FW_AUTH_IP_LIMIT = 50;          // failed sign-ins per IP per window, any account

// ------------------------------------------------------------------ settings

function fw_auth_remember_ttl(): int
{
    return defined('REMEMBER_ME_EXPIRY') ? max(86400, (int)REMEMBER_ME_EXPIRY) : 30 * 86400;
}

function fw_auth_session_ttl(): int
{
    return defined('SESSION_LIFETIME') ? max(1800, (int)SESSION_LIFETIME) : 86400;
}

function fw_auth_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/** True inside the Flowwork Android app (its WebView adds "FlowWorkApp/x" to the user agent). */
function fw_auth_is_app(): bool
{
    return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'FlowWorkApp') !== false;
}

function fw_auth_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Only same-site paths may be used as a post-login destination. */
function fw_safe_redirect(?string $url, string $fallback = '/home.php'): string
{
    $url = trim((string)$url);
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//') || str_starts_with($url, '/\\')
        || preg_match('/[\x00-\x1f\\\\]/', $url)) {
        return $fallback;
    }
    return $url;
}

function fw_company_setting(PDO $db, int $companyId, string $key, ?string $default = null): ?string
{
    try {
        $st = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
        $st->execute([$companyId, $key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Company opted in to "one signed-in device per user" (Admin › Security). Off by default. */
function fw_auth_single_device(PDO $db, int $companyId): bool
{
    return fw_company_setting($db, $companyId, 'security_single_device', '0') === '1';
}

// ------------------------------------------------------------------ schema

/**
 * Run $fn; if a table is missing, create the auth tables and try once more.
 * Production databases are provisioned on first use, like the mail module.
 */
function fw_auth_db(PDO $db, callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        fw_auth_install($db);
        return $fn();
    }
}

function fw_auth_install(PDO $db): void
{
    $sessions = "CREATE TABLE IF NOT EXISTS `user_sessions` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `remember_hash` char(64) DEFAULT NULL,
          `device_hash` char(64) DEFAULT NULL,
          `device_label` varchar(120) NOT NULL DEFAULT '',
          `user_agent` varchar(255) DEFAULT NULL,
          `ip` varchar(45) DEFAULT NULL,
          `is_app` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
          `expires_at` datetime NOT NULL,
          `revoked_at` datetime DEFAULT NULL,
          `revoked_reason` varchar(32) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_user_sessions_remember` (`remember_hash`),
          KEY `idx_user_sessions_user` (`user_id`, `revoked_at`),
          KEY `idx_user_sessions_device` (`device_hash`),
          KEY `idx_user_sessions_expires` (`expires_at`)%s
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $db->exec(sprintf($sessions, ",\n          CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE"));
    } catch (PDOException $e) {
        // A users table whose id type differs cannot take the foreign key; the
        // table works without it (rows of deleted users are simply never used).
        error_log('[auth] user_sessions without foreign key: ' . $e->getMessage());
        $db->exec(sprintf($sessions, ''));
    }
    $db->exec("CREATE TABLE IF NOT EXISTS `auth_login_attempts` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `email` varchar(255) NOT NULL,
          `ip` varchar(45) NOT NULL,
          `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_login_attempts_email` (`email`, `attempted_at`),
          KEY `idx_login_attempts_ip` (`ip`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ------------------------------------------------------------------ device names

/** "Flowwork app · Android", "Chrome on Windows", "Safari on iPhone" … */
function fw_auth_device_label(string $ua): string
{
    $os = 'Unknown device';
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'CrOS' => 'ChromeOS',
        'Windows' => 'Windows', 'Macintosh' => 'Mac', 'Mac OS X' => 'Mac', 'Linux' => 'Linux',
    ] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $os = $name; break; }
    }
    if (stripos($ua, 'FlowWorkApp') !== false) return 'Flowwork app · ' . $os;
    $browser = 'Browser';
    foreach ([
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox',
        'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari',
    ] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    return $browser . ' on ' . $os;
}

// ------------------------------------------------------------------ cookies

function fw_auth_set_remember_cookie(string $token, int $ttl): void
{
    if (headers_sent()) return;
    setcookie(FW_AUTH_COOKIE, $token, [
        'expires' => time() + $ttl, 'path' => '/', 'secure' => fw_auth_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function fw_auth_clear_remember_cookie(): void
{
    if (!isset($_COOKIE[FW_AUTH_COOKIE])) return;
    unset($_COOKIE[FW_AUTH_COOKIE]);
    if (headers_sent()) return;
    setcookie(FW_AUTH_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => fw_auth_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** Push the PHP session cookie's expiry forward, so an active device stays signed in. */
function fw_auth_slide_session_cookie(): void
{
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) return;
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + fw_auth_session_ttl(), 'path' => $p['path'] ?: '/', 'domain' => $p['domain'] ?? '',
        'secure' => (bool)$p['secure'], 'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}

/** This browser's device id, created on first sign-in and kept for two years. */
function fw_auth_device_id(): string
{
    $id = (string)($_COOKIE[FW_AUTH_DEVICE_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie(FW_AUTH_DEVICE_COOKIE, $id, [
            'expires' => time() + 2 * 365 * 86400, 'path' => '/', 'secure' => fw_auth_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[FW_AUTH_DEVICE_COOKIE] = $id;
    return $id;
}

function fw_auth_remember_token(): ?string
{
    $raw = (string)($_COOKIE[FW_AUTH_COOKIE] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $raw) ? $raw : null;
}

// ------------------------------------------------------------------ lifecycle

/**
 * Sign $user in on this device. $user needs id, company_id, first_name, last_name.
 * Creates the device row, starts a fresh PHP session id and, with $remember,
 * sets the "keep me signed in" cookie.
 */
function fw_auth_begin(PDO $db, array $user, bool $remember): int
{
    $uid = (int)$user['id'];
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // Signing in again on the same browser or app: its older row (a session that
    // was lost, or an old "keep me signed in" token) is replaced, so each device
    // appears once in Signed-in devices.
    $deviceHash = hash('sha256', fw_auth_device_id());
    $old = fw_auth_remember_token();
    fw_auth_db($db, fn() => $db->prepare("UPDATE user_sessions SET revoked_at = NOW(), revoked_reason = 'replaced'
                                           WHERE revoked_at IS NULL AND (device_hash = ? OR remember_hash = ?)")
                               ->execute([$deviceHash, $old !== null ? hash('sha256', $old) : '-']));

    $token = $remember ? bin2hex(random_bytes(32)) : null;
    $ttl = $remember ? fw_auth_remember_ttl() : fw_auth_session_ttl();
    $sid = (int)fw_auth_db($db, function () use ($db, $uid, $token, $deviceHash, $ua, $ttl) {
        $db->prepare("INSERT INTO user_sessions (user_id, remember_hash, device_hash, device_label, user_agent, ip, is_app, expires_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
           ->execute([$uid, $token !== null ? hash('sha256', $token) : null, $deviceHash, fw_auth_device_label($ua), $ua !== '' ? $ua : null,
                      fw_auth_ip(), fw_auth_is_app() ? 1 : 0, $ttl]);
        return $db->lastInsertId();
    });

    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $csrf = $_SESSION['csrf_token'] ?? null;
    $_SESSION = [];
    if ($csrf) $_SESSION['csrf_token'] = $csrf;
    $_SESSION['user_id'] = $uid;
    $_SESSION['company_id'] = (int)$user['company_id'];
    $_SESSION['user_first_name'] = $user['first_name'] ?? '';
    $_SESSION['user_last_name'] = $user['last_name'] ?? '';
    $_SESSION['auth_sid'] = $sid;
    $_SESSION['auth_touch'] = time();

    if ($token !== null) fw_auth_set_remember_cookie($token, $ttl);
    else fw_auth_clear_remember_cookie();

    $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$uid]);
    if (fw_auth_single_device($db, (int)$user['company_id'])) {
        fw_auth_revoke_all($db, $uid, 'single_device', $sid);
    }
    if (random_int(1, 50) === 1) fw_auth_cleanup($db);
    return $sid;
}

/**
 * Called on every web request (from init.php): is this PHP session's device
 * still signed in? If not, the session is emptied so every auth check that
 * follows (auth_gate.php or a bare $_SESSION['user_id'] test) sees a guest.
 */
function fw_auth_check(PDO $db): void
{
    static $checked = false;
    if ($checked || PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    $checked = true;
    if (empty($_SESSION['user_id'])) return;

    $uid = (int)$_SESSION['user_id'];
    $sid = (int)($_SESSION['auth_sid'] ?? 0);
    if ($sid <= 0) {
        if (!fw_auth_adopt_legacy($db, $uid)) fw_auth_drop('session_expired');
        return;
    }

    $row = fw_auth_db($db, function () use ($db, $sid) {
        $st = $db->prepare("SELECT user_id, remember_hash, revoked_reason, (revoked_at IS NULL AND expires_at > NOW()) AS live
                              FROM user_sessions WHERE id = ?");
        $st->execute([$sid]);
        return $st->fetch(PDO::FETCH_ASSOC);
    });
    if (!$row || (int)$row['user_id'] !== $uid || !(int)$row['live']) {
        fw_auth_drop(fw_auth_notice($row['revoked_reason'] ?? null));
        return;
    }

    if (time() - (int)($_SESSION['auth_touch'] ?? 0) >= FW_AUTH_TOUCH_EVERY) {
        $_SESSION['auth_touch'] = time();
        $remembered = $row['remember_hash'] !== null;
        $ttl = $remembered ? fw_auth_remember_ttl() : fw_auth_session_ttl();
        $db->prepare("UPDATE user_sessions SET last_seen_at = NOW(), ip = ?,
                             expires_at = GREATEST(expires_at, DATE_ADD(NOW(), INTERVAL ? SECOND))
                       WHERE id = ?")
           ->execute([fw_auth_ip(), $ttl, $sid]);
        fw_auth_slide_session_cookie();
        $token = fw_auth_remember_token();
        if ($remembered && $token !== null && hash_equals((string)$row['remember_hash'], hash('sha256', $token))) {
            fw_auth_set_remember_cookie($token, $ttl);
        }
    }
}

/**
 * Bring this device back from its "keep me signed in" cookie after the PHP
 * session expired. Returns true when the visitor is signed in again.
 */
function fw_auth_restore(PDO $db): bool
{
    $token = fw_auth_remember_token();
    if ($token === null) return false;
    $row = fw_auth_db($db, function () use ($db, $token) {
        $st = $db->prepare("SELECT s.id, s.user_id, u.company_id, u.status, u.first_name, u.last_name, c.subscription_active
                              FROM user_sessions s
                              JOIN users u ON u.id = s.user_id
                         LEFT JOIN companies c ON c.id = u.company_id
                             WHERE s.remember_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()");
        $st->execute([hash('sha256', $token)]);
        return $st->fetch(PDO::FETCH_ASSOC);
    });
    if (!$row || $row['status'] !== 'active' || empty($row['subscription_active'])) {
        fw_auth_clear_remember_cookie();
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$row['user_id'];
    $_SESSION['company_id'] = (int)$row['company_id'];
    $_SESSION['user_first_name'] = $row['first_name'];
    $_SESSION['user_last_name'] = $row['last_name'];
    $_SESSION['auth_sid'] = (int)$row['id'];
    $_SESSION['auth_touch'] = time();

    $ttl = fw_auth_remember_ttl();
    $db->prepare("UPDATE user_sessions SET last_seen_at = NOW(), ip = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
       ->execute([fw_auth_ip(), $ttl, (int)$row['id']]);
    $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$row['user_id']]);
    fw_auth_set_remember_cookie($token, $ttl);
    return true;
}

/** Log out on this device only. */
function fw_auth_end(PDO $db, string $reason = 'logout'): void
{
    $sid = (int)($_SESSION['auth_sid'] ?? 0);
    if ($sid > 0) {
        fw_auth_db($db, fn() => $db->prepare("UPDATE user_sessions SET revoked_at = NOW(), revoked_reason = ?
                                               WHERE id = ? AND revoked_at IS NULL")
                                   ->execute([$reason, $sid]));
    }
    $token = fw_auth_remember_token();
    if ($token !== null) {
        fw_auth_db($db, fn() => $db->prepare("UPDATE user_sessions SET revoked_at = NOW(), revoked_reason = ?
                                               WHERE remember_hash = ? AND revoked_at IS NULL")
                                   ->execute([$reason, hash('sha256', $token)]));
    }
    fw_auth_clear_remember_cookie();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    if (!headers_sent() && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
}

/** Sign out one of the user's devices. */
function fw_auth_revoke(PDO $db, int $userId, int $sessionId, string $reason = 'signed_out'): bool
{
    return (bool)fw_auth_db($db, function () use ($db, $userId, $sessionId, $reason) {
        $st = $db->prepare("UPDATE user_sessions SET revoked_at = NOW(), revoked_reason = ?
                             WHERE id = ? AND user_id = ? AND revoked_at IS NULL");
        $st->execute([$reason, $sessionId, $userId]);
        return $st->rowCount() === 1;
    });
}

/** Sign out every device of a user (password reset, suspension), optionally keeping one. */
function fw_auth_revoke_all(PDO $db, int $userId, string $reason, ?int $exceptId = null): int
{
    return (int)fw_auth_db($db, function () use ($db, $userId, $reason, $exceptId) {
        $st = $db->prepare("UPDATE user_sessions SET revoked_at = NOW(), revoked_reason = ?
                             WHERE user_id = ? AND revoked_at IS NULL AND id <> ?");
        $st->execute([$reason, $userId, (int)$exceptId]);
        return $st->rowCount();
    });
}

/** The user's signed-in devices, most recently used first. */
function fw_auth_devices(PDO $db, int $userId): array
{
    return fw_auth_db($db, function () use ($db, $userId) {
        // Ages in seconds rather than timestamps: no time-zone guesswork in the browser.
        $st = $db->prepare("SELECT id, device_label, ip, is_app, remember_hash IS NOT NULL AS remembered,
                                   TIMESTAMPDIFF(SECOND, created_at, NOW()) AS created_ago,
                                   TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_ago
                              FROM user_sessions
                             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
                          ORDER BY last_seen_at DESC, id DESC");
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    });
}

// ------------------------------------------------------------------ internals

/**
 * Sessions started before per-device sign-in existed carry the old single
 * session token. The one device that token still matches keeps working and
 * gets a device row; every other old session was already signed out.
 */
function fw_auth_adopt_legacy(PDO $db, int $uid): bool
{
    $legacy = (string)($_SESSION['sess_token'] ?? '');
    if ($legacy === '') return false;
    $st = $db->prepare("SELECT session_token FROM users WHERE id = ? AND status = 'active'");
    $st->execute([$uid]);
    $current = $st->fetchColumn();
    if (!is_string($current) || $current === '' || !hash_equals($current, $legacy)) return false;

    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $deviceHash = hash('sha256', fw_auth_device_id());
    $sid = (int)fw_auth_db($db, function () use ($db, $uid, $ua, $deviceHash) {
        $db->prepare("INSERT INTO user_sessions (user_id, device_hash, device_label, user_agent, ip, is_app, expires_at)
                      VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
           ->execute([$uid, $deviceHash, fw_auth_device_label($ua), $ua !== '' ? $ua : null, fw_auth_ip(), fw_auth_is_app() ? 1 : 0,
                      fw_auth_session_ttl()]);
        return $db->lastInsertId();
    });
    $db->prepare("UPDATE users SET session_token = NULL WHERE id = ?")->execute([$uid]);
    unset($_SESSION['sess_token']);
    $_SESSION['auth_sid'] = $sid;
    $_SESSION['auth_touch'] = time();
    return true;
}

/** Empty the PHP session (the device is no longer signed in) and remember why. */
function fw_auth_drop(string $notice): void
{
    $_SESSION = ['auth_notice' => $notice];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
}

/** Login-page message key for why a device was signed out. */
function fw_auth_notice(?string $reason): string
{
    return match ($reason) {
        'signed_out'     => 'signed_out_remote',
        'password_reset' => 'password_changed',
        'single_device'  => 'signed_in_elsewhere',
        'suspended'      => 'account_suspended',
        'logout'         => 'logged_out',
        default          => 'session_expired',
    };
}

/** Housekeeping: forget long-dead device rows and old failed sign-ins. */
function fw_auth_cleanup(PDO $db): void
{
    try {
        $db->exec("DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                                              OR expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $db->exec("DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (Throwable $e) {
        error_log('[auth] cleanup: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------ sign-in throttling

/**
 * Limits come from Admin › Security (max attempts, lockout minutes) of the
 * account's company; unknown accounts use the defaults.
 * @return array{0:int,1:int} [max attempts, lockout minutes]
 */
function fw_login_limits(PDO $db, ?int $companyId): array
{
    $max = 5;
    $minutes = 15;
    if ($companyId) {
        $max = (int)fw_company_setting($db, $companyId, 'security_max_login_attempts', '5');
        $minutes = (int)fw_company_setting($db, $companyId, 'security_lockout_duration', '15');
    }
    return [min(20, max(3, $max ?: 5)), min(60, max(1, $minutes ?: 15))];
}

/**
 * Seconds until sign-in may be tried again, or 0. Counts failures for this
 * email from this IP, for this email from anywhere (4× the limit, so one person
 * cannot lock a colleague out casually), and from this IP for any email.
 */
function fw_login_wait(PDO $db, string $email, string $ip, int $max, int $minutes): int
{
    return (int)fw_auth_db($db, function () use ($db, $email, $ip, $max, $minutes) {
        $wait = 0;
        $checks = [
            ["email = ? AND ip = ?", [$email, $ip], $max],
            ["email = ?", [$email], $max * 4],
            ["ip = ?", [$ip], FW_AUTH_IP_LIMIT],
        ];
        foreach ($checks as [$where, $args, $limit]) {
            $st = $db->prepare("SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) AS ago
                                  FROM auth_login_attempts
                                 WHERE $where AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
            $st->execute(array_merge($args, [$minutes]));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] >= $limit) $wait = max($wait, $minutes * 60 - (int)$r['ago']);
        }
        return max(0, $wait);
    });
}

function fw_login_failed(PDO $db, string $email, string $ip): void
{
    fw_auth_db($db, fn() => $db->prepare("INSERT INTO auth_login_attempts (email, ip) VALUES (?, ?)")->execute([$email, $ip]));
}

function fw_login_succeeded(PDO $db, string $email, string $ip): void
{
    fw_auth_db($db, fn() => $db->prepare("DELETE FROM auth_login_attempts WHERE email = ? AND ip = ?")->execute([$email, $ip]));
}
