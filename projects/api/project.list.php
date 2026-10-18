<?php
// /projects/api/project.list.php — kept for older callers (Finance reports use
// it for the project filter). Answers in the old shape: {ok, data:{projects:[{project_id, name, …}]}}.
// A report filter needs every project the person can open, finished ones too.
// New code: /projects/api/pickers.php?type=projects or /projects/api/projects.php.
require_once __DIR__ . '/_bootstrap.php';

[$vis] = $PR->visibleSql('p');
$st = $DB->prepare("SELECT p.project_id, p.name, p.project_number, p.status FROM projects p
                     WHERE p.company_id = ? AND $vis ORDER BY p.name, p.project_id LIMIT 2000");
$st->execute([$PR_COMPANY_ID]);
$rows = array_map(fn($p) => ['project_id' => (int)$p['project_id'], 'name' => (string)$p['name'], 'project_number' => (string)($p['project_number'] ?? ''), 'status' => (string)$p['status']],
                  $st->fetchAll(PDO::FETCH_ASSOC));
pr_json(['data' => ['projects' => $rows, 'pagination' => ['page' => 1, 'pages' => 1, 'total' => count($rows)]]]);
