<?php
// /projects/api/pickers.php — project / board / item dropdowns for other modules
// (Quotes & Invoices, Receipts, Timesheets, Mail). Only what the user may open.
//   GET ?type=projects[&keep=12,15]      open projects (+ the ones a document already uses)
//   GET ?type=boards&project_id=5
//   GET ?type=items&board_id=3
//   GET ?type=groups&board_id=3
require_once __DIR__ . '/_bootstrap.php';

switch ((string)($_GET['type'] ?? '')) {
    case 'projects':
        // keep only brings back closed projects the user can see (server-side callers pass their document's own project directly).
        $keep = pr_ids((string)($_GET['keep'] ?? ''), 50);
        $visible = $PR->visibleProjectIds(); // null: admins see every project
        if ($visible !== null) $keep = array_values(array_filter($keep, fn($id) => isset($visible[$id])));
        pr_json(['projects' => ProjectLink::pickerProjects($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE, $keep)]);
    case 'boards':
        pr_json(['boards' => ProjectLink::pickerBoards($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE, (int)($_GET['project_id'] ?? 0))]);
    case 'items':
        pr_json(['items' => ProjectLink::pickerItems($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE, (int)($_GET['board_id'] ?? 0))]);
    case 'groups':
        $boardId = (int)($_GET['board_id'] ?? 0);
        $PR->requireBoard($boardId, 1);
        pr_json(['groups' => array_map(fn($g) => BoardRepo::groupOut($g), (new BoardRepo($PR))->groupRows($boardId))]);
    case 'clients':
        // CRM customers by name, for the project's client field.
        $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
        if (mb_strlen($q) < 2) pr_json(['clients' => []]);
        pr_rate_limit('pr-search', 120, 60);
        $soft = '';
        try { if ($DB->query("SHOW COLUMNS FROM crm_accounts LIKE 'deleted_at'")->fetch()) $soft = ' AND deleted_at IS NULL'; } catch (Throwable $e) { /* older schema */ }
        $st = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer'$soft AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 10");
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st->execute([$PR_COMPANY_ID, $like, $like]);
        pr_json(['clients' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $st->fetchAll(PDO::FETCH_ASSOC))]);
}
pr_error('Unknown picker');
