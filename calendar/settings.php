<?php
// /calendar/settings.php — personal calendar settings: views and hours,
// calendars (create, share, subscribe, export), dates from other modules,
// import. Company defaults and which modules may show dates are set by an
// admin on /admin/calendar.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');
$isAdmin = fw_active_user_is_admin();

$companyName = 'Company';
$boot = ['settings' => null, 'calendars' => [], 'sources' => [], 'me' => ['id' => $userId], 'role' => $role, 'admin' => $isAdmin];
try {
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';
    CalendarSchema::ensure($DB);
    $cal = new CalendarRepo($DB, $companyId, $userId, $role);
    $cal->defaultCalendarId();
    $cal->allCalendars(true);
    require_once __DIR__ . '/api/_payload.php';
    $boot['settings'] = CalendarSettings::load($DB, $companyId, $userId);
    $boot['calendars'] = cal_calendars_payload($cal);
    $available = (new CalendarSources($DB, $cal))->available();
    foreach (CalendarSources::META as $name => $m) $boot['sources'][] = ['id' => $name, 'available' => $available[$name]] + $m;
} catch (Throwable $e) {
    error_log('[calendar/settings] ' . $e->getMessage());
}

$pageTitle = 'Calendar settings';
$withSearch = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-page" id="settingsApp" data-admin="<?= $isAdmin ? '1' : '0' ?>">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-page__body">
        <aside class="fwm-page__nav fwm-side" id="side" aria-label="Settings sections">
            <a class="fwm-page__back" href="/calendar/"><svg class="i"><use href="#i-arrow-left"/></svg>Back to Calendar</a>
            <nav class="fwm-nav" id="settingsNav">
                <a class="fwm-nav__item" href="#general" data-tab="general"><svg class="i"><use href="#i-settings"/></svg><span class="fwm-nav__label">General</span></a>
                <a class="fwm-nav__item" href="#calendars" data-tab="calendars"><svg class="i"><use href="#i-calendar"/></svg><span class="fwm-nav__label">Calendars</span></a>
                <a class="fwm-nav__item" href="#sources" data-tab="sources"><svg class="i"><use href="#i-grid"/></svg><span class="fwm-nav__label">From Flowwork</span></a>
                <a class="fwm-nav__item" href="#import" data-tab="import"><svg class="i"><use href="#i-upload"/></svg><span class="fwm-nav__label">Import &amp; export</span></a>
                <?php if ($isAdmin): ?>
                <a class="fwm-nav__item" href="/admin/calendar.php"><svg class="i"><use href="#i-shield"/></svg><span class="fwm-nav__label">Company defaults</span><svg class="i i--xs"><use href="#i-external"/></svg></a>
                <?php endif; ?>
            </nav>
        </aside>
        <main class="fwm-pane fwm-page__main">
            <div class="fwm-page__scroll scroll">
                <div class="fwm-page__content">
                    <section class="fwm-panel" data-panel="general">
                        <div class="fwm-page__head"><div><h1>General</h1><p>How your calendar looks and what new events start with. Changes are saved as you make them.</p></div></div>
                        <div id="generalForm"></div>
                    </section>
                    <section class="fwm-panel" data-panel="calendars">
                        <div class="fwm-page__head"><div><h1>Calendars</h1><p>Your own calendars, the company's shared ones, and calendars colleagues shared with you.</p></div>
                            <button class="btn btn--primary" id="addCalendar" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>New calendar</button></div>
                        <div id="calendarsList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="sources">
                        <div class="fwm-page__head"><div><h1>From Flowwork</h1><p>Dates from the rest of Flowwork appear on your calendar as they are right now — move a due date there and the calendar follows.</p></div></div>
                        <div id="sourcesList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="import">
                        <div class="fwm-page__head"><div><h1>Import &amp; export</h1><p>Bring events in from Google Calendar, Outlook or Apple Calendar, or take them with you.</p></div></div>
                        <div id="importPanel"></div>
                    </section>
                </div>
            </div>
        </main>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<input type="file" id="importFile" accept=".ics,text/calendar" hidden>

<script id="calBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/calendar/js/calendar-core.js?v=<?= CAL_ASSET_VERSION ?>"></script>
<script src="/calendar/js/calendar-settings.js?v=<?= CAL_ASSET_VERSION ?>"></script>
</body>
</html>
