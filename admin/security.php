<?php
// /admin/security.php — Security: sign-in protection (read by
// fw_login_limits / fw_auth_single_device), password rules (read by
// includes/password_policy.php wherever a password is chosen), every
// signed-in device in the company with sign-out, and recent sign-in activity.
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'security');

$devices = [];
try {
    $st = $DB->prepare("SELECT s.id, s.user_id, s.device_label, s.ip, s.is_app, s.remember_hash IS NOT NULL AS remembered, s.last_seen_at, s.created_at,
                               CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email
                          FROM user_sessions s
                          JOIN user_companies uc ON uc.user_id = s.user_id AND uc.company_id = ?
                          JOIN users u ON u.id = s.user_id
                         WHERE s.revoked_at IS NULL AND s.expires_at > NOW()
                      ORDER BY s.last_seen_at DESC LIMIT 300");
    $st->execute([$ADMIN_COMPANY_ID]);
    $devices = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
}
$failed = [];
try {
    $st = $DB->prepare("SELECT a.email, a.ip, COUNT(*) AS tries, MAX(a.attempted_at) AS last_at
                          FROM auth_login_attempts a
                         WHERE a.email IN (SELECT u.email FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ?)
                      GROUP BY a.email, a.ip ORDER BY last_at DESC LIMIT 50");
    $st->execute([$ADMIN_COMPANY_ID]);
    $failed = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
}
$st = $DB->prepare("SELECT al.action, COALESCE(al.timestamp, al.created_at) AS at, al.ip, al.details, CONCAT_WS(' ', u.first_name, u.last_name) AS name
                      FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                     WHERE al.company_id = ? AND al.action IN ('user_login', 'user_logout', 'password_reset', 'password_reset_requested', 'user_signed_out', 'device_signed_out', 'user_suspended')
                  ORDER BY al.id DESC LIMIT 25");
$st->execute([$ADMIN_COMPANY_ID]);
$activity = $st->fetchAll(PDO::FETCH_ASSOC);
$mySid = (int)($_SESSION['auth_sid'] ?? 0);
$people = count(array_unique(array_column($devices, 'user_id')));

$page = 'security';
$pageTitle = 'Security';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Workspace <i>◆</i> Security</p>
        <h1>Security</h1>
        <p><?= count($devices) ?> device<?= count($devices) === 1 ? '' : 's' ?> signed in for <?= $people ?> <?= $people === 1 ? 'person' : 'people' ?><?= $failed ? ' · <b>' . array_sum(array_column($failed, 'tries')) . ' failed sign-in attempt' . (array_sum(array_column($failed, 'tries')) === 1 ? '' : 's') . '</b> in the last day' : ' · no failed sign-ins in the last day' ?>.</p>
    </div>
</header>

<form data-settings="security" novalidate>
    <?= AdminSettings::render('security', $values) ?>
</form>

<section class="fwa-card" id="card-devices" style="margin-top:16px">
    <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#ih-devices"/></svg></span>
        <div class="fwa-card__title"><h3>Signed-in devices</h3><p>Every phone, tablet and computer signed in to this company. Sign one out if it is lost, or if you don’t recognise it.</p></div></header>
    <?php if (!$devices): ?>
        <div class="fwa-card__body fwa-muted">No devices are signed in.</div>
    <?php else: ?>
        <div class="fwa-table-wrap"><table class="fwa-table">
            <thead><tr><th>Person</th><th>Device</th><th class="hide-narrow">Last used</th><th class="hide-narrow">Signed in</th><th class="t-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="deviceBody">
            <?php foreach ($devices as $d): $name = trim((string)$d['name']) ?: $d['email']; $mine = (int)$d['id'] === $mySid; ?>
                <tr data-sid="<?= (int)$d['id'] ?>">
                    <td><div class="fwa-who"><span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:<?= AdminKit::hue($name) ?>"><?= $e(AdminKit::initials($name)) ?></span><span class="fwa-who__text"><b><?= $e($name) ?></b><small><?= $e($d['email']) ?></small></span></div></td>
                    <td><?= $e($d['device_label'] ?: 'Unknown device') ?><?= $mine ? '<span class="fwa-you">This one</span>' : '' ?><small class="fwa-muted" style="display:block;margin-top:2px"><?= (int)$d['is_app'] ? 'Flowwork app' : 'Browser' ?><?= (int)$d['remembered'] ? ' · kept signed in' : '' ?><?= $d['ip'] ? ' · ' . $e($d['ip']) : '' ?></small></td>
                    <td class="hide-narrow"><?= $e(AdminKit::ago($d['last_seen_at'])) ?></td>
                    <td class="hide-narrow"><?= $e(AdminKit::date($d['created_at'])) ?></td>
                    <td class="t-actions"><?php if (!$mine): ?><button class="fwa-mini" type="button" data-signout><svg class="i"><use href="#ia-logout-all"/></svg>Sign out</button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <div class="fwa-table-foot"><svg class="i i--sm"><use href="#i-info"/></svg>To sign one person out everywhere, use <a href="/admin/users.php">People &amp; roles</a> › ⋯ › Sign out everywhere. Your own devices are also under <a href="/account/">Signed-in devices</a>.</div>
</section>

<div class="fwa-cols" style="margin-top:16px">
    <section class="fwa-card" id="card-activity">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#ia-pulse"/></svg></span>
            <div class="fwa-card__title"><h3>Sign-in activity</h3><p>The latest sign-ins, sign-outs and password resets.</p></div></header>
        <?php if (!$activity): ?>
            <div class="fwa-card__body fwa-muted">Nothing yet.</div>
        <?php else: ?>
            <ul class="fwa-feed">
                <?php foreach ($activity as $a): [$label, $tone] = AdminKit::actionLabel((string)$a['action']); $who = trim((string)$a['name']) ?: 'Someone'; $det = json_decode((string)$a['details'], true); ?>
                    <li><span class="fwa-dot" data-tone="<?= $tone ?>" style="margin-top:6px"></span>
                        <span class="fwa-feed__body"><b><?= $e($who) ?></b> <span><?= $e(mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1)) ?></span>
                            <small><?= $e(AdminKit::ago($a['at'])) ?><?= is_array($det) && !empty($det['device']) ? ' · ' . $e($det['device']) : '' ?><?= $a['ip'] ? ' · ' . $e($a['ip']) : '' ?></small></span></li>
                <?php endforeach; ?>
            </ul>
            <div class="fwa-feed__more"><a class="btn btn--sm btn--ghost" href="/admin/audit.php?kind=signin">Everything in the audit trail <svg class="i i--sm"><use href="#i-chev-right"/></svg></a></div>
        <?php endif; ?>
    </section>
    <section class="fwa-card" id="card-failed">
        <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-alert"/></svg></span>
            <div class="fwa-card__title"><h3>Failed sign-ins</h3><p>Wrong passwords in the last 24 hours. A few are normal; many from one address are not.</p></div></header>
        <?php if (!$failed): ?>
            <div class="fwa-card__body"><div class="fwa-banner fwa-banner--ok" style="margin:0"><svg class="i"><use href="#i-check"/></svg><div class="fwa-banner__body">None. All quiet.</div></div></div>
        <?php else: ?>
            <ul class="fwa-feed">
                <?php foreach ($failed as $f): $many = (int)$f['tries'] >= (int)$values['security_max_login_attempts']; ?>
                    <li><span class="fwa-dot" data-tone="<?= $many ? 'danger' : 'warning' ?>" style="margin-top:6px"></span>
                        <span class="fwa-feed__body"><b><?= $e($f['email']) ?></b> <span><?= (int)$f['tries'] ?> wrong password<?= (int)$f['tries'] === 1 ? '' : 's' ?><?= $many ? ' — paused' : '' ?></span>
                            <small><?= $e(AdminKit::ago($f['last_at'])) ?> · from <?= $e($f['ip']) ?></small></span></li>
                <?php endforeach; ?>
            </ul>
            <div style="height:8px"></div>
        <?php endif; ?>
    </section>
</div>
<?php
$pageScripts = ['security'];
include __DIR__ . '/partials/foot.php';
