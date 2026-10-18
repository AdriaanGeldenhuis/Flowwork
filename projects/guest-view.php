<?php
// /projects/guest-view.php?token=…[&view=kanban][&item=9] — the board a guest
// was invited to, read-only: the same table, kanban, calendar, timeline and
// chart views and the item panel (details and files), with no updates, people
// list, money or other boards. Needs the guest's session for this invitation.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/boot.php';
require_once __DIR__ . '/partials/guest.php';

ProjectSchema::ensure($DB);
$token = (string)($_GET['token'] ?? '');
$g = Guests::current($DB, $token);
if (!$g) {
    header('Location: /projects/guest-login.php' . (preg_match('/^[a-f0-9]{64}$/', $token) ? '?token=' . rawurlencode($token) : ''));
    exit;
}
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$boot = [];
try {
    $boot = Guests::boardPayload($DB, $g) + [
        'me' => ['id' => 0, 'name' => (string)$g['email'], 'email' => (string)$g['email']],
        'role' => 'guest',
        'admin' => false,
        'can_create' => false,
        'today' => ProjectRepo::today(),
        'templates' => ['boards' => [], 'projects' => []],
        'types' => Columns::TYPES,
        'open' => ['item' => (int)($_GET['item'] ?? 0), 'tab' => in_array($_GET['open'] ?? '', ['details', 'files'], true) ? $_GET['open'] : '', 'view' => (string)($_GET['view'] ?? ''), 'view_id' => 0],
        'guest_token' => $token,
    ];
    $DB->prepare("INSERT INTO board_guest_activity (guest_id, action, ip_address, user_agent, timestamp) VALUES (?, 'view', ?, ?, NOW())")
       ->execute([(int)$g['id'], mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
} catch (Throwable $e) {
    error_log('[projects/guest-view] ' . $e->getMessage());
}
if (empty($boot['board'])) pr_guest_page('This board is not available', (string)$g['company_name'], 'Please try again later.', '', 503);

$pageTitle = $g['board_title'];
$companyName = (string)$g['company_name'];
$bodyExtraClass = 'is-guest';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwp-app" id="prBoard">
    <header class="fwm-top">
        <span class="fwm-brand" aria-label="Projects">
            <span class="fwm-brand__tile fwp-seal" aria-hidden="true"><svg class="i" viewBox="0 0 24 24"><use href="#i-seal-board"/></svg></span>
            <span class="fwm-brand__text"><span class="fwm-brand__name">Projects</span><span class="fwm-brand__company"><?= $h($companyName) ?></span></span>
        </span>
        <form class="fwm-search" id="searchForm" role="search" autocomplete="off">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Search this board</span>
                <input type="search" id="searchInput" placeholder="Search this board" enterkeyhint="search" spellcheck="false">
                <button type="button" class="icon-btn icon-btn--sm" id="searchClear" aria-label="Clear search" hidden><svg class="i i--sm"><use href="#i-x"/></svg></button>
            </label>
        </form>
        <div class="fwm-top__actions">
            <span class="hint hide-narrow" style="margin-right:6px"><?= $h($g['email']) ?></span>
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme"><svg class="i"><use href="#i-moon"/></svg></button>
            <form method="post" action="/projects/guest-logout.php" style="display:contents">
                <input type="hidden" name="csrf_token" value="<?= $h(Csrf::token()) ?>">
                <button class="icon-btn" type="submit" aria-label="Sign out" data-tip="Sign out" data-tip-pos="left"><svg class="i"><use href="#i-arrow-left"/></svg></button>
            </form>
        </div>
    </header>

    <div class="fwm-body">
        <section class="fwm-pane fwp-main" id="mainPane" aria-label="Board">
            <div class="fwp-head" id="head"></div>
            <div class="fwp-tools" id="tools"></div>
            <div class="fwp-progress" id="progress"></div>
            <div class="fwp-view scroll" id="view" tabindex="-1"></div>
        </section>
        <section class="fwm-pane fwp-panel" id="panel" aria-label="Item" hidden></section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<?= pr_shortcuts_modal([
    'Board' => [['Search this board', '/'], ['Table · kanban · calendar', ['1', '2', '3']], ['Timeline · workload · chart', ['4', '5', '6']], ['Filter', 'f'], ['Show shortcuts', '?']],
    'Item' => [['Next / previous item', ['j', 'k']], ['Close the item', 'Esc']],
]) ?>
<script id="prBoot" type="application/json"><?= pr_boot_json($boot) ?></script>
<script src="/projects/js/projects-core.js?v=<?= $prV('projects/js/projects-core.js') ?>"></script>
<script src="/projects/js/board-cells.js?v=<?= $prV('projects/js/board-cells.js') ?>"></script>
<script src="/projects/js/board.js?v=<?= $prV('projects/js/board.js') ?>"></script>
<script src="/projects/js/board-panel.js?v=<?= $prV('projects/js/board-panel.js') ?>"></script>
<script src="/projects/js/board-views.js?v=<?= $prV('projects/js/board-views.js') ?>"></script>
<script src="/projects/js/board-dialogs.js?v=<?= $prV('projects/js/board-dialogs.js') ?>"></script>
<script>
(function () {
  var FWB = window.FWB, FWP = window.FWP;
  if (!FWB) return;
  var token = FWB.BOOT.guest_token;
  FWB.guestFileUrl = function (f) { return '/projects/guest-file.php?token=' + encodeURIComponent(token) + '&id=' + encodeURIComponent(f.id); };
  FWB.start();
  // The board changes while a guest looks at it: offer a refresh.
  var cursor = FWB.BOOT.cursor || 0, told = false;
  function check() {
    if (told || document.visibilityState !== 'visible') return;
    fetch('/projects/guest-changes.php?token=' + encodeURIComponent(token), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        if (d.signed_out) { location.href = '/projects/guest-login.php?token=' + encodeURIComponent(token); return; }
        if (d.cursor > cursor) { told = true; FWP.toast('This board was updated.', { timeout: 0, action: { label: 'Refresh', run: function () { location.reload(); } } }); }
      }).catch(function () {});
  }
  setInterval(check, 30000);
  document.addEventListener('visibilitychange', check);
})();
</script>
</body>
</html>
