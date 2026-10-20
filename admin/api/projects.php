<?php
// /admin/api/projects.php — who can open which project and board. The
// Projects engine (ProjectRepo / BoardRepo) does the work, as an admin, so
// its rules apply: a project keeps an owner, people added hear about it,
// and every change lands in the project's activity.
//   GET  ?id=PROJECT                                   → {project, members, people, boards}
//   POST {action:'member_set', id, user_id, role}       role: owner | manager | member | viewer
//   POST {action:'member_remove', id, user_id}
//   POST {action:'visibility', id, visibility}          members | company
//   POST {action:'board_grant', board_id, user_id, role}   one board only: editor | viewer
//   POST {action:'board_revoke', board_id, user_id}
//   POST {action:'board_archive', board_id, archived}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

ProjectSchema::ensure($DB);
$PR = new ProjectRepo($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'admin');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ProjectForbidden || $e instanceof ProjectUserError) admin_error($e->getMessage(), str_contains($e->getMessage(), 'not found') ? 404 : 400);
    if ($e instanceof AdminInvalid) admin_error($e->getMessage(), 422, ['errors' => $e->errors]);
    error_log('[admin/api/projects] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    admin_error('Something went wrong. Please try again.', 500);
});

function proj_detail(ProjectRepo $PR, PDO $db, int $companyId, int $id): array
{
    $p = $PR->requireProject($id, 1);
    $boards = [];
    $st = $db->prepare("SELECT b.board_id, b.title, b.board_type, b.archived,
                               (SELECT COUNT(*) FROM board_items i WHERE i.board_id = b.board_id AND COALESCE(i.archived, 0) = 0) AS items
                          FROM project_boards b WHERE b.project_id = ? AND b.company_id = ? ORDER BY b.archived, b.sort_order, b.board_id");
    $st->execute([$id, $companyId]);
    $people = $PR->people();
    $grants = [];
    $grantsOk = true;
    try {
        $g = $db->prepare("SELECT bm.board_id, bm.user_id, bm.role FROM board_members bm JOIN project_boards b ON b.board_id = bm.board_id
                            WHERE b.project_id = ? AND b.company_id = ?");
        $g->execute([$id, $companyId]);
        foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            $grants[(int)$r['board_id']][] = ['id' => $uid, 'name' => $people[$uid]['name'] ?? 'Former member', 'email' => $people[$uid]['email'] ?? '', 'role' => (string)$r['role'], 'active' => isset($people[$uid])];
        }
    } catch (Throwable $e) {
        $grantsOk = false; // no board_members table: project access only
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $boards[] = ['id' => (int)$b['board_id'], 'title' => (string)$b['title'], 'type' => (string)$b['board_type'], 'archived' => (bool)$b['archived'],
                     'items' => (int)$b['items'], 'grants' => $grants[(int)$b['board_id']] ?? [], 'url' => ProjectLink::boardUrl((int)$b['board_id'])];
    }
    return [
        'project' => ['id' => $id, 'name' => (string)$p['name'], 'visibility' => (string)($p['visibility'] ?? 'members'), 'status' => (string)$p['status'],
                      'color' => (string)($p['color'] ?? ''), 'url' => ProjectLink::projectUrl($id)],
        'members' => $PR->members($id),
        'people' => array_values($people),
        'boards' => $boards,
        'board_grants' => $grantsOk,
    ];
}

/** The board's project, after checking it belongs to this company. */
function proj_board(PDO $db, int $companyId, int $boardId): array
{
    $st = $db->prepare("SELECT board_id, project_id, title FROM project_boards WHERE board_id = ? AND company_id = ?");
    $st->execute([$boardId, $companyId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) admin_error('Board not found.', 404);
    return $b;
}

if (!admin_is_post()) {
    admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, (int)($_GET['id'] ?? 0)));
}

$in = admin_input();
$action = admin_str($in, 'action', 20);
admin_rate_limit('admin-projects', 300, 600);
$id = admin_int($in, 'id');
$uid = admin_int($in, 'user_id');

switch ($action) {
    case 'member_set':
        $role = admin_str($in, 'role', 20);
        $p = $PR->requireProject($id, 1);
        $PR->setMember($id, $uid, $role);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'project_member_set', ['project' => $p['name'], 'person' => $PR->personName($uid), 'role' => $role], 'project', $id);
        admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, $id));

    case 'member_remove':
        $p = $PR->requireProject($id, 1);
        $PR->removeMember($id, $uid);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'project_member_removed', ['project' => $p['name'], 'person' => $PR->personName($uid)], 'project', $id);
        admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, $id));

    case 'visibility':
        $vis = admin_str($in, 'visibility', 10) === 'company' ? 'company' : 'members';
        $p = $PR->requireProject($id, 1);
        $PR->updateProject($id, ['visibility' => $vis]);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'project_visibility', ['project' => $p['name'], 'visible to' => $vis === 'company' ? 'everyone in the company' : 'its members'], 'project', $id);
        admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, $id));

    case 'board_grant':
    case 'board_revoke':
        $boardId = admin_int($in, 'board_id');
        $b = proj_board($DB, $ADMIN_COMPANY_ID, $boardId);
        if ($action === 'board_grant') {
            $role = admin_str($in, 'role', 10);
            if (!in_array($role, ['editor', 'viewer'], true)) admin_error('Choose editor or viewer.');
            if (!isset($PR->people()[$uid])) admin_error('Choose someone from your company.');
            $DB->prepare("INSERT INTO board_members (board_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)")->execute([$boardId, $uid, $role]);
            AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'board_member_added', ['board' => $b['title'], 'person' => $PR->personName($uid), 'role' => $role], 'board', $boardId);
        } else {
            $DB->prepare("DELETE FROM board_members WHERE board_id = ? AND user_id = ?")->execute([$boardId, $uid]);
            AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, 'board_member_removed', ['board' => $b['title'], 'person' => $PR->personName($uid)], 'board', $boardId);
        }
        $PR->forgetBoard($boardId);
        admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, (int)$b['project_id']));

    case 'board_archive':
        $boardId = admin_int($in, 'board_id');
        $b = proj_board($DB, $ADMIN_COMPANY_ID, $boardId);
        $archived = !empty($in['archived']);
        (new BoardRepo($PR))->archiveBoard($boardId, $archived);
        AdminKit::audit($DB, $ADMIN_COMPANY_ID, $ADMIN_USER_ID, $archived ? 'board_archived' : 'board_restored', ['board' => $b['title']], 'board', $boardId);
        admin_json(proj_detail($PR, $DB, $ADMIN_COMPANY_ID, (int)$b['project_id']));
}
admin_error('Unknown action');
