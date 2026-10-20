<?php
// /admin/calendar.php — company calendar defaults: time zone, week start,
// first view, default reminder, working hours and which Flowwork dates the
// calendar may show. Read by calendar/lib/CalendarSettings.php and
// CalendarRepo::companyTz; each person can still tune their own view in
// /calendar/settings.php.
require_once __DIR__ . '/lib/AdminBoot.php';

$values = AdminSettings::load($DB, $ADMIN_COMPANY_ID, 'calendar');
$tz = new DateTimeZone($values['calendar_timezone']);
$nowThere = new DateTimeImmutable('now', $tz);

$page = 'calendar';
$pageTitle = 'Calendar';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">Modules <i>◆</i> Calendar</p>
        <h1>Calendar</h1>
        <p>Company-wide defaults for everyone’s calendar. It is <b><?= $e($nowThere->format('H:i')) ?></b> on <?= $e($nowThere->format('l j F')) ?> in <?= $e(str_replace('_', ' ', $values['calendar_timezone'])) ?> right now.</p>
    </div>
    <div class="fwa-head__actions">
        <a class="btn" href="/calendar/settings.php"><svg class="i"><use href="#i-settings"/></svg>My calendar settings</a>
        <a class="btn" href="/calendar/"><svg class="i"><use href="#i-calendar"/></svg>Open Calendar</a>
    </div>
</header>

<form data-settings="calendar" novalidate>
    <?= AdminSettings::render('calendar', $values) ?>
</form>
<?php include __DIR__ . '/partials/foot.php'; ?>
