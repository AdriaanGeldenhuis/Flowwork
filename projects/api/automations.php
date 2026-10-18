<?php
// /projects/api/automations.php — "When … then …" recipes on a board (managers).
//   GET  ?board_id=3
//   POST {action:'save', board_id, id?, name, trigger, trigger_config{}, action, action_config{}, active?}
//   POST {action:'toggle', board_id, id, on}
//   POST {action:'delete', board_id, id}
// The automation's own "action" field is sent as "do" to keep it apart from the request action.
require_once __DIR__ . '/_bootstrap.php';

if (!pr_is_post()) {
    $boardId = (int)($_GET['board_id'] ?? 0);
    pr_json(['automations' => Automations::list($PR, $boardId), 'triggers' => Automations::TRIGGERS, 'actions' => Automations::ACTIONS]);
}

$in = pr_input();
$boardId = pr_int($in, 'board_id');

switch (pr_str($in, 'action', 20)) {
    case 'save':
        pr_rate_limit('pr-struct', 300, 600);
        $rule = $in;
        $rule['action'] = (string)($in['do'] ?? '');
        $id = Automations::save($PR, $boardId, $rule);
        pr_json(['id' => $id, 'automations' => Automations::list($PR, $boardId)]);

    case 'toggle':
        Automations::toggle($PR, $boardId, pr_int($in, 'id'), !empty($in['on']));
        pr_json(['automations' => Automations::list($PR, $boardId)]);

    case 'delete':
        Automations::delete($PR, $boardId, pr_int($in, 'id'));
        pr_json(['automations' => Automations::list($PR, $boardId)]);
}
pr_error('Unknown action');
