<?php
// /admin/invites.php — Invitations: who has been invited, whether they joined,
// and resend / copy link / withdraw. New invitations come from "Add someone"
// (admin/js/invite.js → /admin/api/invites.php).
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/lib/AdminSchema.php';
require_once __DIR__ . '/lib/AdminMail.php';
AdminSchema::ensure($DB);

$st = $DB->prepare("
    SELECT i.id, i.email, i.role, i.expires_at, i.created_at, i.used, i.used_at, i.last_sent_at, i.send_count,
           CONCAT_WS(' ', u.first_name, u.last_name) AS sent_by, CONCAT_WS(' ', uu.first_name, uu.last_name) AS joined_as
      FROM invites i
      LEFT JOIN users u ON u.id = i.created_by
      LEFT JOIN users uu ON uu.id = i.used_by
     WHERE i.company_id = ?
  ORDER BY i.used ASC, i.created_at DESC
     LIMIT 500");
$st->execute([$ADMIN_COMPANY_ID]);
$invites = [];
$counts = ['open' => 0, 'expired' => 0, 'joined' => 0];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $state = (int)$r['used'] ? 'joined' : (strtotime($r['expires_at']) < time() ? 'expired' : 'open');
    $counts[$state]++;
    $invites[] = [
        'id' => (int)$r['id'], 'email' => $r['email'], 'role' => $r['role'], 'state' => $state, 'expires_at' => $r['expires_at'],
        'created_at' => $r['created_at'], 'used_at' => $r['used_at'], 'sent_by' => trim((string)$r['sent_by']), 'joined_as' => trim((string)$r['joined_as']),
        'emailed' => (int)$r['send_count'] > 0, 'last_sent_at' => $r['last_sent_at'],
    ];
}
$mailbox = AdminMail::mailbox($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID);
$systemMail = AdminMail::systemReady();

$page = 'invites';
$pageTitle = 'Invitations';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">People <i>◆</i> Invitations</p>
        <h1>Invitations</h1>
        <p>Invite colleagues by email. They choose their own password when they accept, and only then use a seat.</p>
    </div>
    <div class="fwa-head__actions">
        <button class="btn btn--primary" type="button" data-add-person><svg class="i"><use href="#i-send"/></svg>Invite someone</button>
    </div>
</header>

<?php if ($mailbox): ?>
    <div class="fwa-banner"><svg class="i"><use href="#i-mail"/></svg><div class="fwa-banner__body">Invitations are emailed from <b>your mailbox, <?= $e($mailbox['email_address']) ?></b>, so they come from someone the invitee knows.</div></div>
<?php elseif ($systemMail): ?>
    <div class="fwa-banner"><svg class="i"><use href="#i-mail"/></svg><div class="fwa-banner__body">Invitations are emailed by Flowwork. <a href="/mail/settings.php">Connect your own mailbox</a> to send them from your address instead.</div></div>
<?php else: ?>
    <div class="fwa-banner fwa-banner--warn"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b>Invitations can’t be emailed yet.</b> <a href="/mail/settings.php">Connect your mailbox in Mail</a> and Flowwork sends them for you — until then you get a link to copy and send yourself.</div></div>
<?php endif; ?>

<section class="fwa-card">
    <div class="fwa-tools">
        <div class="fwa-chips" id="inviteChips">
            <button class="fwa-chip is-on" type="button" data-filter="open">Waiting <small><?= $counts['open'] ?></small></button>
            <button class="fwa-chip" type="button" data-filter="expired">Expired <small><?= $counts['expired'] ?></small></button>
            <button class="fwa-chip" type="button" data-filter="joined">Joined <small><?= $counts['joined'] ?></small></button>
            <button class="fwa-chip" type="button" data-filter="all">All <small><?= count($invites) ?></small></button>
        </div>
    </div>
    <div class="fwa-table-wrap">
        <table class="fwa-table">
            <thead><tr><th>Invited</th><th>Role</th><th>Status</th><th class="hide-narrow">Sent</th><th class="t-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="inviteBody"></tbody>
        </table>
        <div class="fwa-table__empty" id="inviteEmpty" hidden><b>Nothing here</b><span id="inviteEmptyText">No invitations are waiting.</span></div>
    </div>
</section>
<?php
$pageScripts = ['invite', 'invites'];
$pageBoot = ['invites' => $invites, 'roles' => AdminKit::ROLES, 'seats' => AdminKit::seats($DB, $ADMIN_COMPANY_ID)];
include __DIR__ . '/partials/foot.php';
