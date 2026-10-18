<?php
// /projects/api/project.list.php — kept for older callers (Finance reports used
// it for the project filter). Answers in the old shape: {ok, data:{projects:[{project_id, name, …}]}}.
// New code: /projects/api/pickers.php?type=projects or /projects/api/projects.php.
require_once __DIR__ . '/_bootstrap.php';

$rows = ProjectLink::pickerProjects($DB, $PR_COMPANY_ID, $PR_USER_ID, $PR_ROLE);
pr_json(['data' => ['projects' => array_map(fn($p) => ['project_id' => $p['id'], 'name' => $p['name'], 'project_number' => $p['number'], 'status' => $p['status']], $rows),
                    'pagination' => ['page' => 1, 'pages' => 1, 'total' => count($rows)]]]);
