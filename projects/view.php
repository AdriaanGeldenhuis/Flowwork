<?php
// /projects/view.php?project_id=5[&tab=overview|schedule|money|files|team|activity|settings]
// A project: overview (progress, timeline, budget, team, boards, due soon,
// events, activity), its schedule, money, files, team, activity and settings.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/boot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? 'viewer');
$isAdmin = $role === 'admin';
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { header('Location: /projects/'); exit; }

ProjectSchema::ensure($DB);
$PR = new ProjectRepo($DB, $companyId, $userId, $role);
try {
    $p = $PR->requireProject($projectId, 0);
} catch (ProjectUserError $e) {
    pr_error_page('Project not found', 'This project does not exist, was deleted, or you are not on it. Ask its manager to add you.');
}
$level = $PR->projectLevel($projectId);
$tab = in_array($_GET['tab'] ?? '', ['overview', 'schedule', 'money', 'files', 'team', 'activity', 'settings'], true) ? $_GET['tab'] : 'overview';
$companyName = pr_company_name($DB, $companyId);

$boot = [];
try {
    $stats = $PR->projectStats([$projectId]);
    $st = $DB->prepare("SELECT starred, last_opened_at FROM project_user_state WHERE user_id = ? AND kind = 'project' AND ref_id = ?");
    $st->execute([$userId, $projectId]);
    $state = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
    $st->execute([(int)$p['client_id'], $companyId]);
    $p['client_name'] = $st->fetchColumn() ?: null;
    $events = [];
    try {
        require_once __DIR__ . '/../calendar/lib/autoload.php';
        $events = CalendarLink::upcomingFor($DB, $companyId, $userId, $role, 'project', $projectId, 8);
    } catch (Throwable $e) { /* calendar unavailable */ }
    $boot = pr_boot_base($DB, $PR) + [
        'tab' => $tab,
        'project' => $PR->projectSummary($p + $state, $stats[$projectId] ?? []) + ['level' => $level],
        'boards' => $PR->boardsOf($projectId, $level >= 3),
        'breakdown' => $PR->boardBreakdown($projectId),
        'due' => $PR->dueSoon($projectId, 12),
        'members' => $PR->members($projectId),
        'events' => $events,
        'activity' => Activity::feed($PR, ['project_id' => $projectId], 20),
    ];
    $PR->touch('project', $projectId);
} catch (Throwable $e) {
    error_log('[projects/view] ' . $e->getMessage());
}

$pageTitle = $p['name'];
$withSearch = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwp-app" id="prProject">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwp-side" id="side" aria-label="Project navigation"></aside>

        <section class="fwm-pane fwp-main" id="mainPane" aria-label="Project">
            <div class="fwp-head" id="head"></div>
            <div class="fwp-progress" id="progress"></div>
            <div class="fwp-view scroll" id="view" tabindex="-1"></div>
        </section>
    </div>
</div>

<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<?= pr_shortcuts_modal([
    'Project' => [['Overview · schedule · money', ['1', '2', '3']], ['Files · team · activity', ['4', '5', '6']], ['New board', 'b'], ['Search', '/'], ['Show shortcuts', '?']],
]) ?>
<script id="prBoot" type="application/json"><?= pr_boot_json($boot) ?></script>
<script src="/projects/js/projects-core.js?v=<?= $prV('projects/js/projects-core.js') ?>"></script>
<script src="/projects/js/project.js?v=<?= $prV('projects/js/project.js') ?>"></script>
</body>
</html>
