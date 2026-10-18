<?php
// /projects/api/guests.php — people outside the company who may view a board (managers).
//   GET  ?board_id=3
//   POST {action:'invite', board_id, email, days}
//   POST {action:'resend', board_id, id, days}
//   POST {action:'revoke', board_id, id}
// Invite answers with whether the email went out and the link to share by hand.
require_once __DIR__ . '/_bootstrap.php';

if (!pr_is_post()) pr_json(['guests' => Guests::list($PR, (int)($_GET['board_id'] ?? 0))]);

$in = pr_input();
$boardId = pr_int($in, 'board_id');

switch (pr_str($in, 'action', 20)) {
    case 'invite':
        pr_rate_limit('pr-guest', 30, 3600);
        $r = Guests::invite($PR, $boardId, pr_str($in, 'email', 255), pr_int($in, 'days') ?: 30);
        pr_json($r + ['guests' => Guests::list($PR, $boardId)]);

    case 'resend':
        pr_rate_limit('pr-guest', 30, 3600);
        $r = Guests::resend($PR, $boardId, pr_int($in, 'id'), pr_int($in, 'days') ?: 30);
        pr_json($r + ['guests' => Guests::list($PR, $boardId)]);

    case 'revoke':
        Guests::revoke($PR, $boardId, pr_int($in, 'id'));
        pr_json(['guests' => Guests::list($PR, $boardId)]);
}
pr_error('Unknown action');
