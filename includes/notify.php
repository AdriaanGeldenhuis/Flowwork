<?php
// /includes/notify.php
//
// One call for a user notification: the in-app row (the bell) and the phone
// push (Flowwork Android app) in one go.
//
//   fw_notify($DB, $companyId, $userId, 'mention', 'Anna mentioned you', '"Site visit": can you…',
//             '/projects/board.php?board_id=3&item=9', ['ch' => 'projects', 'who' => 'Anna Smith']);
//
// The `notifications` table was never part of a migration, so it is created
// here on first use (and older copies of it get the columns they miss).
// Run Migrations/2026-10-16-push-notifications.sql to create it up front.

require_once __DIR__ . '/push.php';

/** Push channel for an in-app notification type (the app's notification categories). */
function fw_notify_channel(string $type): string
{
    if (str_starts_with($type, 'mail_sla')) return 'mail_reminders';
    if (str_starts_with($type, 'mail')) return 'mail';
    if (str_starts_with($type, 'calendar') || str_starts_with($type, 'event')) return 'calendar';
    if ($type === 'mention' || str_starts_with($type, 'project')) return 'projects';
    return 'general';
}

/**
 * @param array|false $push false: bell only. Otherwise push options, all optional:
 *   ch, grp, tag, who, wkey, sub, big, app ('mail'), eid, ttl (seconds), act_key (mail conversation key),
 *   title / body (override the bell texts on the phone)
 * @return int|null the notifications.id, or null when it could not be stored
 */
function fw_notify(PDO $db, int $companyId, int $userId, string $type, string $title, string $message,
                   string $url = '', $push = []): ?int
{
    if ($userId <= 0) return null;
    $title = mb_substr(trim($title), 0, 255);
    $message = mb_substr(trim($message), 0, 500);
    $url = mb_substr($url, 0, 500);

    $id = null;
    try {
        $id = (int)fw_notify_db($db, function () use ($db, $companyId, $userId, $type, $title, $message, $url) {
            // `link` is what the bell's JavaScript reads; `url` what most writers set. Keep both.
            $db->prepare("INSERT INTO notifications (company_id, user_id, type, title, message, url, link, is_read, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())")
               ->execute([$companyId, $userId, mb_substr($type, 0, 40), $title, $message, $url ?: null, $url ?: null]);
            return $db->lastInsertId();
        }) ?: null;
    } catch (Throwable $e) {
        error_log('[notify] ' . $type . ' for user ' . $userId . ': ' . $e->getMessage());
    }

    // No bell row, no push: a caller that retries a failed notification (the
    // calendar cron) must not buzz the phone again on every attempt.
    if ($push !== false && $id !== null) {
        try {
            $p = is_array($push) ? $push : [];
            $ch = (string)($p['ch'] ?? fw_notify_channel($type));
            $data = [
                'ch'    => $ch,
                'grp'   => (string)($p['grp'] ?? $ch),
                'title' => (string)($p['title'] ?? $title),
                'body'  => (string)($p['body'] ?? $message),
                'url'   => fw_notify_push_url($url, $companyId),
                'tag'   => (string)($p['tag'] ?? ($id ? 'n:' . $id : '')),
                'nid'   => $id ? (string)$id : '',
            ];
            foreach (['who', 'wkey', 'sub', 'big', 'app', 'eid'] as $k) {
                if (isset($p[$k]) && $p[$k] !== '') $data[$k] = (string)$p[$k];
            }
            if (str_starts_with($ch, 'mail')) $data['app'] = 'mail';
            fw_push_queue($db, $userId, $data, [
                'ttl' => (int)($p['ttl'] ?? 86400),
                'act_key' => $p['act_key'] ?? null,
                'act_company' => $companyId,
            ]);
        } catch (Throwable $e) {
            error_log('[notify] push ' . $type . ' for user ' . $userId . ': ' . $e->getMessage());
        }
    }
    return $id;
}

/**
 * Tapping a push opens this URL. It carries the company it belongs to, so a
 * user in several companies lands in the right one (see fw_notify_apply_link).
 */
function fw_notify_push_url(string $url, int $companyId): string
{
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) $url = '/home.php';
    if ($companyId <= 0) return $url;
    $hash = '';
    if (($p = strpos($url, '#')) !== false) {
        $hash = substr($url, $p);
        $url = substr($url, 0, $p);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'fw_co=' . $companyId . $hash;
}

/**
 * Called from auth_gate.php once the device is signed in (also after a
 * "keep me signed in" restore) and before the active company is resolved.
 * A notification tap from the app adds ?fw_nid= (mark that bell item read)
 * and ?fw_co= (switch to the company it belongs to, when the user is a
 * member). Both only act for the signed-in user's own rows/companies, and
 * the company switch only for the app.
 */
function fw_notify_apply_link(PDO $db): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || empty($_SESSION['user_id'])) return;
    $uid = (int)$_SESSION['user_id'];

    $nid = $_GET['fw_nid'] ?? '';
    if (is_string($nid) && $nid !== '' && ctype_digit($nid) && strlen($nid) <= 18) {
        try {
            $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0")
               ->execute([(int)$nid, $uid]);
        } catch (Throwable $e) {
            error_log('[notify] mark read from link: ' . $e->getMessage());
        }
    }

    $co = $_GET['fw_co'] ?? '';
    if (is_string($co) && $co !== '' && ctype_digit($co) && strlen($co) <= 10
        && function_exists('fw_auth_is_app') && fw_auth_is_app()) {
        try {
            require_once __DIR__ . '/companies.php';
            if ((int)$co !== (int)($_SESSION['company_id'] ?? 0)) fw_set_active_company($uid, (int)$co);
        } catch (Throwable $e) {
            error_log('[notify] company from link: ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------------ schema

/** Run $fn; create/repair the notifications table on "missing table" or "unknown column" and retry once. */
function fw_notify_db(PDO $db, callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        fw_notify_install($db);
        return $fn();
    }
}

function fw_notify_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `company_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `type` varchar(40) NOT NULL DEFAULT 'general',
          `title` varchar(255) NOT NULL DEFAULT '',
          `message` varchar(500) NOT NULL DEFAULT '',
          `url` varchar(500) DEFAULT NULL,
          `link` varchar(500) DEFAULT NULL,
          `is_read` tinyint(1) NOT NULL DEFAULT 0,
          `read_at` datetime DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_notifications_user` (`company_id`, `user_id`, `is_read`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // A copy created by hand may lack columns the code uses.
    $have = [];
    foreach ($db->query("SHOW COLUMNS FROM `notifications`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $have[strtolower($c['Field'])] = true;
    }
    $add = [
        'type'       => "`type` varchar(40) NOT NULL DEFAULT 'general'",
        'title'      => "`title` varchar(255) NOT NULL DEFAULT ''",
        'message'    => "`message` varchar(500) NOT NULL DEFAULT ''",
        'url'        => "`url` varchar(500) DEFAULT NULL",
        'link'       => "`link` varchar(500) DEFAULT NULL",
        'is_read'    => "`is_read` tinyint(1) NOT NULL DEFAULT 0",
        'read_at'    => "`read_at` datetime DEFAULT NULL",
        'created_at' => "`created_at` datetime NOT NULL DEFAULT current_timestamp()",
    ];
    foreach ($add as $col => $ddl) {
        if (!isset($have[$col])) $db->exec("ALTER TABLE `notifications` ADD COLUMN $ddl");
    }
}
