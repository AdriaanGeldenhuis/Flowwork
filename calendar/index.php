<?php
// /calendar/index.php — Calendar app (day · week · month · year · schedule).
// Deep links: ?view=week&date=2026-10-21 · ?event=12[&date=…] · ?new=1&title=…&date=…&start=09:00&end=10:00
//             &all_day=1&location=…&description=…&link=crm_account:12 · ?focus=invoice:44&date=…
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');
$isAdmin = fw_active_user_is_admin();

$companyName = 'Company';
$boot = ['settings' => null, 'calendars' => [], 'sources' => [], 'me' => ['id' => $userId, 'name' => ''], 'role' => $role, 'admin' => $isAdmin];
try {
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';
    CalendarSchema::ensure($DB);
    $cal = new CalendarRepo($DB, $companyId, $userId, $role);
    $cal->ensureCompanyCalendar();
    $cal->defaultCalendarId();
    $cal->allCalendars(true);
    require_once __DIR__ . '/api/_payload.php';
    $boot['settings'] = CalendarSettings::load($DB, $companyId, $userId);
    $boot['calendars'] = cal_calendars_payload($cal);
    $available = (new CalendarSources($DB, $cal))->available();
    foreach (CalendarSources::META as $name => $m) $boot['sources'][] = ['id' => $name, 'available' => $available[$name]] + $m;
    $stmt = $DB->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $boot['me'] = ['id' => $userId, 'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')), 'email' => $me['email'] ?? ''];
} catch (Throwable $e) {
    error_log('[calendar/index] ' . $e->getMessage());
}

$pageTitle = 'Calendar';
$withSearch = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="calApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwc-side" id="side" aria-label="Calendars">
            <button class="fwm-compose-btn" id="newEventBtn" type="button">
                <svg class="i"><use href="#i-plus"/></svg><span>New event</span>
            </button>
            <div class="fwm-side__scroll scroll" id="sideNav">
                <div class="fwc-mini" id="miniMonth" aria-label="Choose a date"></div>
                <div class="fwm-nav__head"><h3>My calendars</h3>
                    <button class="icon-btn icon-btn--sm" id="newCalBtn" type="button" aria-label="New calendar" data-tip="New calendar"><svg class="i i--sm"><use href="#i-plus"/></svg></button>
                </div>
                <nav class="fwm-nav" id="navMine" aria-label="My calendars"></nav>
                <div class="fwm-nav__head" id="sharedHead"><h3>Shared</h3></div>
                <nav class="fwm-nav" id="navShared" aria-label="Shared calendars"></nav>
                <div class="fwm-nav__head" id="sourcesHead"><h3>From Flowwork</h3></div>
                <nav class="fwm-nav" id="navSources" aria-label="Dates from other modules"></nav>
            </div>
            <div class="fwm-side__foot" id="sideFoot"></div>
        </aside>

        <section class="fwm-pane fwc-main" id="mainPane" aria-label="Calendar">
            <div class="fwc-head" id="viewHead">
                <div class="fwc-head__title"><span class="fwm-list__eyebrow" id="viewEyebrow"></span><h2 id="viewTitle">Calendar</h2></div>
                <div class="fwc-head__nav">
                    <button class="btn btn--sm" id="todayBtn" type="button">Today</button>
                    <button class="icon-btn" id="prevBtn" type="button" aria-label="Previous" data-tip="Previous (k)"><svg class="i"><use href="#i-chev-left"/></svg></button>
                    <button class="icon-btn" id="nextBtn" type="button" aria-label="Next" data-tip="Next (j)"><svg class="i"><use href="#i-chev-right"/></svg></button>
                </div>
                <div class="seg" id="viewSeg" role="tablist" aria-label="View">
                    <button type="button" data-view="day" role="tab">Day</button>
                    <button type="button" data-view="week" role="tab">Week</button>
                    <button type="button" data-view="month" role="tab">Month</button>
                    <button type="button" data-view="year" role="tab">Year</button>
                    <button type="button" data-view="agenda" role="tab">Schedule</button>
                </div>
                <button class="icon-btn" id="viewMoreBtn" type="button" aria-label="View options" data-tip="Options" data-tip-pos="left"><svg class="i"><use href="#i-more-h"/></svg></button>
            </div>
            <div class="fwc-progress" id="progress"></div>
            <div class="fwc-view scroll" id="view" tabindex="-1"></div>
        </section>

        <section class="fwm-pane fwc-detail" id="detailPane" aria-label="Event" hidden></section>
    </div>
</div>

<button class="fwm-fab fwc-fab" id="fabNew" type="button"><svg class="i"><use href="#i-plus"/></svg>New event</button>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<input type="file" id="importFile" accept=".ics,text/calendar" hidden>
<input type="file" id="attachFile" hidden>

<!-- Keyboard shortcuts -->
<div class="fwm-modal" id="shortcutsModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle">
        <div class="fwm-dialog__head"><h2 id="shortcutsTitle">Keyboard shortcuts</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="fwm-kbd-grid">
                <h4>Navigate</h4>
                <div class="fwm-kbd-row"><span>Today</span><span><kbd class="fwm-kbd">t</kbd></span></div>
                <div class="fwm-kbd-row"><span>Next / previous period</span><span><kbd class="fwm-kbd">j</kbd><kbd class="fwm-kbd">k</kbd></span></div>
                <div class="fwm-kbd-row"><span>Day · week · month · year · schedule</span><span><kbd class="fwm-kbd">d</kbd><kbd class="fwm-kbd">w</kbd><kbd class="fwm-kbd">m</kbd><kbd class="fwm-kbd">y</kbd><kbd class="fwm-kbd">a</kbd></span></div>
                <div class="fwm-kbd-row"><span>Go to a date</span><span><kbd class="fwm-kbd">g</kbd></span></div>
                <div class="fwm-kbd-row"><span>Search</span><span><kbd class="fwm-kbd">/</kbd></span></div>
                <h4>Events</h4>
                <div class="fwm-kbd-row"><span>New event</span><span><kbd class="fwm-kbd">c</kbd></span></div>
                <div class="fwm-kbd-row"><span>Edit the open event</span><span><kbd class="fwm-kbd">e</kbd></span></div>
                <div class="fwm-kbd-row"><span>Delete the open event</span><span><kbd class="fwm-kbd">#</kbd></span></div>
                <div class="fwm-kbd-row"><span>Close / cancel</span><span><kbd class="fwm-kbd">Esc</kbd></span></div>
                <div class="fwm-kbd-row"><span>Save in the editor</span><span><kbd class="fwm-kbd">Ctrl</kbd><kbd class="fwm-kbd">Enter</kbd></span></div>
                <div class="fwm-kbd-row"><span>Show shortcuts</span><span><kbd class="fwm-kbd">?</kbd></span></div>
            </div>
        </div>
    </div>
</div>

<script id="calBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/calendar/js/calendar-core.js?v=<?= CAL_ASSET_VERSION ?>"></script>
<script src="/calendar/js/calendar.js?v=<?= CAL_ASSET_VERSION ?>"></script>
</body>
</html>
