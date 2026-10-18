<?php
// /projects/api/projects.php
//   GET  [?status=open|active|on_hold|completed|draft|cancelled|all&q=&mine=1&archived=1]   portfolio
//   GET  ?id=5                                       one project: boards, members, stats, events, activity
//   POST {action:'create', name, description, client_id|client, site_address, start_date, end_date, budget,
//         manager_id, color, visibility, template, members[{user_id, role}]}
//   POST {action:'update', id, …same fields, status}
//   POST {action:'delete', id}                       owners and admins
//   POST {action:'duplicate', id}
//   POST {action:'star', id, on}
//   POST {action:'member_set', id, user_id, role}   POST {action:'member_remove', id, user_id}
//   POST {action:'boards_reorder', id, ids[]}
require_once __DIR__ . '/_bootstrap.php';

if (!pr_is_post()) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $p = $PR->requireProject($id, 0);
        $level = $PR->projectLevel($id);
        $stats = $PR->projectStats([$id]);
        $st = $DB->prepare("SELECT starred, last_opened_at FROM project_user_state WHERE user_id = ? AND kind = 'project' AND ref_id = ?");
        $st->execute([$PR_USER_ID, $id]);
        $state = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([(int)$p['client_id'], $PR_COMPANY_ID]);
        $p['client_name'] = $st->fetchColumn() ?: null;
        $events = [];
        try {
            require_once __DIR__ . '/../../calendar/lib/autoload.php';
            $events = CalendarLink::upcomingFor($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE, 'project', $id, 8);
        } catch (Throwable $e) { /* calendar unavailable */ }
        pr_json([
            'project' => $PR->projectSummary($p + $state, $stats[$id] ?? []) + ['level' => $level],
            'boards' => $PR->boardsOf($id, $level >= 3),
            'members' => $PR->members($id),
            'events' => $events,
            'activity' => Activity::feed($PR, ['project_id' => $id], 25),
        ]);
    }
    pr_json(['projects' => $PR->listProjects([
        'status' => (string)($_GET['status'] ?? 'open'),
        'q' => mb_substr((string)($_GET['q'] ?? ''), 0, 200),
        'mine' => !empty($_GET['mine']),
        'archived' => !empty($_GET['archived']),
    ])]);
}

$in = pr_input();
$action = pr_str($in, 'action', 30);
$id = pr_int($in, 'id');

switch ($action) {
    case 'create':
        pr_rate_limit('pr-create', 60, 600);
        $newId = $PR->createProject($in);
        pr_json(['id' => $newId, 'url' => ProjectLink::projectUrl($newId)]);

    case 'update':
        $PR->updateProject($id, $in);
        $PR->forgetProject($id);
        $p = $PR->requireProject($id, 1);
        $stats = $PR->projectStats([$id]);
        pr_json(['project' => $PR->projectSummary($p, $stats[$id] ?? []) + ['level' => $PR->projectLevel($id)]]);

    case 'delete':
        pr_unlink_files($PR->deleteProject($id));
        pr_json();

    case 'duplicate':
        pr_rate_limit('pr-create', 60, 600);
        $newId = $PR->duplicateProject($id, !empty($in['with_items']));
        pr_json(['id' => $newId, 'url' => ProjectLink::projectUrl($newId)]);

    case 'star':
        $PR->star('project', $id, !empty($in['on']));
        pr_json();

    case 'member_set':
        $PR->setMember($id, pr_int($in, 'user_id'), pr_str($in, 'role', 20));
        pr_json(['members' => $PR->members($id)]);

    case 'member_remove':
        $PR->removeMember($id, pr_int($in, 'user_id'));
        pr_json(['members' => $PR->members($id)]);

    case 'boards_reorder':
        (new BoardRepo($PR))->reorderBoards($id, pr_ids($in['ids'] ?? []));
        pr_json();
}
pr_error('Unknown action');
