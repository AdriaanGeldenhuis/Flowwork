<?php
// /admin/index.php — the admin overview: people, invitations, projects and
// plan at a glance; a set-up checklist that links to each missing piece;
// anything that needs attention; recent activity; and every settings area.
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/lib/AdminMail.php';

$cid = $ADMIN_COMPANY_ID;
$c = $ADMIN_COMPANY;
$q = function (string $sql, array $params = [], $default = 0) use ($DB) {
    try {
        $st = $DB->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        error_log('[admin/index] ' . $e->getMessage());
        return $default;
    }
};

$seats = AdminKit::seats($DB, $cid);
$people = (int)$q("SELECT COUNT(*) FROM user_companies WHERE company_id = ?", [$cid]);
$admins = (int)$q("SELECT COUNT(*) FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? AND uc.role = 'admin' AND u.status = 'active'", [$cid]);
$suspended = (int)$q("SELECT COUNT(*) FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE uc.company_id = ? AND u.status <> 'active'", [$cid]);
$invOpen = (int)$q("SELECT COUNT(*) FROM invites WHERE company_id = ? AND used = 0 AND expires_at > NOW()", [$cid]);
$invSoon = (int)$q("SELECT COUNT(*) FROM invites WHERE company_id = ? AND used = 0 AND expires_at > NOW() AND expires_at < DATE_ADD(NOW(), INTERVAL 2 DAY)", [$cid]);
$projects = (int)$q("SELECT COUNT(*) FROM projects WHERE company_id = ? AND COALESCE(archived, 0) = 0 AND status IN ('draft', 'active', 'on_hold')", [$cid]);
$boards = (int)$q("SELECT COUNT(*) FROM project_boards WHERE company_id = ? AND COALESCE(archived, 0) = 0", [$cid]);
$sub = null;
try {
    $st = $DB->prepare("SELECT status, current_period_end FROM subscriptions WHERE company_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $st->execute([$cid]);
    $sub = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
}
$mailbox = AdminMail::mailbox($DB, $cid, $ADMIN_USER_ID);
$has = fn(string $k) => trim((string)($c[$k] ?? '')) !== '';

// ---------------------------------------------------------------- checklist
$steps = [
    ['Company details', 'Address, phone and email for your documents', '/admin/company.php#card-contact', $has('address_line1') && $has('city') && ($has('phone') || $has('email'))],
    ['Registration & tax numbers', 'CIPC registration, plus VAT if you are registered', '/admin/company.php#card-tax', $has('reg_number') || $has('vat_number') || $has('tax_reference')],
    ['Your logo', 'Shown on quotes, invoices and statements', '/admin/company.php#card-logo', $has('logo_url')],
    ['Banking details', 'So customers know where to pay', '/qi/settings.php#banking', $has('bank_account_number')],
    ['Invite your team', 'Colleagues work with you in every module', '/admin/users.php?add=1', $people > 1 || $invOpen > 0],
    ['A second admin', 'So someone else can help if you are away', '/admin/users.php', $admins > 1],
    ['Connect your mailbox', 'Send quotes, invoices and invitations from your own address', '/mail/settings.php', $mailbox !== null],
    ['Card payments', 'A “Pay now” button on every invoice, through Yoco', '/admin/integrations.php', $has('yoco_sec_key')],
];
$done = count(array_filter($steps, fn($s) => $s[3]));
$pct = (int)round($done / count($steps) * 100);

// ---------------------------------------------------------------- attention
$attention = [];
if ($seats['used'] >= $seats['max']) $attention[] = ['warning', 'All ' . $seats['max'] . ' paid seats are in use', 'Nobody new can join until a seat is freed or you add seats.', '/admin/billing.php', 'See plans'];
if ($invSoon) $attention[] = ['gold', $invSoon . ' invitation' . ($invSoon === 1 ? '' : 's') . ' expire' . ($invSoon === 1 ? 's' : '') . ' within two days', 'Resend if they have not seen it yet.', '/admin/invites.php', 'Invitations'];
if ($suspended) $attention[] = ['danger', $suspended . ' suspended ' . ($suspended === 1 ? 'person' : 'people'), 'They cannot sign in. Reactivate or remove them to tidy up.', '/admin/users.php', 'People'];
if ($admins < 2) $attention[] = ['info', 'Only one admin', 'If you are away, nobody else can change settings or add people.', '/admin/users.php', 'Add an admin'];
if ($sub && in_array($sub['status'], ['past_due', 'canceled'], true)) $attention[] = ['danger', $sub['status'] === 'past_due' ? 'Your subscription payment is overdue' : 'Your subscription is cancelled', 'Access ends when the current period does.', '/admin/billing.php', 'Subscription'];
if ($has('yoco_sec_key') && !$has('yoco_webhook_secret')) $attention[] = ['warning', 'Card payments are half set up', 'Without the webhook secret, payments are not recorded on invoices by themselves.', '/admin/integrations.php', 'Finish set-up'];
if (!$mailbox && !AdminMail::systemReady() && $invOpen) $attention[] = ['info', 'Invitations are not emailed', 'Connect your mailbox so Flowwork can send them for you.', '/mail/settings.php', 'Connect'];

// ---------------------------------------------------------------- activity
$activity = [];
try {
    $st = $DB->prepare("SELECT al.action, al.details, COALESCE(al.timestamp, al.created_at) AS at, CONCAT_WS(' ', u.first_name, u.last_name) AS who
                          FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                         WHERE al.company_id = ? AND al.action NOT IN ('user_login', 'user_logout', 'company_switch')
                      ORDER BY al.id DESC LIMIT 8");
    $st->execute([$cid]);
    $activity = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$hour = (int)date('G');
$firstName = (string)($_SESSION['user_first_name'] ?? '');
$colors = ['Company' => '#7a1f30', 'People' => '#2f5c84', 'Modules' => '#2f7457', 'Workspace' => '#9a7136', 'Account' => '#6b3a6e'];

$page = 'index';
$pageTitle = 'Overview';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow"><?= $e(date('l')) ?> <i>◆</i> <?= $e(date('j F Y')) ?></p>
        <h1><?= $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening') ?><?= $firstName !== '' ? ', <em>' . $e($firstName) . '</em>' : '' ?></h1>
        <p>Everything about <b><?= $e($companyName) ?></b> in one place: who works here, how each module behaves, and your subscription.</p>
    </div>
    <div class="fwa-head__actions">
        <button class="btn btn--primary" type="button" data-add-person><svg class="i"><use href="#ia-user-plus"/></svg>Add someone</button>
    </div>
</header>

<div class="fwa-stats">
    <a class="fwa-stat" href="/admin/users.php" style="--i:0">
        <span class="fwa-stat__label">People</span>
        <span class="fwa-stat__value"><?= $people ?></span>
        <span class="fwa-meter<?= $seats['used'] >= $seats['max'] ? ' is-full' : '' ?>"><i style="--p:<?= min(100, round($seats['used'] / $seats['max'] * 100)) ?>%"></i></span>
        <span class="fwa-stat__sub"><?= (int)$seats['used'] ?> of <?= (int)$seats['max'] ?> paid seats in use</span>
    </a>
    <a class="fwa-stat" href="/admin/invites.php" style="--i:1">
        <span class="fwa-stat__label">Invitations</span>
        <span class="fwa-stat__value"><?= $invOpen ?></span>
        <span class="fwa-stat__sub"><?= $invOpen ? 'waiting to be accepted' : 'none waiting' ?></span>
    </a>
    <a class="fwa-stat" href="/admin/boards.php" style="--i:2">
        <span class="fwa-stat__label">Projects</span>
        <span class="fwa-stat__value"><?= $projects ?></span>
        <span class="fwa-stat__sub"><?= $boards ?> board<?= $boards === 1 ? '' : 's' ?> in use</span>
    </a>
    <a class="fwa-stat<?= $sub && in_array($sub['status'], ['past_due', 'canceled'], true) ? ' is-alert' : '' ?>" href="/admin/billing.php" style="--i:3">
        <span class="fwa-stat__label">Plan</span>
        <span class="fwa-stat__value"><?= $e($c['plan_name'] ?? '—') ?></span>
        <span class="fwa-stat__sub"><?php
            if (!$sub) echo 'No subscription on record';
            elseif ($sub['status'] === 'canceled') echo 'Cancelled' . ($sub['current_period_end'] ? ' · ends ' . $e(AdminKit::date($sub['current_period_end'])) : '');
            elseif ($sub['status'] === 'past_due') echo 'Payment overdue';
            elseif ($sub['current_period_end'] && strtotime($sub['current_period_end']) > time()) echo 'Renews ' . $e(AdminKit::date($sub['current_period_end']));
            else echo $e(AdminKit::money((int)($c['price_monthly_cents'] ?? 0))) . ' a month';
        ?></span>
    </a>
</div>

<div class="fwa-cols" style="margin-top:18px">
    <div>
        <section class="fwa-card" id="card-setup">
            <header class="fwa-card__head">
                <span class="fwa-card__icon"><svg class="i"><use href="#i-check-square"/></svg></span>
                <div class="fwa-card__title"><h3><?= $done === count($steps) ? 'All set up' : 'Finish setting up' ?></h3><p><?= $done ?> of <?= count($steps) ?> done — each step makes Flowwork work harder for you.</p></div>
            </header>
            <div class="fwa-card__body" style="padding-bottom:6px"><div class="fwa-progress"><span class="fwa-meter"><i style="--p:<?= $pct ?>%"></i></span><b class="fwa-num"><?= $pct ?>%</b></div></div>
            <div class="fwa-steps">
                <?php foreach ($steps as [$title, $hint, $href, $ok]): ?>
                    <a class="fwa-step<?= $ok ? ' is-done' : '' ?>" href="<?= $e($href) ?>">
                        <span class="fwa-step__dot"><?php if ($ok): ?><svg class="i"><use href="#i-check"/></svg><?php endif; ?></span>
                        <span class="fwa-step__text"><b><?= $e($title) ?></b><small><?= $e($hint) ?></small></span>
                        <svg class="i i--sm"><use href="#i-chev-right"/></svg>
                    </a>
                <?php endforeach; ?>
            </div>
            <div style="height:10px"></div>
        </section>
    </div>
    <div>
        <section class="fwa-card" id="card-attention">
            <header class="fwa-card__head">
                <span class="fwa-card__icon"><svg class="i"><use href="#i-bell"/></svg></span>
                <div class="fwa-card__title"><h3>Needs attention</h3><p><?= $attention ? count($attention) . ' thing' . (count($attention) === 1 ? '' : 's') . ' to look at' : 'Nothing right now' ?></p></div>
            </header>
            <?php if (!$attention): ?>
                <div class="fwa-card__body"><div class="fwa-banner fwa-banner--ok" style="margin:0"><svg class="i"><use href="#i-check"/></svg><div class="fwa-banner__body"><b>All in order.</b> Seats, invitations, people and your subscription look fine.</div></div></div>
            <?php else: ?>
                <div class="fwa-actions">
                    <?php foreach ($attention as [$tone, $title, $hint, $href, $cta]): ?>
                        <div class="fwa-action">
                            <span class="fwa-dot" data-tone="<?= $tone ?>"></span>
                            <span class="fwa-action__text"><b><?= $e($title) ?></b><small><?= $e($hint) ?></small></span>
                            <a class="fwa-mini" href="<?= $e($href) ?>"><?= $e($cta) ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="height:8px"></div>
            <?php endif; ?>
        </section>

        <section class="fwa-card" id="card-activity">
            <header class="fwa-card__head">
                <span class="fwa-card__icon"><svg class="i"><use href="#i-history"/></svg></span>
                <div class="fwa-card__title"><h3>Recent changes</h3><p>Sign-ins are left out — see the full audit trail.</p></div>
            </header>
            <?php if (!$activity): ?>
                <div class="fwa-card__body fwa-muted">Nothing has changed yet.</div>
            <?php else: ?>
                <ul class="fwa-feed">
                    <?php foreach ($activity as $a): [$label, $tone] = AdminKit::actionLabel((string)$a['action']); $who = trim((string)$a['who']) ?: 'Flowwork'; ?>
                        <li>
                            <span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:<?= AdminKit::hue($who) ?>"><?= $e(AdminKit::initials($who)) ?></span>
                            <span class="fwa-feed__body"><b><?= $e($who) ?></b> <span><?= $e(mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1)) ?></span>
                                <small><?= $e(AdminKit::ago($a['at'])) ?><?php $d = AdminKit::detailText($a['details']); if ($d !== ''): ?> · <?= $e(mb_strimwidth($d, 0, 90, '…')) ?><?php endif; ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="fwa-feed__more"><a class="btn btn--sm btn--ghost" href="/admin/audit.php">Open the audit trail <svg class="i i--sm"><use href="#i-chev-right"/></svg></a></div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="fwa-section"><h2>Settings</h2><span class="fwa-section__rule"></span><small>Press <kbd class="fwm-kbd">/</kbd> to find any setting</small></div>
<div class="fwa-areas">
    <?php $i = 0; foreach (AdminKit::nav() as $group => $items): if ($group === '') continue; foreach ($items as $it): ?>
        <a class="fwa-area" href="<?= $e($it[5] ?? '/admin/' . $it[0] . '.php') ?>" style="--c:<?= $colors[$group] ?? '#b08d57' ?>; --i:<?= $i++ ?>">
            <span class="fwa-area__icon"><svg class="i"><use href="#<?= $e($it[2]) ?>"/></svg></span>
            <span class="fwa-area__text"><b><?= $e($it[1]) ?></b><small><?= $e($it[3]) ?></small></span>
        </a>
    <?php endforeach; endforeach; ?>
</div>
<?php
$pageScripts = ['invite'];
$pageBoot = ['roles' => AdminKit::ROLES, 'seats' => $seats];
include __DIR__ . '/partials/foot.php';
