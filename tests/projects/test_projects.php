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

// ------------------------------------------------------------------ formulas: new columns, loops, labels, progress, TODAY()
$cell = fn(int $item, int $col) => $DB->query("SELECT value FROM board_item_values WHERE item_id = $item AND column_id = $col")->fetchColumn();
$rb = $br->createBoard($pid, ['template' => 'blank', 'title' => 'PrTest Formulas']);
$rg = $br->load($rb)['groups'][0]['id'];
$rS = col($br->load($rb), 'Status'); $rD = col($br->load($rb), 'Date');
$rA = $br->createColumn($rb, ['type' => 'number', 'name' => 'A']);
$r1 = $ir->create($rb, $rg, 'Formula row', [$rA['id'] => 5])['id'];
$rF = $br->createColumn($rb, ['type' => 'formula', 'name' => 'F', 'config' => ['formula' => '{A} * 2', 'precision' => 0]]);
assert_eq('10', $cell($r1, $rF['id']), 'a new formula column fills in on existing items');
throws(fn() => $br->createColumn($rb, ['type' => 'formula', 'name' => 'Me', 'config' => ['formula' => '{Me} + 1']]), 'a formula cannot read itself');
throws(fn() => $br->updateColumn($rF['id'], ['config' => ['formula' => '[#' . $rF['id'] . '] + 1']]), 'nor itself by #id');
$rG = $br->createColumn($rb, ['type' => 'formula', 'name' => 'G', 'config' => ['formula' => '{H} + 1', 'precision' => 0]]);
throws(fn() => $br->createColumn($rb, ['type' => 'formula', 'name' => 'H', 'config' => ['formula' => '{G} + 1']]), 'two formulas cannot read each other');
$setFormula = fn(array $c, string $f) => $DB->prepare("UPDATE board_columns SET config = ? WHERE column_id = ?")->execute([json_encode(['formula' => $f, 'precision' => 0]), $c['id']]);
$setFormula($rF, '{G} + 1'); $setFormula($rG, '{F} + 1'); // a loop saved by an older version
BoardRepo::computeFormulas($DB, C, $rb); BoardRepo::computeFormulas($DB, C, $rb);
assert_eq([false, false], [$cell($r1, $rF['id']), $cell($r1, $rG['id'])], 'a stored loop stays empty instead of growing on every recompute');
$setFormula($rF, '{A} * 2'); $setFormula($rG, '{F} + 1');
BoardRepo::computeFormulas($DB, C, $rb);
assert_eq('11', $cell($r1, $rG['id']), 'formulas reading formulas, in order');
$rL = $br->createColumn($rb, ['type' => 'formula', 'name' => 'Long', 'config' => ['formula' => implode('+', array_fill(0, 249, '{A}'))]]);
throws(fn() => $br->updateColumn($rA['id'], ['name' => 'Amount']), 'a rename that would push a formula past 1000 characters is refused');
assert_eq('A', $DB->query("SELECT name FROM board_columns WHERE column_id = {$rA['id']}")->fetchColumn(), '… and changes nothing');
$br->deleteColumn($rL['id']);
$rSF = $br->createColumn($rb, ['type' => 'formula', 'name' => 'Is done', 'config' => ['formula' => 'IF({Status} = "Done", 1, 0)', 'precision' => 0]]);
$ir->setValue($r1, $rS['id'], 'Done');
assert_eq('1', $cell($r1, $rSF['id']), 'formula reads the status label');
$br->updateColumn($rS['id'], ['config' => ['labels' => array_map(fn($l) => $l['key'] === 'done' ? ['label' => 'Complete'] + $l : $l, $rS['config']['labels'])]]);
assert_eq(['0', 'Complete'], [$cell($r1, $rSF['id']), $DB->query("SELECT status_label FROM board_items WHERE id = $r1")->fetchColumn()], 'a renamed status label recomputes formulas and the item’s field');
$rP = $br->createColumn($rb, ['type' => 'progress', 'name' => 'Pct', 'config' => ['mode' => 'subitems']]);
$rPF = $br->createColumn($rb, ['type' => 'formula', 'name' => 'Pct x2', 'config' => ['formula' => '{Pct} * 2', 'precision' => 0]]);
$rs = $ir->addSubitem($r1, 'one'); $ir->addSubitem($r1, 'two');
$ir->updateSubitem($rs['id'], ['done' => true]);
assert_eq('100', $cell($r1, $rPF['id']), 'formulas read progress from subitems and follow subitem changes');
$rT = $br->createColumn($rb, ['type' => 'formula', 'name' => 'Days left', 'config' => ['formula' => '{Date} - TODAY()', 'precision' => 0]]);
$ir->setValue($r1, $rD['id'], date('Y-m-d', strtotime('+10 days')));
$DB->exec("UPDATE board_item_values SET value = '12' WHERE item_id = $r1 AND column_id = {$rT['id']}");
$DB->prepare("UPDATE project_boards SET formulas_on = ? WHERE board_id = ?")->execute([date('Y-m-d', strtotime('-1 day')), $rb]);
assert_eq(10.0, val(item($br->load($rb)['items'], $r1), $rT['id']), 'TODAY() formulas are recomputed once a day when the board is read');

// ------------------------------------------------------------------ dropdown remaps, copies and moves keep values right
$rDD = $br->createColumn($rb, ['type' => 'dropdown', 'name' => 'Trade', 'config' => ['options' => [['label' => 'Plumbing'], ['label' => 'Electrical'], ['label' => 'Roofing']], 'multi' => true]]);
$r2 = $ir->create($rb, $rg, 'Second row', [$rDD['id'] => 'Electrical'])['id'];
$ir->setValue($r1, $rDD['id'], ['Plumbing', 'Roofing']);
$br->updateColumn($rDD['id'], ['config' => ['options' => [['label' => 'Electrical', 'was' => 'Plumbing'], ['label' => 'Plumbing', 'was' => 'Electrical']], 'multi' => true]]);
assert_eq(['Electrical', 'Plumbing'], [$cell($r1, $rDD['id']), $cell($r2, $rDD['id'])], 'dropdown options renamed (even swapped) or removed: the cells follow');
$br->updateColumn($rDD['id'], ['config' => ['options' => [['label' => 'Sparky'], ['label' => 'Plumbing']], 'multi' => true]]);
assert_eq('Electrical', $cell($r1, $rDD['id']), 'an editor that does not say what an option was keeps the cells');
$rR = $br->createColumn($rb, ['type' => 'formula', 'name' => 'By id', 'config' => ['formula' => '[#' . $rA['id'] . '] + 1', 'precision' => 0]]);
$br->createColumn($rb, ['type' => 'progress', 'name' => 'From status', 'config' => ['mode' => 'status', 'sources' => [$rS['id']]]]);
$rc = $br->copyBoard($rb, $pid, true);
$rcc = array_column($br->load($rc)['columns'], null, 'name');
assert_eq('[#' . $rcc['A']['id'] . '] + 1', $rcc['By id']['config']['formula'], 'a copied board’s formulas use the copied column ids');
assert_eq([$rcc['Status']['id']], $rcc['From status']['config']['sources'], '… and so do its progress sources');
assert_eq('6', $cell((int)$DB->query("SELECT id FROM board_items WHERE board_id = $rc AND title = 'Formula row'")->fetchColumn(), $rcc['By id']['id']), 'copied formulas computed');
$rt = $br->createBoard($pid, ['template' => 'blank', 'title' => 'PrTest Target']);
$rtS = col($br->load($rt), 'Status');
$br->updateColumn($rtS['id'], ['config' => ['labels' => [['key' => 'open', 'label' => 'Open'], ['key' => 'fin', 'label' => 'Complete', 'done' => true]]]]);
$rtDD = $br->createColumn($rt, ['type' => 'dropdown', 'name' => 'Trade', 'config' => ['options' => [['label' => 'plumbing']]]]);
$ir->setValue($r2, $rS['id'], 'Complete');
$r3 = $ir->create($rb, $rg, 'Third row', [$rS['id'] => 'Working on it'])['id'];
$ir->moveToBoard($rb, [$r2, $r3], $rt, null);
assert_eq(['fin', false, 'plumbing'], [$cell($r2, $rtS['id']), $cell($r3, $rtS['id']), $cell($r2, $rtDD['id'])], 'moving boards maps statuses and options to the target’s own, and drops what it does not have');

// ------------------------------------------------------------------ automations: deleted columns / groups, other boards' ids, chains
$rgp = $br->createGroup($rb, 'Parked', null, 'bottom');
$ra1 = Automations::save($member, $rb, ['name' => 'To parked', 'trigger' => 'item_created', 'trigger_config' => [], 'action' => 'move_to_group', 'action_config' => ['group_id' => $rgp['id']]]);
$ra2 = Automations::save($member, $rb, ['name' => 'A changed', 'trigger' => 'column_changed', 'trigger_config' => ['column_id' => $rA['id']], 'action' => 'notify', 'action_config' => ['to' => 'creator']]);
throws(fn() => Automations::delete($member, $bid, $ra1), 'an automation is changed only through its own board');
$br->deleteGroup($rgp['id']);
$br->deleteColumn($rA['id']);
assert_eq([0, 0], array_map('intval', $DB->query("SELECT is_active FROM board_automations WHERE id IN ($ra1, $ra2)")->fetchAll(PDO::FETCH_COLUMN)), 'deleting a group or column switches off the automations that used it');
$rcb = $br->createBoard($pid, ['template' => 'blank', 'title' => 'PrTest Chain']);
$rK = $br->createColumn($rcb, ['type' => 'text', 'name' => 'K']);
for ($n = 0; $n < 8; $n++) Automations::save($member, $rcb, ['name' => "Set $n", 'trigger' => 'column_changed', 'trigger_config' => ['column_id' => $rK['id']], 'action' => 'set_value', 'action_config' => ['column_id' => $rK['id'], 'value' => "v$n"]]);
$rk = $ir->create($rcb, null, 'Chain')['id'];
$ir->setValue($rk, $rK['id'], 'go');
assert_true((int)$DB->query("SELECT COUNT(*) FROM board_audit_log WHERE item_id = $rk AND action = 'value_changed'")->fetchColumn() <= 9, 'recipes that set each other off run once each per change');

// ------------------------------------------------------------------ promote, bulk people, group delete, long text, legacy copy, due date
$rpb = $br->createBoard($pid, ['template' => 'tasks', 'title' => 'PrTest Promote']);
$rpc = array_column($br->load($rpb)['columns'], null, 'name');
$rpg = $br->load($rpb)['groups'][0]['id'];
$rp = $ir->create($rpb, $rpg, 'Parent')['id'];
$rn = $ir->create($rpb, $rpg, 'Next')['id'];
$rsub = $ir->addSubitem($rp, 'Child', ADMIN2, '2026-11-05');
$ir->updateSubitem($rsub['id'], ['done' => true]);
$rpr = $ir->promoteSubitem($rsub['id']);
assert_eq([[ADMIN2], '2026-11-05', 'done'], [val($rpr, $rpc['Owner']['id']), val($rpr, $rpc['Due date']['id']), val($rpr, $rpc['Status']['id'])], 'a promoted subitem keeps its owner, due date and done state');
assert_eq([$rp, $rpr['id'], $rn], array_map('intval', $DB->query("SELECT id FROM board_items WHERE board_id = $rpb ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN)), '… and sits right below its parent');
throws(fn() => $ir->promoteSubitem($rsub['id']), 'a subitem is promoted once');
$rbulk = [];
for ($n = 0; $n < 5; $n++) $rbulk[] = $ir->create($rpb, $rpg, "Bulk $n", [], 'bottom', ['quiet' => true, 'no_payload' => true])['id'];
$rnote = (int)$DB->query("SELECT COALESCE(MAX(id), 0) FROM notifications")->fetchColumn();
$ir->setValues($rpb, $rbulk, $rpc['Owner']['id'], [MEMBER, ADMIN2]);
$ir->setValues($rpb, array_slice($rbulk, 0, 2), $rpc['Owner']['id'], [ADMIN2]);
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > $rnote AND user_id = " . ADMIN2)->fetchColumn(), 'a bulk assign sends one notification per person');
$want = [];
foreach ($DB->query("SELECT v.item_id, v.value FROM board_item_values v JOIN board_items i ON i.id = v.item_id WHERE i.board_id = $rpb AND v.column_id = {$rpc['Owner']['id']}")->fetchAll(PDO::FETCH_NUM) as [$wi, $wv]) {
    foreach (explode(',', $wv) as $wu) $want[] = "$wi:$wu";
}
$have = array_map(fn($p) => $p[0] . ':' . $p[1], $DB->query("SELECT item_id, user_id FROM board_item_people WHERE board_id = $rpb")->fetchAll(PDO::FETCH_NUM));
sort($want); sort($have);
assert_eq($want, $have, 'the people index matches the cells after bulk edits');
$rgx = $br->createGroup($rpb, 'Short-lived', null, 'bottom');
$rparked = [$ir->create($rpb, $rgx['id'], 'Parked 1')['id'], $ir->create($rpb, $rgx['id'], 'Parked 2')['id']];
$br->deleteGroup($rgx['id']);
$ir->archive($rpb, $rparked, false);
$rorder = array_map('intval', $DB->query("SELECT id FROM board_items WHERE board_id = $rpb AND group_id = $rpg AND archived = 0 ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN));
assert_eq($rparked, array_slice($rorder, -2), 'items of a deleted group come back below the items already there');
$rlt = $br->createColumn($rpb, ['type' => 'longtext', 'name' => 'Notes']);
$ir->setValue($rp, $rlt['id'], str_repeat("\u{1F600}", 20000));
assert_true(strlen($cell($rp, $rlt['id'])) <= 65535 && mb_check_encoding($cell($rp, $rlt['id']), 'UTF-8'), 'long text is cut to what a TEXT cell holds');
$rtl = $br->createColumn($rpb, ['type' => 'timeline', 'name' => 'Window']);
$ir->setValue($rn, $rtl['id'], ['start' => '2026-11-01', 'end' => '2026-11-09']);
BoardRepo::migrateLegacyFields($DB, C, $rpb);
assert_eq(false, $cell($rn, $rpc['Due date']['id']), 'the legacy copy leaves alone a due date that only mirrors the timeline');
$rin = $br->createColumn($rpb, ['type' => 'date', 'name' => 'Inspection']);
$ir->setValue($rn, $rin['id'], '2026-10-30');
$br->updateColumn($rpc['Due date']['id'], ['name' => 'Ordered']);
$br->updateColumn($rin['id'], ['name' => 'Inspection deadline']);
assert_eq('2026-10-30', $DB->query("SELECT due_date FROM board_items WHERE id = $rn")->fetchColumn(), 'renaming date columns moves the items’ due date');

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
