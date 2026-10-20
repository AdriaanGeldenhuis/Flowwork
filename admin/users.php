<?php
// /admin/users.php — People & roles: everyone who can open this company,
// their role, seat, status and last sign-in. Add someone (invitation or an
// account straight away), change roles, suspend, sign out everywhere or
// remove. Actions go to /admin/api/people.php and /admin/api/invites.php.
require_once __DIR__ . '/lib/AdminBoot.php';

$st = $DB->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.is_seat, u.last_login_at, u.created_at,
           u.company_id AS primary_company_id, uc.role,
           (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id = u.id AND pm.company_id = uc.company_id) AS projects
      FROM user_companies uc
      JOIN users u ON u.id = uc.user_id
     WHERE uc.company_id = ?
  ORDER BY FIELD(uc.role, 'admin', 'member', 'bookkeeper', 'viewer', 'pos'), u.first_name, u.last_name");
$st->execute([$ADMIN_COMPANY_ID]);
$people = $st->fetchAll(PDO::FETCH_ASSOC);

// Signed-in devices per person (best effort: the table appears on first sign-in)
$devices = [];
try {
    $st = $DB->prepare("SELECT s.user_id, COUNT(*) FROM user_sessions s JOIN user_companies uc ON uc.user_id = s.user_id AND uc.company_id = ?
                         WHERE s.revoked_at IS NULL AND s.expires_at > NOW() GROUP BY s.user_id");
    $st->execute([$ADMIN_COMPANY_ID]);
    $devices = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
} catch (Throwable $ex) {
}

$seats = AdminKit::seats($DB, $ADMIN_COMPANY_ID);
$roleCounts = array_fill_keys(array_keys(AdminKit::ROLES), 0);
$suspended = 0;
foreach ($people as $p) {
    if (isset($roleCounts[$p['role']])) $roleCounts[$p['role']]++;
    if ($p['status'] !== 'active') $suspended++;
}
$st = $DB->prepare("SELECT COUNT(*) FROM invites WHERE company_id = ? AND used = 0 AND expires_at > NOW()");
$st->execute([$ADMIN_COMPANY_ID]);
$pendingInvites = (int)$st->fetchColumn();

$rows = array_map(fn($p) => [
    'id' => (int)$p['id'], 'first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'email' => $p['email'], 'role' => $p['role'],
    'status' => $p['status'], 'is_seat' => (int)$p['is_seat'], 'primary' => (int)$p['primary_company_id'] === $ADMIN_COMPANY_ID,
    'projects' => (int)$p['projects'], 'devices' => $devices[(int)$p['id']] ?? 0, 'last_login' => $p['last_login_at'], 'created' => $p['created_at'],
], $people);

$page = 'users';
$pageTitle = 'People & roles';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">People <i>◆</i> <?= count($people) ?> in <?= $e($companyName) ?></p>
        <h1>People &amp; <em>roles</em></h1>
        <p>Everyone who can open <?= $e($companyName) ?>, and what they may do. Bookkeepers don’t use a paid seat.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/admin/invites.php"><svg class="i"><use href="#i-send"/></svg>Invitations<?= $pendingInvites ? ' · ' . $pendingInvites : '' ?></a>
        <button class="btn btn--primary" type="button" data-add-person><svg class="i"><use href="#ia-user-plus"/></svg>Add someone</button>
    </div>
</header>

<div class="fwa-stats">
    <div class="fwa-stat" style="--i:0">
        <span class="fwa-stat__label">Paid seats</span>
        <span class="fwa-stat__value"><?= (int)$seats['used'] ?><small>/ <?= (int)$seats['max'] ?></small></span>
        <span class="fwa-meter<?= $seats['used'] >= $seats['max'] ? ' is-full' : '' ?>"><i style="--p:<?= min(100, round($seats['used'] / $seats['max'] * 100)) ?>%"></i></span>
        <span class="fwa-stat__sub"><?= $seats['free'] ? $seats['free'] . ' free' : 'All in use — <a href="/admin/billing.php">more seats</a>' ?></span>
    </div>
    <div class="fwa-stat" style="--i:1">
        <span class="fwa-stat__label">Admins</span>
        <span class="fwa-stat__value"><?= (int)$roleCounts['admin'] ?></span>
        <span class="fwa-stat__sub"><?= $roleCounts['admin'] === 1 ? 'Consider a second admin as a backup' : 'Can change everything here' ?></span>
    </div>
    <a class="fwa-stat" href="/admin/invites.php" style="--i:2">
        <span class="fwa-stat__label">Invitations waiting</span>
        <span class="fwa-stat__value"><?= $pendingInvites ?></span>
        <span class="fwa-stat__sub"><?= $pendingInvites ? 'Not accepted yet' : 'Everyone has joined' ?></span>
    </a>
    <div class="fwa-stat<?= $suspended ? ' is-alert' : '' ?>" style="--i:3">
        <span class="fwa-stat__label">Suspended</span>
        <span class="fwa-stat__value"><?= $suspended ?></span>
        <span class="fwa-stat__sub"><?= $suspended ? 'Cannot sign in' : 'Nobody is locked out' ?></span>
    </div>
</div>

<section class="fwa-card" style="margin-top:18px">
    <div class="fwa-tools">
        <label class="fwa-search"><svg class="i"><use href="#i-search"/></svg><span class="sr-only">Search people</span><input type="search" id="peopleSearch" placeholder="Search by name or email" autocomplete="off"></label>
        <div class="fwa-chips" id="roleChips" role="tablist" aria-label="Filter by role">
            <button class="fwa-chip is-on" type="button" data-filter="all">Everyone <small><?= count($people) ?></small></button>
            <?php foreach (AdminKit::ROLES as $rk => [$rl]): if (!$roleCounts[$rk]) continue; ?>
                <button class="fwa-chip" type="button" data-filter="<?= $rk ?>"><?= $e($rl) ?>s <small><?= $roleCounts[$rk] ?></small></button>
            <?php endforeach; ?>
            <?php if ($suspended): ?><button class="fwa-chip" type="button" data-filter="suspended">Suspended <small><?= $suspended ?></small></button><?php endif; ?>
        </div>
    </div>
    <div class="fwa-table-wrap">
        <table class="fwa-table" id="peopleTable">
            <thead><tr><th>Person</th><th>Role</th><th class="hide-narrow">Status</th><th class="hide-narrow">Seat</th><th class="hide-narrow t-num">Projects</th><th class="hide-narrow">Last sign-in</th><th class="t-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="peopleBody"></tbody>
        </table>
        <div class="fwa-table__empty" id="peopleEmpty" hidden><b>Nobody matches</b>Try another name, or clear the filter.</div>
    </div>
    <div class="fwa-table-foot"><svg class="i i--sm"><use href="#i-info"/></svg>Removing someone takes them off this company’s projects and boards; their work and history stay. Suspending stops them signing in anywhere.</div>
</section>
<?php
$pageScripts = ['invite', 'people'];
$pageBoot = ['people' => $rows, 'roles' => AdminKit::ROLES, 'seats' => $seats, 'company' => $companyName];
include __DIR__ . '/partials/foot.php';
