<?php
// /projects/api/money.php — a project's budget, quotes, invoices, costs and hours.
//   GET  ?project_id=5
//   POST {action:'cost_save', project_id, id?, type, description, qty, unit_cost, item_id?, supplier_id?}
//   POST {action:'cost_delete', project_id, id}
require_once __DIR__ . '/_bootstrap.php';

if (!pr_is_post()) pr_json(['money' => ProjectLink::financials($PR, (int)($_GET['project_id'] ?? 0))]);

$in = pr_input();
$pid = pr_int($in, 'project_id');

switch (pr_str($in, 'action', 20)) {
    case 'cost_save':
        pr_rate_limit('pr-write', 600, 600);
        ProjectLink::saveCost($PR, $pid, $in);
        pr_json(['money' => ProjectLink::financials($PR, $pid)]);

    case 'cost_delete':
        ProjectLink::deleteCost($PR, $pid, pr_int($in, 'id'));
        pr_json(['money' => ProjectLink::financials($PR, $pid)]);
}
pr_error('Unknown action');
