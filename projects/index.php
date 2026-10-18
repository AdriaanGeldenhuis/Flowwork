<?php
// /projects/index.php — Projects home: the portfolio (cards · table · timeline),
// My work across every board, starred and recent boards, and search.
// Routes (hash): #portfolio[?status=…] · #mywork · #archive · #search?q=…
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/boot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');
$isAdmin = $role === 'admin';
$companyName = pr_company_name($DB, $companyId);

$boot = [];
try {
    ProjectSchema::ensure($DB);
    $PR = new ProjectRepo($DB, $companyId, $userId, $role);
    $boot = pr_boot_base($DB, $PR) + [
        'projects' => $PR->listProjects(['status' => 'all']),
        'side' => $PR->sidebarBoards(),
        'summary' => ProjectLink::myWorkSummary($DB, $companyId, $userId, $role),
    ];
} catch (Throwable $e) {
    error_log('[projects/index] ' . $e->getMessage());
}

$pageTitle = 'Projects';
$withSearch = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwp-app" id="prHome">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwp-side" id="side" aria-label="Projects navigation">
            <button class="fwm-compose-btn" id="newProjectBtn" type="button">
                <svg class="i"><use href="#i-plus"/></svg><span>New project</span>
            </button>
            <div class="fwm-side__scroll scroll">
                <nav class="fwm-nav" id="navMain" aria-label="Views"></nav>
                <div class="fwm-nav__head" id="starHead" hidden><h3>Starred boards</h3></div>
                <nav class="fwm-nav fwp-nav-board" id="navStar" aria-label="Starred boards"></nav>
                <div class="fwm-nav__head" id="recentHead" hidden><h3>Recent</h3></div>
                <nav class="fwm-nav fwp-nav-board" id="navRecent" aria-label="Recently opened boards"></nav>
                <div class="fwm-nav__head"><h3>Projects</h3>
                    <button class="icon-btn icon-btn--sm" id="newProjectSide" type="button" aria-label="New project" data-tip="New project"><svg class="i i--sm"><use href="#i-plus"/></svg></button>
                </div>
                <nav class="fwm-nav fwp-nav-proj" id="navProjects" aria-label="Open projects"></nav>
            </div>
            <div class="fwm-side__foot" id="sideFoot"></div>
        </aside>

        <section class="fwm-pane fwp-main" id="mainPane" aria-label="Projects">
            <div class="fwp-head" id="head"></div>
            <div class="fwp-tools" id="tools"></div>
            <div class="fwp-progress" id="progress"></div>
            <div class="fwp-view scroll" id="view" tabindex="-1"></div>
        </section>
    </div>
</div>

<button class="fwm-fab fwp-fab" id="fabNew" type="button"><svg class="i"><use href="#i-plus"/></svg>New project</button>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<?= pr_shortcuts_modal([
    'Navigate' => [['Portfolio', ['g', 'p']], ['My work', ['g', 'w']], ['Search', '/'], ['Show shortcuts', '?']],
    'Projects' => [['New project', 'c'], ['Cards · table · timeline', ['1', '2', '3']], ['Close / cancel', 'Esc']],
]) ?>
<script id="prBoot" type="application/json"><?= pr_boot_json($boot) ?></script>
<script src="/projects/js/projects-core.js?v=<?= $prV('projects/js/projects-core.js') ?>"></script>
<script src="/projects/js/projects-home.js?v=<?= $prV('projects/js/projects-home.js') ?>"></script>
</body>
</html>
