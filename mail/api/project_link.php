<?php
// /mail/api/project_link.php — "Add to project": file emails with Projects.
//   GET  ?key=t123&view=inbox                          → {links:[...]} the conversation's link chips
//   POST {op:"link", key, email_id, project_id}         link one email of the conversation to a project
//   POST {op:"unlink", key, type, id}                   remove a project / board / item link from the conversation
// New items (create with email_id) and links to an existing item (link_email)
// go straight to /projects/api/items.php; this covers what that API does not.
require_once __DIR__ . '/_bootstrap.php';

if (!MailRepo::projectsAvailable()) mail_error('Projects is not available', 404);

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? mail_input() : $_GET;
$key = mail_str($in, 'key', 30);
if (!MailRepo::parseKey($key)) mail_error('Invalid conversation');

/** Email ids of the conversation (the same messages the reader shows for this view). */
$conversation = function (string $scope) use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $key): array {
    $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], $scope);
    if (!$rows && $scope !== 'all') $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, [$key], 'all');
    if (!$rows) mail_error('Conversation not found', 404);
    return array_map(fn($r) => (int)$r['email_id'], $rows);
};
$view = mail_str($in, 'view', 20);
$scope = in_array($view, ['trash', 'spam', 'outbox'], true) ? $view : 'visible';

if (!$isPost) {
    mail_json(['links' => MailRepo::linksFor($DB, $MAIL_COMPANY_ID, $conversation($scope))]);
}

$op = mail_str($in, 'op', 10);

if ($op === 'link') {
    $emailId = (int)($in['email_id'] ?? 0);
    if (!in_array($emailId, $conversation('all'), true)) mail_error('Message not found', 404);
    $projectId = (int)($in['project_id'] ?? 0);
    if ($projectId <= 0 || !ProjectLink::belongs($DB, $MAIL_COMPANY_ID, $projectId)) mail_error('Project not found', 404);
    try {
        // Checks the user may open the project and owns the mailbox.
        ProjectLink::linkEmail(ProjectLink::repo($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $MAIL_ROLE), 'project', $projectId, $emailId);
    } catch (ProjectUserError $e) {
        mail_error($e->getMessage(), $e instanceof ProjectForbidden ? 403 : 400);
    }
    // ProjectLink writes links without company_id; set it like the other mail writers do.
    $DB->prepare("UPDATE email_links SET company_id = ? WHERE email_id = ? AND linked_type = 'project' AND linked_id = ? AND company_id IS NULL")
       ->execute([$MAIL_COMPANY_ID, $emailId, $projectId]);
    mail_json([
        'link'  => ProjectLink::describe($DB, $MAIL_COMPANY_ID, 'project', $projectId),
        'links' => MailRepo::linksFor($DB, $MAIL_COMPANY_ID, $conversation($scope)),
    ]);
}

if ($op === 'unlink') {
    $type = mail_str($in, 'type', 10);
    $id = (int)($in['id'] ?? 0);
    if (!in_array($type, MailRepo::PROJECT_LINK_TYPES, true) || $id <= 0) mail_error('Unknown link');
    $ids = $conversation('all'); // only this user's own messages
    $DB->prepare("DELETE FROM email_links WHERE linked_type = ? AND linked_id = ? AND email_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
       ->execute(array_merge([$type, $id], $ids));
    mail_json(['links' => MailRepo::linksFor($DB, $MAIL_COMPANY_ID, $conversation($scope))]);
}

mail_error('Unknown operation');
