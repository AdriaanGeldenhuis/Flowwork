<?php
// /admin/mail.php — Mail: every mailbox connected in the company, whether it
// is syncing, and which address Flowwork uses for what (documents, payslips,
// invitations, system mail). Mailboxes are per person and are connected in
// /mail/settings.php; there are no company-wide SMTP settings to keep here.
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/lib/AdminMail.php';

$boxes = [];
try {
    $st = $DB->prepare("SELECT a.account_id, a.email_address, a.display_name, a.is_active, a.last_sync_at, a.last_error, a.smtp_server, a.imap_server,
                               CONCAT_WS(' ', u.first_name, u.last_name) AS owner, a.user_id
                          FROM email_accounts a LEFT JOIN users u ON u.id = a.user_id
                         WHERE a.company_id = ? ORDER BY a.is_active DESC, owner, a.email_address");
    $st->execute([$ADMIN_COMPANY_ID]);
    $boxes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
}
// Payslips use the company's first mailbox that can send (payroll/lib/PayslipEmailer.php)
$payslipBox = null;
$byId = $boxes;
usort($byId, fn($a, $b) => (int)$a['account_id'] <=> (int)$b['account_id']);
foreach ($byId as $b) if (!empty($b['smtp_server'])) { $payslipBox = $b; break; }
$mine = AdminMail::mailbox($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID);
$system = AdminMail::systemReady();
$broken = count(array_filter($boxes, fn($b) => (int)$b['is_active'] && trim((string)$b['last_error']) !== ''));

$page = 'mail';
$pageTitle = 'Mail';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Modules <i>◆</i> Mail</p>
        <h1>Mail</h1>
        <p>Everyone connects their own mailbox, so mail goes out from the right person. Here you see them all, and which address Flowwork uses for what.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/mail/templates.php"><svg class="i"><use href="#i-template"/></svg>Templates</a>
        <a class="btn btn--primary" href="/mail/settings.php"><svg class="i"><use href="#i-plus"/></svg><?= $mine ? 'My mailbox settings' : 'Connect my mailbox' ?></a>
    </div>
</header>

<?php if ($broken): ?>
    <div class="fwa-banner fwa-banner--warn"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b><?= $broken ?> mailbox<?= $broken === 1 ? ' is' : 'es are' ?> not syncing.</b> Usually a changed password — its owner can fix it under Mail › Settings.</div></div>
<?php endif; ?>

<section class="fwa-card" id="card-boxes">
    <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-inbox"/></svg></span>
        <div class="fwa-card__title"><h3>Connected mailboxes · <?= count($boxes) ?></h3><p>Each person’s own address. Passwords are stored encrypted and never shown.</p></div></header>
    <?php if (!$boxes): ?>
        <div class="fwa-card__body"><p class="fwa-muted" style="margin:0">Nobody has connected a mailbox yet. <a href="/mail/settings.php">Connect yours</a> to send quotes, invoices and invitations from your own address.</p></div>
    <?php else: ?>
        <div class="fwa-table-wrap"><table class="fwa-table">
            <thead><tr><th>Mailbox</th><th>Belongs to</th><th>Status</th><th class="hide-narrow">Last checked</th></tr></thead>
            <tbody>
            <?php foreach ($boxes as $b): $err = trim((string)$b['last_error']); ?>
                <tr<?= (int)$b['is_active'] ? '' : ' class="is-muted"' ?>>
                    <td><div class="fwa-who"><span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:<?= AdminKit::hue((string)$b['email_address']) ?>"><?= $e(AdminKit::initials((string)$b['email_address'])) ?></span>
                        <span class="fwa-who__text"><b><?= $e($b['email_address']) ?></b><small><?= $e($b['display_name'] ?: '—') ?></small></span></div></td>
                    <td><?= $e(trim((string)$b['owner']) ?: '—') ?><?= (int)$b['user_id'] === $ADMIN_USER_ID ? '<span class="fwa-you">You</span>' : '' ?></td>
                    <td><?php if (!(int)$b['is_active']): ?><span class="fwa-pill" data-tone="muted">Switched off</span>
                        <?php elseif ($err !== ''): ?><span class="fwa-pill" data-tone="danger" title="<?= $e(mb_strimwidth($err, 0, 300, '…')) ?>">Not syncing</span>
                        <?php elseif (empty($b['smtp_server'])): ?><span class="fwa-pill" data-tone="warning">Can’t send</span>
                        <?php else: ?><span class="fwa-pill" data-tone="success">Working</span><?php endif; ?></td>
                    <td class="hide-narrow"><?= $b['last_sync_at'] ? $e(AdminKit::ago($b['last_sync_at'])) : '<span class="fwa-muted">Never</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="fwa-card" id="card-routes">
    <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#i-send"/></svg></span>
        <div class="fwa-card__title"><h3>Who sends what</h3><p>So you know where replies will land.</p></div></header>
    <div class="fwa-actions">
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#iq-invoice"/></svg></span><span class="fwa-action__text"><b>Quotes, invoices, statements and CRM email</b><small>From the mailbox of the person who sends them.</small></span></div>
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#i-wallet"/></svg></span><span class="fwa-action__text"><b>Payslips</b><small><?= $payslipBox ? 'From ' . $e($payslipBox['email_address']) . ' — the company’s first mailbox that can send.' : 'No mailbox can send yet, so the server’s own mail is used — they may land in spam.' ?></small></span></div>
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#ia-user-plus"/></svg></span><span class="fwa-action__text"><b>Invitations</b><small><?= $mine ? 'From your mailbox (' . $e($mine['email_address']) . ') when you invite someone.' : ($system ? 'From Flowwork’s mail, until you connect your own mailbox.' : 'Not emailed until you connect your mailbox — you get a link to share instead.') ?></small></span></div>
        <div class="fwa-action"><span class="fwa-action__icon"><svg class="i"><use href="#i-lock"/></svg></span><span class="fwa-action__text"><b>Password resets</b><small><?= $system ? 'From Flowwork’s system mail.' : 'Flowwork’s system mail is not set up on this server — ask Flowwork support.' ?></small></span>
            <span class="fwa-pill" data-tone="<?= $system ? 'success' : 'warning' ?>"><?= $system ? 'Ready' : 'Not set up' ?></span></div>
    </div>
    <div style="height:8px"></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
