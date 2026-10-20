<?php
// /admin/boards.php — Projects & access: every project, who is on it and who
// can see it, plus extra access to single boards. Changes go through
// /admin/api/projects.php, which uses the Projects engine's own rules.
require_once __DIR__ . '/lib/AdminBoot.php';
require_once __DIR__ . '/../projects/lib/autoload.php';
ProjectSchema::ensure($DB);

$PR = new ProjectRepo($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'admin');
$people = $PR->people();
$st = $DB->prepare("
    SELECT p.project_id, p.name, p.status, p.archived, p.visibility, p.color, p.client, p.owner_id, p.created_by, p.project_manager_id, p.updated_at,
           (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.project_id AND pm.company_id = p.company_id) AS members,
           (SELECT COUNT(*) FROM project_boards b WHERE b.project_id = p.project_id AND b.company_id = p.company_id AND COALESCE(b.archived, 0) = 0) AS boards
      FROM projects p
     WHERE p.company_id = ?
  ORDER BY COALESCE(p.archived, 0), FIELD(p.status, 'active', 'draft', 'on_hold', 'completed', 'cancelled', 'archived'), p.name");
$st->execute([$ADMIN_COMPANY_ID]);
$projects = [];
$counts = ['open' => 0, 'company' => 0, 'members' => 0, 'closed' => 0];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $closed = (int)$p['archived'] === 1 || in_array($p['status'], ['completed', 'cancelled', 'archived'], true);
    $owner = (int)($p['owner_id'] ?: $p['created_by']);
    $projects[] = [
        'id' => (int)$p['project_id'], 'name' => (string)$p['name'], 'status' => (string)$p['status'], 'status_label' => ProjectRepo::STATUSES[$p['status']] ?? ucfirst((string)$p['status']),
        'closed' => $closed, 'visibility' => (string)($p['visibility'] ?: 'members'), 'color' => (string)($p['color'] ?: '#b08d57'), 'client' => (string)($p['client'] ?? ''),
        'owner' => $people[$owner]['name'] ?? '', 'members' => (int)$p['members'], 'boards' => (int)$p['boards'], 'url' => ProjectLink::projectUrl((int)$p['project_id']),
    ];
    $counts[$closed ? 'closed' : 'open']++;
    if (!$closed) $counts[($p['visibility'] ?? 'members') === 'company' ? 'company' : 'members']++;
}

$page = 'boards';
$pageTitle = 'Projects & access';
include __DIR__ . '/partials/head.php';
?>
<header class="fwa-head">
    <div class="fwa-head__text">
        <p class="fwa-eyebrow">People <i>◆</i> Access</p>
        <h1>Projects &amp; <em>access</em></h1>
        <p>Who can open each project and board. A project is seen by the people on it — or by everyone, if it is shared with the whole company. Admins always see everything.</p>
    </div>
    <div class="fwa-head__actions"><a class="btn" href="/projects/"><svg class="i"><use href="#i-kanban"/></svg>Open Projects</a></div>
</header>

<div class="fwa-stats">
    <div class="fwa-stat" style="--i:0"><span class="fwa-stat__label">Open projects</span><span class="fwa-stat__value"><?= $counts['open'] ?></span><span class="fwa-stat__sub"><?= $counts['closed'] ?> completed or archived</span></div>
    <div class="fwa-stat" style="--i:1"><span class="fwa-stat__label">Members only</span><span class="fwa-stat__value"><?= $counts['members'] ?></span><span class="fwa-stat__sub">seen by the people on them</span></div>
    <div class="fwa-stat" style="--i:2"><span class="fwa-stat__label">Shared with everyone</span><span class="fwa-stat__value"><?= $counts['company'] ?></span><span class="fwa-stat__sub">every colleague can view them</span></div>
</div>

<section class="fwa-card" style="margin-top:18px">
    <div class="fwa-tools">
        <label class="fwa-search"><svg class="i"><use href="#i-search"/></svg><span class="sr-only">Search projects</span><input type="search" id="projSearch" placeholder="Search projects or clients" autocomplete="off"></label>
        <div class="fwa-chips" id="projChips">
            <button class="fwa-chip is-on" type="button" data-filter="open">Open <small><?= $counts['open'] ?></small></button>
            <button class="fwa-chip" type="button" data-filter="closed">Closed <small><?= $counts['closed'] ?></small></button>
            <button class="fwa-chip" type="button" data-filter="all">All <small><?= count($projects) ?></small></button>
        </div>
    </div>
    <div class="fwa-table-wrap">
        <table class="fwa-table">
            <thead><tr><th>Project</th><th>Who can see it</th><th class="hide-narrow">Owner</th><th class="t-num hide-narrow">People</th><th class="t-num hide-narrow">Boards</th><th class="t-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="projBody"></tbody>
        </table>
        <div class="fwa-table__empty" id="projEmpty" hidden><b>No projects here</b>Projects you create in Projects appear here.</div>
    </div>
</section>
<?php
$pageScripts = ['projects'];
$pageBoot = ['projects' => $projects];
include __DIR__ . '/partials/foot.php';
