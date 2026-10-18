<?php
// /projects/board.php?board_id=3[&item=9[&open=updates|files|activity]][&view=table|kanban|calendar|gantt|workload|chart][&view_id=4]
// A board: table, kanban, calendar, timeline, workload and chart views over the
// same items, with the item panel, filters, saved views, automations and guests.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/boot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');
$isAdmin = $role === 'admin';
$boardId = (int)($_GET['board_id'] ?? 0);
if ($boardId <= 0) { header('Location: /projects/'); exit; }

ProjectSchema::ensure($DB);
$PR = new ProjectRepo($DB, $companyId, $userId, $role);
try {
    $b = $PR->requireBoard($boardId, 1);
} catch (ProjectUserError $e) {
    pr_error_page('Board not found', 'This board does not exist, was deleted, or you are not on its project. Ask the project’s manager to add you.');
}
$companyName = pr_company_name($DB, $companyId);
$projectId = (int)$b['project_id'];

$boot = [];
try {
    $boards = new BoardRepo($PR);
    $data = $boards->load($boardId);
    $stats = $PR->projectStats([$projectId]);
    $project = $PR->projectRow($projectId);
    $boot = pr_boot_base($DB, $PR) + $data + [
        'project' => $PR->projectSummary($project, $stats[$projectId] ?? []) + ['level' => $PR->projectLevel($projectId)],
        'boards' => $PR->boardsOf($projectId),
        // Older notification links say open=comments; the panel calls them updates.
        'open' => ['item' => (int)($_GET['item'] ?? 0), 'tab' => ($_GET['open'] ?? '') === 'comments' ? 'updates' : (string)($_GET['open'] ?? ''), 'view' => (string)($_GET['view'] ?? ''), 'view_id' => (int)($_GET['view_id'] ?? 0)],
        'automation' => ['triggers' => Automations::TRIGGERS, 'actions' => Automations::ACTIONS],
        'types' => Columns::TYPES,
    ];
    $PR->touch('board', $boardId);
} catch (Throwable $e) {
    error_log('[projects/board] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$pageTitle = $b['title'];
$withSearch = true;
$searchPlaceholder = 'Search this board';
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwp-app" id="prBoard">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwp-side" id="side" aria-label="Project navigation"></aside>

        <section class="fwm-pane fwp-main" id="mainPane" aria-label="Board">
            <div class="fwp-head" id="head"></div>
            <div class="fwp-tools" id="tools"></div>
            <div class="fwp-progress" id="progress"></div>
            <div class="fwp-view scroll" id="view" tabindex="-1"></div>
        </section>

        <section class="fwm-pane fwp-panel" id="panel" aria-label="Item" hidden></section>
    </div>
</div>

<button class="fwm-fab fwp-fab" id="fabNew" type="button"><svg class="i"><use href="#i-plus"/></svg><span>New item</span></button>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<input type="file" id="fileInput" multiple hidden>
<input type="file" id="importInput" accept=".csv,text/csv" hidden>
<?= pr_shortcuts_modal([
    'Board' => [['New item', 'n'], ['Search this board', '/'], ['Table · kanban · calendar', ['1', '2', '3']], ['Timeline · workload · chart', ['4', '5', '6']], ['Filter', 'f'], ['Show shortcuts', '?']],
    'Table' => [['Move between cells', ['←', '↑', '→', '↓']], ['Edit the cell', 'Enter'], ['Open the item', ['Shift', 'Enter']], ['Select the row', 'x'], ['Cancel', 'Esc']],
    'Item' => [['Next / previous item', ['j', 'k']], ['Close the item', 'Esc'], ['Post an update', ['Ctrl', 'Enter']]],
]) ?>
<script id="prBoot" type="application/json"><?= pr_boot_json($boot) ?></script>
<script src="/projects/js/projects-core.js?v=<?= $prV('projects/js/projects-core.js') ?>"></script>
<script src="/projects/js/board-cells.js?v=<?= $prV('projects/js/board-cells.js') ?>"></script>
<script src="/projects/js/board.js?v=<?= $prV('projects/js/board.js') ?>"></script>
<script src="/projects/js/board-panel.js?v=<?= $prV('projects/js/board-panel.js') ?>"></script>
<script src="/projects/js/board-views.js?v=<?= $prV('projects/js/board-views.js') ?>"></script>
<script src="/projects/js/board-dialogs.js?v=<?= $prV('projects/js/board-dialogs.js') ?>"></script>
<script>window.FWB && window.FWB.start();</script>
</body>
</html>
