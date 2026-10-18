<?php
// /calendar/api/people.php
//   GET                                   colleagues in this company (for invitations and sharing)
//   GET ?busy=1&users=3,4&from=…&to=…[&exclude=12]   their busy times (no event details)
require_once __DIR__ . '/_bootstrap.php';

if (!empty($_GET['busy'])) {
    $from = cal_date($_GET['from'] ?? '');
    $to = cal_date($_GET['to'] ?? '');
    if (!$from || !$to || $to < $from || strtotime($to) - strtotime($from) > 62 * 86400) cal_error('Choose a valid date range.');
    $users = array_filter(array_map('intval', explode(',', (string)($_GET['users'] ?? ''))));
    cal_json(['busy' => $CAL->busy($users, $from . ' 00:00:00', $to . ' 23:59:59', (int)($_GET['exclude'] ?? 0) ?: null)]);
}

cal_json(['people' => $CAL->people(), 'me' => $CAL_USER_ID]);
