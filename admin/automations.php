<?php
// /admin/automations.php — Automations: everything Flowwork does on its own
// (reminders, recurring invoices, mail sync, housekeeping), whether the
// background runner is alive, and where each one is set. The runner is
// notifications/api/tick.php; its last run is kept in storage/cron/tick.json.
// Only health is shown here, never counts (the runner serves every company).
require_once __DIR__ . '/lib/AdminBoot.php';

$state = json_decode((string)@file_get_contents(__DIR__ . '/../storage/cron/tick.json'), true);
$last = is_array($state) ? ($state['last'] ?? null) : null;
$lastAt = $last && !empty($last['at']) ? strtotime((string)$last['at']) : 0;
$age = $lastAt ? time() - $lastAt : null;
$jobs = is_array($last['jobs'] ?? null) ? $last['jobs'] : [];
$health = $age === null ? 'unknown' : ($age < 600 ? 'ok' : ($age < 3 * 3600 ? 'slow' : 'down'));
$jobState = function (array $keys) use ($jobs, $health): string {
    if ($health === 'unknown') return 'unknown';
    foreach ($keys as $k) if (($jobs[$k] ?? null) === 'error') return 'error';
    return 'ok';
};
$cal = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'calendar');
$sup = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'suppliers');
$data = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'data');
$reminderLabel = AdminSettings::area('calendar')['fields'][3]['options'][$cal['calendar_default_reminder']] ?? $cal['calendar_default_reminder'] . ' minutes before';
$retention = AdminSettings::area('data')['fields'][0]['options'][$data['audit_retention_days']] ?? 'Forever';

$recurring = 0;
try {
    $st = $DB->prepare("SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND active = 1");
    $st->execute([$ADMIN_COMPANY_ID]);
    $recurring = (int)$st->fetchColumn();
} catch (Throwable $ex) {
    $recurring = null;
}

$automations = [
    ['i-bell', 'Event reminders', 'Calendar', 'Before each event, to everyone invited — by phone notification and in Flowwork. New events start with “' . mb_strtolower($reminderLabel) . '”.', ['reminders'], '/admin/calendar.php#set-calendar_default_reminder', 'Change the default'],
    ['i-check-square', 'Due-date reminders', 'Projects', 'Every morning from 07:00, people hear about board items assigned to them that are due today or overdue.', ['project_reminders'], '/projects/', 'Open Projects'],
    ['i-users', 'Assignment notices', 'Projects', 'Whenever someone is assigned an item or added to a project, they are told at once.', [], '/projects/', 'Open Projects'],
    ['iq-recurring', 'Recurring invoices', 'Quotes & Invoices', ($recurring === null ? 'Invoices on a schedule are created and sent by themselves.' : ($recurring ? $recurring . ' recurring invoice' . ($recurring === 1 ? ' is' : 's are') . ' active; each is created (and sent, if you chose that) on its day.' : 'None set up yet. Turn any invoice into a recurring one and it is created on its day.')), ['qi_recurring'], '/qi/recurring.php', 'Recurring invoices'],
    ['i-flag', 'Follow-up reminders', 'CRM', 'CRM tasks remind the person they are assigned to when they fall due.', ['crm_tasks'], '/crm/#tasks', 'Open CRM tasks'],
    ['i-shield', 'Compliance document reminders', 'Suppliers', 'Owners are reminded ' . str_replace(',', ', ', $sup['crm_reminder_days']) . ' days before a supplier’s document expires' . ($sup['crm_block_expired_suppliers'] === '1' ? '; expired suppliers are blocked.' : '.'), ['crm_compliance'], '/admin/suppliers.php#sup-compliance', 'Change'],
    ['i-mail', 'Mail sync & scheduled sending', 'Mail', 'New mail is fetched every minute, scheduled mail goes out on time, and you are nudged about mail waiting too long for a reply.', ['mail', 'reply_reminders'], '/mail/settings.php', 'Mail settings'],
    ['ia-database', 'Housekeeping', 'Data', 'Old sign-in records are cleared, and the audit trail is kept for: ' . mb_strtolower($retention) . '.', ['cleanup'], '/admin/data.php#data-retention', 'Change'],
];

$page = 'automations';
$pageTitle = 'Automations';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Workspace <i>◆</i> Automations</p>
        <h1>Automations</h1>
        <p>What Flowwork does for you without being asked, and where each one is set.</p>
    </div>
</header>

<?php if ($health === 'ok'): ?>
    <div class="fwa-banner fwa-banner--ok"><svg class="i"><use href="#i-check"/></svg><div class="fwa-banner__body"><b>Running smoothly.</b> Background jobs last ran <?= $age < 90 ? 'a moment ago' : $e(AdminKit::ago(date('Y-m-d H:i:s', $lastAt))) ?>.</div></div>
<?php elseif ($health === 'slow'): ?>
    <div class="fwa-banner fwa-banner--warn"><svg class="i"><use href="#i-clock"/></svg><div class="fwa-banner__body"><b>Running behind.</b> Background jobs last ran <?= $e(AdminKit::ago(date('Y-m-d H:i:s', $lastAt))) ?>, so reminders may arrive late. It usually catches up by itself.</div></div>
<?php elseif ($health === 'down'): ?>
    <div class="fwa-banner fwa-banner--danger"><svg class="i"><use href="#i-alert"/></svg><div class="fwa-banner__body"><b>Background jobs have stopped.</b> They last ran <?= $e(AdminKit::ago(date('Y-m-d H:i:s', $lastAt))) ?>. Reminders and recurring invoices wait until they run again — please let Flowwork support know.</div></div>
<?php else: ?>
    <div class="fwa-banner"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">The background runner has not reported in on this server yet, so its health cannot be shown.</div></div>
<?php endif; ?>

<div class="fwa-grid">
    <?php foreach ($automations as $i => [$icon, $title, $module, $text, $keys, $href, $cta]): $s = $keys ? $jobState($keys) : 'ok'; ?>
        <section class="fwa-card" style="animation:fwm-rise .4s var(--ease) both;animation-delay:<?= $i * 35 ?>ms">
            <header class="fwa-card__head"><span class="fwa-card__icon"><svg class="i"><use href="#<?= $icon ?>"/></svg></span>
                <div class="fwa-card__title"><h3><?= $e($title) ?></h3><p><?= $e($module) ?></p></div>
                <span class="fwm-status <?= $s === 'error' ? 'fwm-status--err' : ($s === 'ok' && $health === 'ok' ? 'fwm-status--ok' : 'fwm-status--off') ?>"><?= $s === 'error' ? 'Last run failed' : ($health === 'ok' || !$keys ? 'On' : 'Waiting') ?></span></header>
            <div class="fwa-card__body"><p style="margin:0;color:var(--ink-2);line-height:1.6"><?= $e($text) ?></p></div>
            <footer class="fwa-card__foot"><a class="fwa-mini" href="<?= $e($href) ?>"><?= $e($cta) ?> <svg class="i"><use href="#i-chev-right"/></svg></a></footer>
        </section>
    <?php endforeach; ?>
</div>

<div class="fwa-banner" style="margin-top:16px"><svg class="i"><use href="#i-info"/></svg><div class="fwa-banner__body">Payment reminders to customers are sent by you, from the overdue invoice (<b>Send a reminder</b>), so nobody gets chased by surprise.</div></div>
<?php include __DIR__ . '/partials/foot.php'; ?>
