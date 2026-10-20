<?php
// /admin/api/invites.php — invitations to join the company.
//   POST {action:'send', email, role, days, message}  → {invite, link, emailed, via, from?, email_error?}
//   POST {action:'resend', id}                          → same shape; the link is renewed for the same span
//   POST {action:'revoke', id}
// The email goes out from the admin's own mailbox when connected, else
// Flowwork's system mail; when neither works the link is returned to copy.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AdminSchema.php';
require_once __DIR__ . '/../lib/AdminMail.php';

admin_require_post();
AdminSchema::ensure($DB);
$in = admin_input();
$action = admin_str($in, 'action', 20);

function invite_link(string $token): string
{
    $base = defined('APP_BASE_URL') && APP_BASE_URL ? rtrim(APP_BASE_URL, '/')
          : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $base . '/accept-invite.php?token=' . $token;
}

function invite_row(PDO $db, int $companyId, int $id): array
{
    $st = $db->prepare("SELECT id, email, role, expires_at, created_at, used, last_sent_at, send_count, custom_message FROM invites WHERE id = ? AND company_id = ?");
    $st->execute([$id, $companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) admin_error('That invitation no longer exists.', 404);
    return $r;
}

function invite_deliver(PDO $db, int $companyId, int $userId, int $id, string $token): array
{
    $inv = invite_row($db, $companyId, $id);
    $link = invite_link($token);
    $res = AdminMail::invite($db, $companyId, $userId, ['email' => $inv['email'], 'role' => $inv['role'], 'link' => $link,
                                                         'expires_at' => $inv['expires_at'], 'message' => $inv['custom_message']]);
    if ($res['ok']) {
        $db->prepare("UPDATE invites SET last_sent_at = NOW(), send_count = send_count + 1 WHERE id = ?")->execute([$id]);
        $inv = invite_row($db, $companyId, $id);
    }
    unset($inv['custom_message']);
    return ['invite' => $inv, 'link' => $link, 'emailed' => $res['ok'], 'via' => $res['via'], 'from' => $res['from'] ?? null, 'email_error' => $res['error'] ?? null];
}

switch ($action) {
    case 'send':
        admin_rate_limit('admin-invite', 30, 3600);
        $email = mb_strtolower(admin_str($in, 'email', 190));
        $role = admin_str($in, 'role', 20);
        $days = in_array(admin_int($in, 'days'), [3, 7, 14, 30], true) ? admin_int($in, 'days') : 7;
        $message = mb_substr(trim(str_replace("\r", '', (string)($in['message'] ?? ''))), 0, 1000);
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (!isset(AdminKit::ROLES[$role])) $errors['role'] = 'Choose a role.';
        if ($errors) throw new AdminInvalid($errors);

        $st = $DB->prepare("SELECT 1 FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? AND u.email = ?");
        $st->execute([$ADMIN_COMPANY_ID, $email]);
        if ($st->fetchColumn()) throw new AdminInvalid(['email' => 'They are already part of this company.']);
        $st = $DB->prepare("SELECT id FROM invites WHERE company_id = ? AND email = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $st->execute([$ADMIN_COMPANY_ID, $email]);
        if ($st->fetchColumn()) throw new AdminInvalid(['email' => 'They already have an open invitation. Resend it from Invitations instead.']);
        if ($role !== 'bookkeeper') {
            $s = AdminKit::seats($DB, $ADMIN_COMPANY_ID);
            if ($s['used'] >= $s['max']) throw new AdminInvalid(['role' => 'All ' . $s['max'] . ' paid seats are in use, so they could not join. Free a seat or move to a bigger plan first.']);
        }

        // Expired, unused invitations for the same address are replaced.
        $DB->prepare("DELETE FROM invites WHERE company_id = ? AND email = ? AND used = 0")->execute([$ADMIN_COMPANY_ID, $email]);
        $token = bin2hex(random_bytes(32));
        $DB->prepare("INSERT INTO invites (company_id, email, token, role, expires_at, created_by, created_at, custom_message)
                      VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW(), ?)")
           ->execute([$ADMIN_COMPANY_ID, $email, $token, $role, $days, $ADMIN_USER_ID, $message !== '' ? $message : null]);
        $id = (int)$DB->lastInsertId();
        $out = invite_deliver($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $id, $token);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'invite_sent', ['email' => $email, 'role' => AdminKit::ROLES[$role][0], 'emailed' => $out['emailed'] ? 'yes' : 'no — link copied by hand'], 'invite', $id);
        admin_json($out);

    case 'resend':
        admin_rate_limit('admin-invite', 30, 3600);
        $id = admin_int($in, 'id');
        $inv = invite_row($DB, $ADMIN_COMPANY_ID, $id);
        if ((int)$inv['used']) admin_error('This invitation was already accepted.');
        // A fresh link that works for as long as the original did (at least 7 days).
        $span = max(7, (int)round((strtotime($inv['expires_at']) - strtotime($inv['created_at'])) / 86400));
        $token = bin2hex(random_bytes(32));
        $DB->prepare("UPDATE invites SET token = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), created_at = NOW() WHERE id = ? AND company_id = ?")
           ->execute([$token, min(30, $span), $id, $ADMIN_COMPANY_ID]);
        $out = invite_deliver($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $id, $token);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'invite_resent', ['email' => $inv['email'], 'emailed' => $out['emailed'] ? 'yes' : 'no'], 'invite', $id);
        admin_json($out);

    case 'link':
        // The current link of an open invitation (to copy and send by hand).
        $id = admin_int($in, 'id');
        $inv = invite_row($DB, $ADMIN_COMPANY_ID, $id);
        if ((int)$inv['used'] || strtotime($inv['expires_at']) < time()) admin_error('This invitation is no longer open. Resend it to get a fresh link.');
        $st = $DB->prepare("SELECT token FROM invites WHERE id = ?");
        $st->execute([$id]);
        admin_json(['link' => invite_link((string)$st->fetchColumn())]);

    case 'revoke':
        $id = admin_int($in, 'id');
        $inv = invite_row($DB, $ADMIN_COMPANY_ID, $id);
        if ((int)$inv['used']) admin_error('This invitation was already accepted — remove the person under People instead.');
        $DB->prepare("DELETE FROM invites WHERE id = ? AND company_id = ? AND used = 0")->execute([$id, $ADMIN_COMPANY_ID]);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'invite_revoked', ['email' => $inv['email']], 'invite', $id);
        admin_json();
}
admin_error('Unknown action');
