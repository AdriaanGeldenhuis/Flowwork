<?php
/**
 * Mail ↔ Projects: link chips for emails filed with a project, board or item
 * ("Add to project" in the reader). Needs the flowwork_test database
 * (tests/db/setup.sh); no mail server. Removes every row it creates.
 *
 * Usage: php tests/mail/test_mail_projects.php
 */

require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/mail/lib/autoload.php';

if (!MailRepo::projectsAvailable()) { echo "SKIP: Projects module not installed\n"; exit(0); }
MailSchema::ensure($DB);
ProjectSchema::ensure($DB);

const OTHER_CO = 999001; // a company id nobody uses

$made = ['emails' => [], 'email_accounts' => [], 'board_items' => [], 'project_boards' => [], 'projects' => []];
register_shutdown_function(function () use ($DB, &$made) {
    $in = fn(array $ids) => implode(',', array_map('intval', $ids ?: [0]));
    $DB->exec("DELETE FROM email_links WHERE email_id IN (" . $in($made['emails']) . ")");
    $DB->exec("DELETE FROM emails WHERE email_id IN (" . $in($made['emails']) . ")");
    $DB->exec("DELETE FROM email_accounts WHERE account_id IN (" . $in($made['email_accounts']) . ")");
    $DB->exec("DELETE FROM board_audit_log WHERE item_id IN (" . $in($made['board_items']) . ")");
    $DB->exec("DELETE FROM board_items WHERE id IN (" . $in($made['board_items']) . ")");
    $DB->exec("DELETE FROM project_boards WHERE board_id IN (" . $in($made['project_boards']) . ")");
    $DB->exec("DELETE FROM projects WHERE project_id IN (" . $in($made['projects']) . ")");
});
$insert = function (string $table, array $row) use ($DB, &$made): int {
    $cols = array_keys($row);
    $DB->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
       ->execute(array_values($row));
    $id = (int)$DB->lastInsertId();
    $made[$table][] = $id;
    return $id;
};
$link = fn(int $emailId, string $type, int $id) => $DB->prepare("INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
    ->execute([TEST_COMPANY_ID, $emailId, $type, $id, TEST_USER_ID]);

// ---------------------------------------------------------------- fixtures
$projectId = $insert('projects', ['company_id' => TEST_COMPANY_ID, 'name' => 'MailTest Harbour', 'project_number' => 'PRJ-T901', 'status' => 'active',
                                   'visibility' => 'company', 'created_by' => TEST_USER_ID]);
$boardId = $insert('project_boards', ['company_id' => TEST_COMPANY_ID, 'project_id' => $projectId, 'title' => 'MailTest Schedule']);
$itemId = $insert('board_items', ['company_id' => TEST_COMPANY_ID, 'board_id' => $boardId, 'group_id' => 0, 'title' => 'MailTest Order tiles', 'created_by' => TEST_USER_ID]);
$goneItemId = $insert('board_items', ['company_id' => TEST_COMPANY_ID, 'board_id' => $boardId, 'group_id' => 0, 'title' => 'MailTest gone', 'created_by' => TEST_USER_ID]);
$DB->exec("DELETE FROM board_items WHERE id = $goneItemId");
$otherProjectId = $insert('projects', ['company_id' => OTHER_CO, 'name' => 'MailTest Elsewhere', 'status' => 'active', 'created_by' => TEST_USER_ID]);

$accountId = $insert('email_accounts', ['company_id' => TEST_COMPANY_ID, 'user_id' => TEST_USER_ID, 'account_name' => 'MailTest', 'email_address' => 'mailtest@example.com', 'is_active' => 0]);
$mail = fn(string $subject) => $insert('emails', ['company_id' => TEST_COMPANY_ID, 'account_id' => $accountId, 'folder' => 'INBOX', 'direction' => 'incoming',
                                                 'sender' => 'site@example.com', 'subject' => $subject, 'body_text' => 'x', 'sent_at' => date('Y-m-d H:i:s')]);
$e1 = $mail('Tiles');
$e2 = $mail('Re: Tiles');

// ---------------------------------------------------------------- link chips
$link($e1, 'item', $itemId);
$link($e2, 'item', $itemId);          // the same item twice in one conversation → one chip
$link($e2, 'board', $boardId);
$link($e1, 'item', $goneItemId);      // deleted → skipped
$link($e1, 'project', $otherProjectId); // another company's → skipped
$chips = [];
foreach (MailRepo::linksFor($DB, TEST_COMPANY_ID, [$e1, $e2]) as $l) $chips[$l['type'] . ':' . $l['id']] = $l;

assert_eq(['item:' . $itemId, 'board:' . $boardId], array_keys($chips), 'one chip per item/board; deleted and other-company links skipped');
assert_eq('MailTest Order tiles · MailTest Schedule', $chips['item:' . $itemId]['label'], 'item chip label (item · board)');
assert_eq(ProjectLink::itemUrl($boardId, $itemId), $chips['item:' . $itemId]['url'], 'item chip opens the item on its board');
assert_eq('MailTest Schedule · MailTest Harbour', $chips['board:' . $boardId]['label'], 'board chip label (board · project)');
assert_eq(ProjectLink::boardUrl($boardId), $chips['board:' . $boardId]['url'], 'board chip URL');
$other = array_map(fn($l) => $l['type'] . ':' . $l['id'], MailRepo::linksFor($DB, OTHER_CO, [$e1, $e2]));
assert_eq(['project:' . $otherProjectId], $other, 'labels are scoped to the company asking: the other company sees only its own project');

// ---------------------------------------------------------------- "Add to project" → whole project
ProjectLink::linkEmail(ProjectLink::repo($DB, TEST_COMPANY_ID, TEST_USER_ID, 'admin'), 'project', $projectId, $e2);
ProjectLink::linkEmail(ProjectLink::repo($DB, TEST_COMPANY_ID, TEST_USER_ID, 'admin'), 'project', $projectId, $e2); // idempotent
$n = $DB->prepare("SELECT COUNT(*) FROM email_links WHERE email_id = ? AND linked_type = 'project' AND linked_id = ?");
$n->execute([$e2, $projectId]);
assert_eq(1, (int)$n->fetchColumn(), 'linking twice keeps one row');
$chips = array_column(MailRepo::linksFor($DB, TEST_COMPANY_ID, [$e1, $e2]), null, 'type');
assert_eq('PRJ-T901 · MailTest Harbour', $chips['project']['label'] ?? null, 'project chip label (number · name)');
assert_eq(ProjectLink::projectUrl($projectId), $chips['project']['url'] ?? null, 'project chip opens the project');

$error = '';
try { ProjectLink::linkEmail(ProjectLink::repo($DB, TEST_COMPANY_ID, 3, 'member'), 'project', $projectId, $e1); } catch (ProjectUserError $e) { $error = $e->getMessage(); }
assert_true(str_contains($error, 'mailboxes'), 'a colleague who can see the project still cannot link an email from this mailbox: ' . $error);

echo 'mail projects: OK (' . $GLOBALS['__assert_count'] . " assertions)\n";
