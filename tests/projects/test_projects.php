<?php
/**
 * Projects engine (projects/lib) against the test database: schema and
 * backfill, access levels, projects, boards from templates, groups, columns,
 * every column type's validation, formulas, the items' own fields (what
 * Calendar, Home and My work read), people index, move / reorder / copy /
 * archive / delete, move to another board, subitems, updates with @mentions
 * and notifications, watchers, dependencies (no loops), automations, saved
 * views, guests, money, search, My work and the link API for other modules.
 *
 *   php tests/projects/test_projects.php
 *
 * Needs the test database (tests/db/setup.sh) with its company-1 users
 * (1 admin, 3 member, 4 admin). Everything it creates is removed at the end.
 */

require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/includes/notify.php';
require FLOWWORK_ROOT . '/projects/lib/autoload.php';

const C = TEST_COMPANY_ID;
const ADMIN = 1;
const MEMBER = 3;
const ADMIN2 = 4;

ProjectSchema::ensure($DB);
fw_notify_install($DB);

$maxId = fn(string $t, string $col = 'id') => (int)$DB->query("SELECT COALESCE(MAX(`$col`), 0) FROM `$t`")->fetchColumn();
$mark = ['projects' => $maxId('projects', 'project_id'), 'boards' => $maxId('project_boards', 'board_id'), 'notifications' => $maxId('notifications'),
         'audit' => $maxId('board_audit_log'), 'cost' => $maxId('cost_items'), 'guests' => $maxId('board_guests')];

register_shutdown_function(function () use ($DB, $mark) {
    $repo = new ProjectRepo($DB, C, ADMIN, 'admin');
    $st = $DB->query("SELECT project_id FROM projects WHERE project_id > {$mark['projects']} AND company_id = " . C);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        try { $repo->deleteProject((int)$pid); } catch (Throwable $e) { echo "cleanup: ", $e->getMessage(), "\n"; }
    }
    $DB->exec("DELETE FROM notifications WHERE id > {$mark['notifications']}");
    $DB->exec("DELETE FROM board_audit_log WHERE id > {$mark['audit']}");
    $DB->exec("DELETE FROM board_guests WHERE id > {$mark['guests']}");
    $DB->exec("DELETE FROM project_user_state WHERE company_id = " . C . " AND ((kind = 'project' AND ref_id > {$mark['projects']}) OR (kind = 'board' AND ref_id > {$mark['boards']}))");
});

function repo(int $user, string $role = 'member'): ProjectRepo
{
    global $DB;
    return new ProjectRepo($DB, C, $user, $role);
}
function throws(callable $fn, string $msg, string $class = ProjectUserError::class): void
{
    try { $fn(); } catch (Throwable $e) {
        assert_true($e instanceof $class, "$msg (got " . get_class($e) . ': ' . $e->getMessage() . ')');
        return;
    }
    fail("expected an error: $msg");
}
function col(array $board, string $name): array
{
    foreach ($board['columns'] as $c) if ($c['name'] === $name) return $c;
    fail("no column $name");
}
function item(array $items, int $id): array
{
    foreach ($items as $i) if ($i['id'] === $id) return $i;
    fail("no item $id");
}
function val(array $item, int $colId)
{
    $v = (array)$item['values'];
    return $v[$colId] ?? null;
}

$admin = repo(ADMIN, 'admin');
$member = repo(MEMBER, 'member');

// ------------------------------------------------------------------ formula engine
$r = fn($ref) => ['qty' => 3.0, 'rate' => '12.5', 'total days' => 4.0, 'status' => 'Done'][Formula::nameKey($ref)] ?? null;
assert_eq(37.5, Formula::evaluate('{Qty} * {Rate}', $r), 'brace refs');
assert_eq(37.5, Formula::evaluate('[Qty]*[Rate]', $r), 'bracket refs');
assert_eq(50.0, Formula::evaluate('{TotAL DAYS}  *  {Rate}', $r), 'loose name match');
assert_eq(13.0, Formula::evaluate('{Qty} - -10', $r), 'double minus');
assert_eq(1.0, Formula::evaluate('IF({Status} = "done", 1, 0)', $r), 'text compare');
assert_eq(null, Formula::evaluate('1/0', $r), 'division by zero is empty');
assert_eq(null, Formula::evaluate('system("ls")', $r), 'unknown function');
assert_eq(5.0, Formula::evaluate('WORKDAYS(' . Formula::dayNumber('2026-10-19') . ',' . Formula::dayNumber('2026-10-25') . ')', $r), 'workdays Mon–Sun');
assert_eq('3.33', Formula::format(Formula::evaluate('ROUND(10/3, 2)', $r), 2), 'round + format');

// ------------------------------------------------------------------ column values
$status = ['column_id' => 1, 'type' => 'status', 'config' => null];
assert_eq('done', Columns::encodeValue($status, 'Done'), 'status by label');
throws(fn() => Columns::encodeValue($status, 'Nope'), 'unknown status label');
$legacy = ['column_id' => 1, 'type' => 'status', 'config' => '{"labels":{"Working on it":"#fdab3d","Done":"#00c875"}}'];
assert_eq('Working on it', Columns::encodeValue($legacy, 'working on it'), 'legacy label map');
assert_true(Columns::statusOf(Columns::config($legacy), 'Done')['done'], 'legacy Done counts as done');
assert_eq('urgent', Columns::encodeValue(['type' => 'priority', 'config' => null], 'critical'), 'critical → urgent');
assert_eq('urgent', Columns::decode(['type' => 'priority'], 'critical'), 'decode critical');
assert_eq('3,7', Columns::encodeValue(['type' => 'people', 'config' => null], [3, 7, 3]), 'people list');
assert_eq([3], Columns::decode(['type' => 'people'], '3'), 'single legacy person');
assert_eq(['start' => '2026-01-02', 'end' => '2026-01-05'], Columns::decode(['type' => 'timeline'], Columns::encodeValue(['type' => 'timeline', 'config' => null], ['start' => '2026-01-05', 'end' => '2026-01-02'])), 'timeline swaps');
assert_eq(['a', 'b'], Columns::decode(['type' => 'tags'], 'a, b'), 'legacy comma tags');
throws(fn() => Columns::encodeValue(['type' => 'link', 'config' => null], 'javascript:alert(1)'), 'javascript: links refused');
assert_eq('https://example.com', Columns::encodeValue(['type' => 'link', 'config' => null], 'example.com'), 'scheme added');
throws(fn() => Columns::encodeValue(['type' => 'email', 'config' => null], 'not-an-email'), 'bad email');
assert_eq('1250.5', Columns::encodeValue(['type' => 'number', 'config' => null], 'R 1,250.50'), 'currency number');
throws(fn() => Columns::encodeValue(['type' => 'formula', 'config' => null], 1), 'formula not settable');
$num = Columns::config(['type' => 'number', 'config' => '{"format":"percentage","affix":"m","affixPosition":"suffix"}']);
assert_eq(['percent', 'm', 'suffix'], [$num['format'], $num['unit'], $num['unit_pos']], 'legacy number config');
$dd = Columns::config(['type' => 'dropdown', 'config' => '{"options":["Paid","Not Paid"],"optionColors":{"Paid":"#00aa00"}}']);
assert_eq('#00aa00', $dd['options'][0]['color'], 'legacy option colours');

// ------------------------------------------------------------------ projects + access
$pid = $member->createProject(['name' => 'PrTest Riverside', 'template' => 'construction_standard', 'budget' => '250000', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31']);
$p = $member->requireProject($pid);
assert_eq(4, $member->projectLevel($pid), 'creator is owner');
assert_true(preg_match('/^PRJ-\d{4}$/', $p['project_number']) === 1, 'project number assigned');
assert_eq(0, repo(ADMIN2, 'member')->projectLevel($pid), 'non-member cannot see a members-only project');
assert_eq(5, $admin->projectLevel($pid), 'admin sees everything');
throws(fn() => repo(ADMIN2, 'member')->requireProject($pid), 'hidden project is "not found"');
$member->updateProject($pid, ['visibility' => 'company']);
assert_eq(1, repo(ADMIN2, 'member')->projectLevel($pid), 'company visibility gives viewer');
throws(fn() => repo(ADMIN2, 'member')->updateProject($pid, ['name' => 'x']), 'viewer cannot edit', ProjectForbidden::class);
assert_eq(1, repo(MEMBER, 'viewer')->projectLevel($pid), 'company viewer role is capped at viewer');
$member->setMember($pid, ADMIN2, 'member');
assert_eq(2, repo(ADMIN2, 'member')->projectLevel($pid), 'added member can edit');
throws(fn() => $member->updateProject($pid, ['end_date' => '2026-09-01']), 'end before start');
throws(fn() => $member->removeMember($pid, MEMBER), 'cannot remove the last owner');

$boards = $member->boardsOf($pid);
assert_eq(['Programme', 'Costing', 'Procurement', 'Snags & quality'], array_map(fn($b) => $b['title'], $boards), 'construction template boards');

// ------------------------------------------------------------------ board, columns, items
$br = new BoardRepo($member);
$ir = new ItemRepo($member);
$bid = $br->createBoard($pid, ['template' => 'tasks', 'title' => 'PrTest Tasks']);
$board = $br->load($bid);
assert_eq(['This week', 'Next week', 'Later'], array_map(fn($g) => $g['name'], $board['groups']), 'tasks groups');
$cOwner = col($board, 'Owner'); $cStatus = col($board, 'Status'); $cDue = col($board, 'Due date'); $cPrio = col($board, 'Priority');
$g1 = $board['groups'][0]['id']; $g2 = $board['groups'][1]['id'];

$cQty = $br->createColumn($bid, ['type' => 'number', 'name' => 'Qty']);
$cRate = $br->createColumn($bid, ['type' => 'number', 'name' => 'Rate', 'config' => ['format' => 'currency', 'precision' => 2, 'unit' => 'R']]);
$cTotal = $br->createColumn($bid, ['type' => 'formula', 'name' => 'Total', 'config' => ['formula' => '{qty} * {RATE}', 'precision' => 2]]);
$cTl = $br->createColumn($bid, ['type' => 'timeline', 'name' => 'Timeline']);
$cDays = $br->createColumn($bid, ['type' => 'formula', 'name' => 'Days', 'config' => ['formula' => 'WORKDAYS({Timeline.start}, {Timeline.end})', 'precision' => 0]]);
$cProg = $br->createColumn($bid, ['type' => 'progress', 'name' => 'Progress', 'config' => ['mode' => 'status']]);
$dupe = $br->createColumn($bid, ['type' => 'text', 'name' => 'qty']);
assert_eq('qty 2', $dupe['name'], 'column names stay unique');

$it = $ir->create($bid, $g1, 'Pour slab', [$cOwner['id'] => [MEMBER, ADMIN2], $cQty['id'] => 4, $cRate['id'] => '125.50', $cDue['id'] => '2026-10-20']);
$iid = $it['id'];
assert_eq(502.0, val($it, $cTotal['id']), 'formula computed on create');
$row = $DB->query("SELECT * FROM board_items WHERE id = $iid")->fetch();
assert_eq(MEMBER, (int)$row['assigned_to'], 'assigned_to mirrors first person');
assert_eq('2026-10-20', $row['due_date'], 'due_date mirrors due column');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM board_item_people WHERE item_id = $iid")->fetchColumn(), 'people index has both');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > {$mark['notifications']} AND user_id = " . ADMIN2 . " AND type = 'project_assigned'")->fetchColumn(), 'assignee notified');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > {$mark['notifications']} AND user_id = " . MEMBER . " AND type = 'project_assigned'")->fetchColumn(), 'no self-notification');

$res = $ir->setValue($iid, $cStatus['id'], 'Done');
$it = $res['items'][0];
assert_eq(100.0, val($it, $cProg['id']), 'progress from status');
$row = $DB->query("SELECT * FROM board_items WHERE id = $iid")->fetch();
assert_eq(['Done', 100], [$row['status_label'], (int)$row['progress']], 'status mirrored, done = 100%');
$ir->setValue($iid, $cTl['id'], ['start' => '2026-10-19', 'end' => '2026-10-30']);
$it = $ir->setValue($iid, $cQty['id'], '-2')['items'][0];
assert_eq(-251.0, val($it, $cTotal['id']), 'negative numbers in formulas');
assert_eq(10.0, val($it, $cDays['id']), 'timeline parts in formulas');
$ir->setValue($iid, $cStatus['id'], null);
assert_eq(null, $DB->query("SELECT status_label FROM board_items WHERE id = $iid")->fetchColumn(), 'cleared status mirrors null');
throws(fn() => $ir->setValue($iid, $cTotal['id'], 5), 'formula cells are read-only');
throws(fn() => repo(ADMIN2, 'viewer')->requireItem($iid, 2), 'viewer cannot edit items', ProjectForbidden::class);

// rename a column used in a formula: the formula follows
$br->updateColumn($cQty['id'], ['name' => 'Quantity']);
$total = $DB->query("SELECT config FROM board_columns WHERE column_id = {$cTotal['id']}")->fetchColumn();
assert_true(str_contains($total, '{Quantity}'), 'formula follows a renamed column');
assert_eq('-251.00', $DB->query("SELECT value FROM board_item_values WHERE item_id = $iid AND column_id = {$cTotal['id']}")->fetchColumn(), 'formula still right after rename');

// status labels renamed: values follow
$br->updateColumn($cStatus['id'], ['config' => ['labels' => [['key' => 'todo', 'label' => 'Not started', 'color' => '#888888'], ['key' => 'done', 'label' => 'Finished', 'color' => '#2f8a5f', 'done' => true]]]]);
$ir->setValue($iid, $cStatus['id'], 'Finished');
assert_eq('done', $DB->query("SELECT value FROM board_item_values WHERE item_id = $iid AND column_id = {$cStatus['id']}")->fetchColumn(), 'status stored by key');

// ------------------------------------------------------------------ move, reorder, duplicate, archive, delete
$i2 = $ir->create($bid, $g1, 'Order steel', [], 'top')['id'];
$i3 = $ir->create($bid, $g1, 'Site meeting')['id'];
assert_eq(0, (int)$DB->query("SELECT position FROM board_items WHERE id = $i2")->fetchColumn(), 'top insert');
$ir->move($bid, [$i2], $g2);
assert_eq($g2, (int)$DB->query("SELECT group_id FROM board_items WHERE id = $i2")->fetchColumn(), 'moved to group');
$ir->reorder($bid, $g1, [$i3, $iid]);
assert_eq([0, 1], [(int)$DB->query("SELECT position FROM board_items WHERE id = $i3")->fetchColumn(), (int)$DB->query("SELECT position FROM board_items WHERE id = $iid")->fetchColumn()], 'reorder');
$copy = $ir->duplicate($iid);
assert_eq('Pour slab (copy)', $copy['title'], 'duplicate title');
assert_eq(val($ir->payload($bid, [$iid])[0], $cTotal['id']), val($copy, $cTotal['id']), 'duplicate keeps values');
$ir->archive($bid, [$copy['id']], true);
assert_eq([], $ir->payload($bid, [$copy['id']]), 'archived items leave the board');
$ir->archive($bid, [$copy['id']], false);
assert_eq(1, count($ir->payload($bid, [$copy['id']])), 'restore');
$ir->delete($bid, [$copy['id']]);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM board_items WHERE id = {$copy['id']}")->fetchColumn(), 'deleted');
throws(fn() => $ir->move($bid, [$i2], 999999999), 'move to a foreign group refused');

// group delete archives its items
$gx = $br->createGroup($bid, 'Temp', null, 'bottom');
$ix = $ir->create($bid, $gx['id'], 'Temp item')['id'];
$br->deleteGroup($gx['id']);
assert_eq(1, (int)$DB->query("SELECT archived FROM board_items WHERE id = $ix")->fetchColumn(), 'group delete archives items');

// move to another board: values by column name and type
$bid2 = $br->createBoard($pid, ['template' => 'tasks', 'title' => 'PrTest Other']);
$b2 = $br->load($bid2);
$ir->setValue($i3, $cStatus['id'], 'Finished');
$ir->setValue($i3, $cOwner['id'], [ADMIN]);
$ir->moveToBoard($bid, [$i3], $bid2, null);
$m = $ir->payload($bid2, [$i3])[0];
assert_eq([ADMIN], val($m, col($b2, 'Owner')['id']), 'owner kept when moving boards');
assert_eq($bid2, (int)$DB->query("SELECT board_id FROM board_item_people WHERE item_id = $i3")->fetchColumn(), 'people index follows');

// ------------------------------------------------------------------ subitems, updates, watchers, deps
$s = $ir->addSubitem($iid, 'Check rebar', ADMIN2, '2026-10-18');
$ir->addSubitem($iid, 'Book pump');
$ir->updateSubitem($s['id'], ['done' => true]);
$pl = $ir->payload($bid, [$iid])[0];
assert_eq([2, 1], [$pl['subitems'], $pl['subitems_done']], 'subitem counts');
$br->updateColumn($cProg['id'], ['config' => ['mode' => 'subitems']]);
assert_eq(50.0, val($ir->payload($bid, [$iid])[0], $cProg['id']), 'progress from subitems');

$u = $ir->addUpdate($iid, 'Concrete booked for Tuesday, @[Admin Two](' . ADMIN2 . ') please confirm');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > {$mark['notifications']} AND user_id = " . ADMIN2 . " AND type = 'mention'")->fetchColumn(), 'mention notified');
$ir2 = new ItemRepo(repo(ADMIN2, 'member'));
$ir2->addUpdate($iid, 'Confirmed', $u['id']);
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > {$mark['notifications']} AND user_id = " . MEMBER . " AND type = 'project_update'")->fetchColumn(), 'reply notifies the thread');
$ups = $ir->updates($iid);
assert_eq(1, count($ups[0]['replies']), 'reply nested');
throws(fn() => $ir->editUpdate($ups[0]['replies'][0]['id'], 'hijack'), 'cannot edit another person’s update', ProjectForbidden::class);

$ir->addDependency($i2, $iid);
throws(fn() => $ir->addDependency($iid, $i2), 'dependency loops refused');
assert_eq('waiting_on', $ir->detail($iid)['deps'][0]['kind'], 'dependency shown on the item');

// ------------------------------------------------------------------ automations
$doneGroup = $br->createGroup($bid, 'Completed', null, 'bottom')['id'];
$doneKey = 'done';
Automations::save($member, $bid, ['name' => 'Done → Completed', 'trigger' => 'status_changed', 'trigger_config' => ['column_id' => $cStatus['id'], 'to' => $doneKey],
                                  'action' => 'move_to_group', 'action_config' => ['group_id' => $doneGroup]]);
Automations::save($member, $bid, ['name' => 'New → assign creator', 'trigger' => 'item_created', 'trigger_config' => [],
                                  'action' => 'assign', 'action_config' => ['column_id' => $cOwner['id'], 'user_id' => 'creator']]);
$a = $ir->create($bid, $g1, 'Automated')['id'];
assert_eq([MEMBER], val($ir->payload($bid, [$a])[0], $cOwner['id']), 'item_created → assign creator');
$res = $ir->setValue($a, $cStatus['id'], 'Finished');
assert_true($res['automated'], 'automation reported');
assert_eq($doneGroup, (int)$DB->query("SELECT group_id FROM board_items WHERE id = $a")->fetchColumn(), 'status → move to group');
throws(fn() => Automations::save(repo(ADMIN2, 'member'), $bid, ['trigger' => 'item_created', 'action' => 'archive']), 'only managers add automations', ProjectForbidden::class);

// ------------------------------------------------------------------ views, prefs, stars
$v = $br->saveView($bid, ['name' => 'Mine', 'view' => 'kanban', 'config' => ['person' => MEMBER], 'shared' => true]);
assert_eq('kanban', $v['view'], 'saved view');
assert_eq(1, count((new BoardRepo(repo(ADMIN2, 'member')))->savedViews($bid)), 'shared view visible to others');
throws(fn() => (new BoardRepo(repo(ADMIN2, 'member')))->deleteView($bid, $v['id']), 'cannot delete someone else’s view', ProjectForbidden::class);
$member->star('board', $bid, true);
$member->touch('board', $bid2);
$side = $member->sidebarBoards();
assert_eq($bid, $side['starred'][0]['id'], 'starred board');

// ------------------------------------------------------------------ my work, link API, search
$mine = ProjectLink::myWork(repo(ADMIN2, 'member'));
assert_true(in_array($iid, array_column($mine, 'id'), true) === false, 'done items are not in My work');
$ir->setValue($iid, $cStatus['id'], 'Not started');
$mine = ProjectLink::myWork(repo(ADMIN2, 'member'));
assert_true(in_array($iid, array_column($mine, 'id'), true), 'second assignee sees the item in My work');
$sum = ProjectLink::myWorkSummary($DB, C, ADMIN2, 'member');
assert_true($sum['open'] >= 1, 'home summary');
assert_eq('Pour slab · PrTest Tasks', ProjectLink::describe($DB, C, 'item', $iid)['label'], 'describe item');
assert_true(ProjectLink::belongs($DB, C, $pid) && !ProjectLink::belongs($DB, 999999, $pid), 'belongs');
assert_true(in_array($pid, array_column(ProjectLink::pickerProjects($DB, C, ADMIN2, 'member'), 'id'), true), 'picker');
$found = ProjectLink::search($member, 'Pour slab');
assert_eq($iid, $found['items'][0]['id'] ?? 0, 'search finds the item');

// ------------------------------------------------------------------ money
ProjectLink::saveCost($member, $pid, ['type' => 'material', 'description' => 'Cement 50 bags', 'qty' => 50, 'unit_cost' => '98.50']);
$money = ProjectLink::financials($member, $pid);
assert_eq(4925.0, $money['manual_cost'], 'manual cost');
assert_eq(round(4925 * 100 / 250000, 1), $money['budget_used'], 'budget used');
throws(fn() => ProjectLink::saveCost($member, $pid, ['description' => 'x', 'qty' => 0]), 'bad cost');

// ------------------------------------------------------------------ guests
$_SESSION = $_SESSION ?? [];
$inv = Guests::invite($member, $bid, 'Guest.Person@example.com', 7);
assert_eq('pending', $inv['guest']['status'], 'guest pending');
assert_true(!isset($inv['guest']['token']) && !isset($inv['guest']['password_hash']), 'no secrets in guest rows');
throws(fn() => Guests::invite($member, $bid, 'not an email'), 'bad guest email');
$token = substr($inv['link'], strpos($inv['link'], 'token=') + 6);
$g = Guests::byToken($DB, $token);
throws(fn() => Guests::confirm($DB, $g, 'short', 'short'), 'short guest password');
Guests::confirm($DB, $g, 'a-long-enough-pass', 'a-long-enough-pass');
assert_true(Guests::current($DB, $token) !== null, 'guest signed in');
$gp = Guests::boardPayload($DB, Guests::current($DB, $token));
assert_true(count($gp['items']) >= 2 && !isset($gp['members']) && !isset($gp['views']), 'guest payload is read-only data');
throws(fn() => Guests::login($DB, 'guest.person@example.com', 'wrong-password-x'), 'wrong guest password');
Guests::signOut();
assert_true(Guests::current($DB, $token) === null, 'guest signed out');

// ------------------------------------------------------------------ activity, copy, delete
$feed = Activity::feed($member, ['item_id' => $iid], 100);
assert_true(in_array('value_changed', array_column($feed, 'action'), true), 'activity recorded');
$copyBoard = $br->copyBoard($bid, $pid, true);
assert_true(count($br->load($copyBoard)['items']) >= 3, 'board copied with items');
$dupProject = $member->duplicateProject($pid, false);
assert_eq(count($member->boardsOf($pid)), count($member->boardsOf($dupProject)), 'project copy keeps boards');
$files = $br->deleteBoard($copyBoard);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM project_boards WHERE board_id = $copyBoard")->fetchColumn(), 'board deleted');
throws(fn() => repo(ADMIN2, 'member')->deleteProject($pid), 'members cannot delete a project', ProjectForbidden::class);

echo "OK — " . $GLOBALS['__assert_count'] . " assertions\n";
