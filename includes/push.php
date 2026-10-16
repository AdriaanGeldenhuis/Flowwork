<?php
// /includes/push.php
//
// Phone notifications for the Flowwork Android app (Firebase Cloud Messaging).
//
// Devices. The app puts its FCM token in a first-party cookie (fw_push) on the
// WebView's own requests. fw_push_sync_device() (called from init.php on every
// request) ties that token to the signed-in device row in `user_sessions`, so
// a phone gets pushes only while it is signed in: logging out, "Signed-in
// devices", a password reset or suspension stops them at once. A second person
// signing in on the same phone takes the token over.
//
// Sending. fw_push_queue() writes to `push_outbox`; the queue is sent at the end
// of the same request (after the response has gone to the browser) and by the
// cron worker notifications/worker/send_push.php, which also retries.
//
// Server setup: Firebase console › Project settings › Service accounts ›
// Generate new private key. Upload the JSON to storage/firebase/service-account.json
// (storage/ is not web-readable) or define FCM_SERVICE_ACCOUNT_FILE in config.php.
// Until it is there nothing is queued (so no backlog of stale pushes builds up).

// Queue times (send_after, expires_at, claimed_at, sent_at) are UTC_TIMESTAMP():
// some connections switch their session time zone (the mail code does), and
// mixing NOW() across them would make retries late or rows look stale.

const FW_PUSH_COOKIE = 'fw_push';
const FW_PUSH_TOUCH_EVERY = 86400;       // refresh a bound device's last_seen_at daily
const FW_PUSH_MAX_ATTEMPTS = 6;
const FW_PUSH_ACTION_TTL = 7 * 86400;    // Mark as read / Archive buttons stay valid this long

// ------------------------------------------------------------------ schema

/** Run $fn; if a push table is missing, create the tables and try once more. */
function fw_push_db(PDO $db, callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        fw_push_install($db);
        return $fn();
    }
}

function fw_push_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS `push_devices` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `session_id` bigint(20) unsigned DEFAULT NULL,
          `token_hash` char(64) NOT NULL,
          `token` text NOT NULL,
          `platform` varchar(16) NOT NULL DEFAULT 'android',
          `app_version` varchar(20) DEFAULT NULL,
          `device_label` varchar(120) NOT NULL DEFAULT '',
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
          `last_sent_at` datetime DEFAULT NULL,
          `fail_count` int(11) NOT NULL DEFAULT 0,
          `last_error` varchar(255) DEFAULT NULL,
          `disabled_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_push_devices_token` (`token_hash`),
          KEY `idx_push_devices_user` (`user_id`, `disabled_at`),
          KEY `idx_push_devices_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `push_outbox` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `payload` mediumtext NOT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `send_after` datetime NOT NULL DEFAULT current_timestamp(),
          `expires_at` datetime NOT NULL,
          `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
          `claim_token` char(32) DEFAULT NULL,
          `claimed_at` datetime DEFAULT NULL,
          `sent_at` datetime DEFAULT NULL,
          `sent_count` int(11) NOT NULL DEFAULT 0,
          `last_error` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_push_outbox_due` (`sent_at`, `send_after`),
          KEY `idx_push_outbox_claim` (`claim_token`),
          KEY `idx_push_outbox_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ------------------------------------------------------------------ devices

/** FCM tokens are URL-safe base64 plus ':'. */
function fw_push_valid_token(string $token): bool
{
    return strlen($token) >= 32 && strlen($token) <= 4096 && preg_match('/^[A-Za-z0-9_:\-]+$/', $token) === 1;
}

/**
 * Called on every web request (init.php): bind this app install's push token
 * to the device row it is signed in on. Cheap: the session remembers the
 * binding, so the database is only touched when something changed (or daily).
 */
function fw_push_sync_device(PDO $db): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $sid = (int)($_SESSION['auth_sid'] ?? 0);
    $token = $_COOKIE[FW_PUSH_COOKIE] ?? '';
    if ($uid <= 0 || $sid <= 0 || !is_string($token) || !fw_push_valid_token($token)) return;

    $hash = hash('sha256', $token);
    $mark = $hash . ':' . $uid . ':' . $sid;
    if (($_SESSION['push_bound'] ?? '') === $mark && time() - (int)($_SESSION['push_bound_at'] ?? 0) < FW_PUSH_TOUCH_EVERY) {
        return;
    }

    fw_push_bind($db, $uid, $sid, $token, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['push_bound'] = $mark;
    $_SESSION['push_bound_at'] = time();
}

/** Bind an app install's token to user $uid on signed-in device row $sid. */
function fw_push_bind(PDO $db, int $uid, int $sid, string $token, string $ua): void
{
    if (!fw_push_valid_token($token)) return;
    $hash = hash('sha256', $token);
    $version = preg_match('#FlowWorkApp/([0-9][0-9A-Za-z.\-]{0,18})#', $ua, $m) ? $m[1] : null;
    $label = function_exists('fw_auth_device_label') ? fw_auth_device_label($ua) : 'Flowwork app';
    fw_push_db($db, function () use ($db, $uid, $sid, $hash, $token, $version, $label) {
        $db->prepare("INSERT INTO push_devices (user_id, session_id, token_hash, token, platform, app_version, device_label)
                      VALUES (?, ?, ?, ?, 'android', ?, ?)
                      ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), session_id = VALUES(session_id), token = VALUES(token),
                                              app_version = VALUES(app_version), device_label = VALUES(device_label),
                                              last_seen_at = NOW(), disabled_at = NULL, fail_count = 0, last_error = NULL")
           ->execute([$uid, $sid, $hash, $token, $version, mb_substr($label, 0, 120)]);
        // One token per signed-in device: a rotated token replaces the old one.
        $db->prepare("UPDATE push_devices SET disabled_at = NOW(), last_error = 'replaced'
                       WHERE session_id = ? AND token_hash <> ? AND disabled_at IS NULL")
           ->execute([$sid, $hash]);
    });
}

/** Devices that may receive a push for $userId: bound to a live, signed-in device row. */
function fw_push_devices(PDO $db, int $userId, ?array $onlyIds = null): array
{
    return fw_push_db($db, function () use ($db, $userId, $onlyIds) {
        $sql = "SELECT d.id, d.token, d.session_id
                  FROM push_devices d
                  JOIN user_sessions s ON s.id = d.session_id AND s.user_id = d.user_id
                 WHERE d.user_id = ? AND d.disabled_at IS NULL
                   AND s.revoked_at IS NULL AND s.expires_at > NOW()";
        $args = [$userId];
        if ($onlyIds) {
            $onlyIds = array_values(array_unique(array_map('intval', $onlyIds)));
            $sql .= ' AND d.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $args = array_merge($args, $onlyIds);
        }
        $st = $db->prepare($sql . ' ORDER BY d.id');
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    });
}

/** user_sessions.id => true for the user's devices that currently receive pushes ("Signed-in devices"). */
function fw_push_session_map(PDO $db, int $userId): array
{
    $map = [];
    try {
        foreach (fw_push_devices($db, $userId) as $d) $map[(int)$d['session_id']] = true;
    } catch (Throwable $e) {
        error_log('[push] session map: ' . $e->getMessage());
    }
    return $map;
}

function fw_push_user_has_devices(PDO $db, int $userId): bool
{
    try {
        return (bool)fw_push_devices($db, $userId);
    } catch (Throwable $e) {
        error_log('[push] device lookup: ' . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------------ queue

/**
 * Queue a phone notification for every signed-in app install of $userId.
 *
 * $data is the payload the app reads (see app/push/Notifier.java): title, body,
 * big, sub, url, tag, grp, ch, who, wkey, nid, app, eid ... Values are cast to
 * strings and trimmed to fit FCM's 4 KB limit.
 * $opts: ttl (seconds, default 1 day), priority ('high'|'normal'),
 *        act_key (mail conversation key: adds signed Mark as read / Archive
 *        buttons), only_devices (push_devices ids), delay (seconds).
 *
 * @return int|null outbox id, or null when the user has no app to send to
 */
function fw_push_queue(PDO $db, int $userId, array $data, array $opts = []): ?int
{
    // Not set up yet: queueing would only pile up stale pushes for the day the key arrives.
    if ($userId <= 0 || fw_push_service_account() === null || !fw_push_user_has_devices($db, $userId)) return null;

    $limits = ['title' => 140, 'body' => 400, 'big' => 1200, 'sub' => 120, 'who' => 120, 'url' => 1000, 'tags' => 1000];
    $clean = [];
    foreach ($data as $k => $v) {
        if ($v === null || $v === '' || !is_scalar($v) || !preg_match('/^[a-z]{1,10}$/', (string)$k)) continue;
        $v = (string)$v;
        if (isset($limits[$k]) && mb_strlen($v) > $limits[$k]) $v = rtrim(mb_substr($v, 0, $limits[$k] - 1)) . '…';
        $clean[$k] = $v;
    }
    if (!isset($clean['ts'])) $clean['ts'] = (string)time();
    // FCM takes 4 KB of data in bytes; non-Latin text is 2-4 bytes a character.
    // Keep room for the per-device action token.
    $size = fn() => strlen(json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    foreach (['big', 'body', 'title'] as $k) {
        while (isset($clean[$k]) && $size() > 3000 && mb_strlen($clean[$k]) > 24) {
            $clean[$k] = rtrim(mb_substr($clean[$k], 0, (int)(mb_strlen($clean[$k]) * 0.8))) . '…';
        }
    }
    if ($size() > 3000) unset($clean['big'], $clean['sub']);

    $ttl = max(60, min(28 * 86400, (int)($opts['ttl'] ?? 86400)));
    $payload = [
        'data'     => $clean,
        'ttl'      => $ttl,
        'priority' => ($opts['priority'] ?? 'high') === 'normal' ? 'normal' : 'high',
    ];
    if (!empty($opts['act_key']) && preg_match('/^[te]\d{1,12}$/', (string)$opts['act_key'])) {
        $payload['act_key'] = (string)$opts['act_key'];
        $payload['act_company'] = (int)($opts['act_company'] ?? 0);
    }
    if (!empty($opts['only_devices'])) $payload['only_devices'] = array_values(array_map('intval', (array)$opts['only_devices']));

    $delay = max(0, (int)($opts['delay'] ?? 0));
    $id = (int)fw_push_db($db, function () use ($db, $userId, $payload, $ttl, $delay) {
        $db->prepare("INSERT INTO push_outbox (user_id, payload, send_after, expires_at)
                      VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))")
           ->execute([$userId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $delay, $ttl]);
        return $db->lastInsertId();
    });
    fw_push_flush_at_shutdown($db);
    return $id ?: null;
}

/**
 * Remove notifications from the user's phones (e.g. the conversation was read
 * on another device). Sent at normal priority: no notification is shown, so
 * it must not wake the phone like a new message would.
 */
function fw_push_dismiss(PDO $db, int $userId, array $tags): void
{
    $tags = array_values(array_unique(array_filter(array_map('strval', $tags),
        fn($t) => preg_match('/^[A-Za-z0-9:_.\-]{1,100}$/', $t) === 1)));
    if (!$tags) return;
    foreach (array_chunk($tags, 40) as $chunk) {
        fw_push_queue($db, $userId, ['t' => 'd', 'tags' => implode(',', $chunk)], ['priority' => 'normal', 'ttl' => 86400]);
    }
}

/** Send what was queued once this request has finished (the response is not held up). */
function fw_push_flush_at_shutdown(PDO $db): void
{
    static $registered = false;
    if ($registered) return;
    $registered = true;
    register_shutdown_function(function () use ($db) {
        try {
            if (PHP_SAPI !== 'cli') {
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                while (ob_get_level() > 0 && @ob_end_flush()) {
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                ignore_user_abort(true);
            }
            fw_push_flush($db, 25, 8.0);
        } catch (Throwable $e) {
            error_log('[push] flush at shutdown: ' . $e->getMessage());
        }
    });
}

/**
 * Send due messages. Safe to run from several processes at once: rows are
 * claimed first, and only the claimant may finish them. Returns counts for
 * the worker's log. $onlyId sends just that queue row (the test button).
 */
function fw_push_flush(PDO $db, int $limit = 50, float $budget = 20.0, ?FwFcmClient $fcm = null, ?int $onlyId = null): array
{
    $stats = ['claimed' => 0, 'sent' => 0, 'devices' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
    $deadline = microtime(true) + $budget;
    $account = fw_push_service_account();
    if ($account === null) return $stats + ['error' => 'not_configured'];

    $claim = bin2hex(random_bytes(16));
    $claimed = fw_push_db($db, function () use ($db, $claim, $limit, $onlyId) {
        $where = "sent_at IS NULL AND attempts < ? AND send_after <= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP()
                  AND (claim_token IS NULL OR claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))";
        $args = [$claim, FW_PUSH_MAX_ATTEMPTS];
        if ($onlyId !== null) {
            $where .= ' AND id = ?';
            $args[] = $onlyId;
        }
        $db->prepare("UPDATE push_outbox SET claim_token = ?, claimed_at = UTC_TIMESTAMP()
                       WHERE $where ORDER BY id LIMIT " . max(1, min(500, $limit)))
           ->execute($args);
        $st = $db->prepare("SELECT id, user_id, payload, attempts,
                                   GREATEST(1, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at)) AS ttl_left
                              FROM push_outbox WHERE claim_token = ? ORDER BY id");
        $st->execute([$claim]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    });
    $stats['claimed'] = count($claimed);
    if (!$claimed) return $stats;

    $fcm = $fcm ?? new FwFcmClient($account);
    // Every write below is "... AND claim_token = ?": a row another process
    // re-claimed (ours took over 5 minutes) is no longer ours to finish.
    $done = $db->prepare("UPDATE push_outbox SET sent_at = UTC_TIMESTAMP(), sent_count = ?, last_error = ?, claim_token = NULL
                           WHERE id = ? AND claim_token = ?");
    $later = $db->prepare("UPDATE push_outbox SET attempts = attempts + 1, claim_token = NULL, last_error = ?,
                                  send_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ? AND claim_token = ?");
    $release = $db->prepare("UPDATE push_outbox SET claim_token = NULL WHERE id = ? AND claim_token = ?");

    foreach ($claimed as $row) {
        $rowId = (int)$row['id'];
        if (microtime(true) > $deadline) {
            $release->execute([$rowId, $claim]);
            continue;
        }
        $p = json_decode((string)$row['payload'], true);
        if (!is_array($p) || empty($p['data'])) {
            $done->execute([0, 'bad payload', $rowId, $claim]);
            $stats['skipped']++;
            continue;
        }
        $devices = fw_push_devices($db, (int)$row['user_id'], $p['only_devices'] ?? null);
        if (!$devices) {
            $done->execute([0, 'no devices', $rowId, $claim]);
            $stats['skipped']++;
            continue;
        }
        // What is left of its lifetime: a late retry must not outlive the event it is about.
        $ttl = max(1, min((int)$p['ttl'], (int)$row['ttl_left']));

        $ok = 0;
        $retryIds = [];
        $lastError = null;
        foreach ($devices as $i => $dev) {
            $data = $p['data'];
            if (!empty($p['act_key'])) {
                $data['act'] = fw_push_action_token((int)$row['user_id'], (int)($p['act_company'] ?? 0),
                                                    (string)$p['act_key'], (int)$dev['id'], $account);
            }
            $r = $fcm->send((string)$dev['token'], $data, $ttl, (string)$p['priority']);
            $stats['devices']++;
            if ($r['status'] === 'ok') {
                $ok++;
                $db->prepare("UPDATE push_devices SET last_sent_at = NOW(), fail_count = 0 WHERE id = ?")->execute([(int)$dev['id']]);
            } elseif ($r['status'] === 'invalid') {
                // Uninstalled, data cleared or a token from another project: forget it.
                $db->prepare("UPDATE push_devices SET disabled_at = NOW(), last_error = ? WHERE id = ?")
                   ->execute([mb_substr($r['error'], 0, 255), (int)$dev['id']]);
                $stats['failed']++;
            } else {
                $retryIds[] = (int)$dev['id'];
                $lastError = $r['error'];
                $db->prepare("UPDATE push_devices SET fail_count = fail_count + 1, last_error = ? WHERE id = ?")
                   ->execute([mb_substr($r['error'], 0, 255), (int)$dev['id']]);
            }
            if ($r['status'] === 'fatal') {
                // Configuration problem: every device would fail. Keep the rest for later.
                foreach (array_slice($devices, $i + 1) as $rest) $retryIds[] = (int)$rest['id'];
                break;
            }
            if ($r['status'] === 'drop') {
                // FCM refuses the message itself (not the phone): retrying cannot help.
                $retryIds = [];
                $lastError = $r['error'];
                $stats['failed']++;
                break;
            }
        }

        $attempts = (int)$row['attempts'] + 1;
        if ($retryIds && $attempts < FW_PUSH_MAX_ATTEMPTS) {
            if ($ok > 0) {
                // Some phones got it; retry only the rest so nobody gets it twice.
                $done->execute([$ok, null, $rowId, $claim]);
                if ($done->rowCount() === 1) {
                    fw_push_db($db, fn() => $db->prepare("INSERT INTO push_outbox (user_id, payload, send_after, expires_at, attempts)
                                                          SELECT user_id, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), expires_at, ?
                                                            FROM push_outbox WHERE id = ?")
                                             ->execute([json_encode(['only_devices' => $retryIds] + $p, JSON_UNESCAPED_UNICODE),
                                                        fw_push_backoff($attempts), $attempts, $rowId]));
                }
            } else {
                $later->execute([mb_substr((string)$lastError, 0, 255), fw_push_backoff($attempts), $rowId, $claim]);
            }
            $stats['retry']++;
        } else {
            $done->execute([$ok, ($retryIds || $ok === 0) && $lastError ? mb_substr((string)$lastError, 0, 255) : null, $rowId, $claim]);
        }
        if ($ok > 0) $stats['sent']++;
    }
    return $stats;
}

function fw_push_backoff(int $attempt): int
{
    return min(3600, 30 * (2 ** max(0, $attempt - 1))); // 30 s, 1, 2, 4, 8 min ...
}

/** Housekeeping for the worker: drop old queue rows and long-dead devices. */
function fw_push_cleanup(PDO $db): void
{
    try {
        $db->exec("DELETE FROM push_outbox WHERE (sent_at IS NOT NULL AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY))
                                         OR expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
        $db->exec("DELETE FROM push_devices WHERE disabled_at IS NOT NULL AND disabled_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    } catch (Throwable $e) {
        error_log('[push] cleanup: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------ Firebase credentials

/** The Firebase service account (project_id, client_email, private_key), or null when not set up. */
function fw_push_service_account(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $file = defined('FCM_SERVICE_ACCOUNT_FILE') ? (string)FCM_SERVICE_ACCOUNT_FILE
          : ((string)getenv('FCM_SERVICE_ACCOUNT_FILE') ?: dirname(__DIR__) . '/storage/firebase/service-account.json');
    if ($file === '' || !is_readable($file)) return null;
    $json = json_decode((string)file_get_contents($file), true);
    if (!is_array($json) || ($json['type'] ?? '') !== 'service_account' || empty($json['project_id'])
        || empty($json['client_email']) || empty($json['private_key'])) {
        error_log('[push] ' . $file . ' is not a Firebase service account key');
        return null;
    }
    return $cache = $json;
}

/** Where the OAuth access token is cached between requests (next to the key). */
function fw_push_cache_file(): ?string
{
    $dir = dirname(__DIR__) . '/storage/firebase';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return null;
    return is_writable($dir) ? $dir . '/oauth-token.json' : null;
}

// ------------------------------------------------------------------ action buttons

function fw_push_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function fw_push_b64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/** Server-only signing key, derived from the Firebase private key (no extra secret to manage). */
function fw_push_action_key(?array $account = null): ?string
{
    $account = $account ?? fw_push_service_account();
    return $account ? hash('sha256', "flowwork-push-action\n" . $account['private_key'], true) : null;
}

/** Signed token for one conversation's Mark as read / Archive buttons on one device. */
function fw_push_action_token(int $userId, int $companyId, string $key, int $deviceId, ?array $account = null): string
{
    $secret = fw_push_action_key($account);
    if ($secret === null) return '';
    $body = fw_push_b64url(json_encode(['u' => $userId, 'c' => $companyId, 'k' => $key, 'd' => $deviceId,
                                        'x' => time() + FW_PUSH_ACTION_TTL]));
    return $body . '.' . fw_push_b64url(hash_hmac('sha256', $body, $secret, true));
}

/** @return array{u:int,c:int,k:string,d:int,x:int}|null the verified, unexpired claims */
function fw_push_action_verify(string $token): ?array
{
    $secret = fw_push_action_key();
    if ($secret === null || strlen($token) > 1000 || substr_count($token, '.') !== 1) return null;
    [$body, $sig] = explode('.', $token, 2);
    if (!hash_equals(fw_push_b64url(hash_hmac('sha256', $body, $secret, true)), $sig)) return null;
    $c = json_decode(fw_push_b64url_decode($body), true);
    if (!is_array($c) || (int)($c['x'] ?? 0) < time() || !preg_match('/^[te]\d{1,12}$/', (string)($c['k'] ?? ''))) return null;
    return ['u' => (int)$c['u'], 'c' => (int)$c['c'], 'k' => (string)$c['k'], 'd' => (int)$c['d'], 'x' => (int)$c['x']];
}

// ------------------------------------------------------------------ FCM HTTP v1

/**
 * Minimal FCM HTTP v1 client: OAuth2 service-account JWT (RS256, openssl) and
 * messages:send over one reused cURL handle. No Composer packages needed.
 */
final class FwFcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private array $account;
    private ?string $accessToken = null;
    private int $accessExpires = 0;
    /** @var resource|\CurlHandle|null */
    private $curl = null;
    /** @var callable|null test hook: fn(string $url, array $headers, string $body): array{0:int,1:string} */
    private $transport;

    public function __construct(array $account, ?callable $transport = null)
    {
        $this->account = $account;
        $this->transport = $transport;
    }

    /**
     * @return array{status:string,error:string} status: ok | invalid (forget this token)
     *         | retry (try again later) | fatal (configuration problem, keep everything)
     *         | drop (FCM refuses this message; retrying cannot help)
     */
    public function send(string $token, array $data, int $ttl, string $priority): array
    {
        $message = [
            'message' => [
                'token'   => $token,
                'data'    => array_map('strval', $data),
                'android' => [
                    'priority' => $priority === 'normal' ? 'NORMAL' : 'HIGH',
                    'ttl'      => max(0, $ttl) . 's',
                ],
            ],
        ];
        $body = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->account['project_id']) . '/messages:send';

        for ($try = 0; $try < 2; $try++) {
            $access = $this->accessToken($try > 0);
            if ($access === null) return ['status' => 'fatal', 'error' => 'oauth: could not get an access token'];
            [$code, $resp] = $this->post($url, ['Authorization: Bearer ' . $access, 'Content-Type: application/json; charset=utf-8'], $body);
            if ($code === 200) return ['status' => 'ok', 'error' => ''];
            if ($code === 401 && $try === 0) continue; // token expired early: fetch a new one once
            return $this->classify($code, $resp);
        }
        return ['status' => 'retry', 'error' => 'unauthorized'];
    }

    private function classify(int $code, string $resp): array
    {
        $j = json_decode($resp, true);
        $status = (string)($j['error']['status'] ?? '');
        $fcmCode = '';
        foreach ((array)($j['error']['details'] ?? []) as $d) {
            if (isset($d['errorCode'])) $fcmCode = (string)$d['errorCode'];
        }
        $msg = trim($code . ' ' . ($fcmCode ?: $status) . ' ' . (string)($j['error']['message'] ?? ''));
        if ($code === 0) return ['status' => 'retry', 'error' => 'network: ' . $resp];
        // Only these mean "this phone's token is dead". A 404 for any other
        // reason (wrong project_id) or SENDER_ID_MISMATCH (key of another
        // Firebase project) is a server setup problem: never blame the phones.
        if ($fcmCode === 'UNREGISTERED') return ['status' => 'invalid', 'error' => $msg];
        if ($code === 400 && ($fcmCode === 'INVALID_ARGUMENT' || $status === 'INVALID_ARGUMENT')) {
            return stripos($resp, 'registration token') !== false
                ? ['status' => 'invalid', 'error' => $msg]
                : ['status' => 'drop', 'error' => $msg];
        }
        if ($code === 429 || $code >= 500) return ['status' => 'retry', 'error' => $msg];
        if (in_array($code, [401, 403, 404], true)) return ['status' => 'fatal', 'error' => $msg];
        return ['status' => 'retry', 'error' => $msg];
    }

    /** OAuth2 access token for the service account, cached on disk until shortly before it expires. */
    private function accessToken(bool $forceNew): ?string
    {
        $now = time();
        if (!$forceNew && $this->accessToken && $this->accessExpires > $now + 60) return $this->accessToken;

        $cacheFile = $this->transport ? null : fw_push_cache_file();
        $cacheKey = hash('sha256', $this->account['client_email'] . "\n" . ($this->account['private_key_id'] ?? ''));
        if (!$forceNew && $cacheFile && is_readable($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['key'] ?? '') === $cacheKey && (int)($c['exp'] ?? 0) > $now + 120 && !empty($c['token'])) {
                $this->accessToken = (string)$c['token'];
                $this->accessExpires = (int)$c['exp'];
                return $this->accessToken;
            }
        }

        $tokenUri = (string)($this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $jwt = self::jwt($this->account, $tokenUri, $now);
        if ($jwt === null) return null;
        [$code, $resp] = $this->post($tokenUri, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
        $j = json_decode($resp, true);
        if ($code !== 200 || empty($j['access_token'])) {
            error_log('[push] oauth token request failed: HTTP ' . $code . ' ' . mb_substr($resp, 0, 300));
            return null;
        }
        $this->accessToken = (string)$j['access_token'];
        $this->accessExpires = $now + max(300, (int)($j['expires_in'] ?? 3600));
        if ($cacheFile) {
            $tmp = $cacheFile . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode(['key' => $cacheKey, 'token' => $this->accessToken, 'exp' => $this->accessExpires])) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $cacheFile);
            }
        }
        return $this->accessToken;
    }

    /** Signed service-account assertion (RFC 7523). */
    public static function jwt(array $account, string $aud, int $now): ?string
    {
        $key = openssl_pkey_get_private((string)$account['private_key']);
        if ($key === false) {
            error_log('[push] the service account private_key could not be read');
            return null;
        }
        $head = fw_push_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => (string)($account['private_key_id'] ?? '')]));
        $claims = fw_push_b64url(json_encode([
            'iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (!openssl_sign($head . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
        return $head . '.' . $claims . '.' . fw_push_b64url($sig);
    }

    /** @return array{0:int,1:string} HTTP status (0 = network error) and body */
    private function post(string $url, array $headers, string $body): array
    {
        if ($this->transport) return ($this->transport)($url, $headers, $body);
        if (function_exists('curl_init')) {
            if (!$this->curl) $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($this->curl);
            if ($resp === false) return [0, curl_error($this->curl)];
            return [(int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), (string)$resp];
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => 10, 'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        return [$code, $resp === false ? 'request failed' : (string)$resp];
    }
}
