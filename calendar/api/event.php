<?php
// /calendar/api/event.php
//   GET  ?id=12[&occ=2026-10-21 09:00:00]   one event with guests, reminders, files and links
//   POST {action:'create', ...fields}
//   POST {action:'update', id, scope:'all'|'this'|'following', occ, ...fields}
//   POST {action:'move',   id, start, end, scope, occ}       drag / resize
//   POST {action:'delete', id, scope, occ}
//   POST {action:'respond', id, response:'accepted'|'declined'|'tentative'}
// Fields: calendar_id, title, description, location, color, all_day, start, end,
//         recurrence (RRULE), visibility, participants[{user_id, role}],
//         reminders[{minutes_before, channel}], links[{type, id}], notify (bool)
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) cal_error('Event not found', 404);
    $occ = isset($_GET['occ']) ? str_replace('T', ' ', (string)$_GET['occ']) : null;
    try {
        cal_json(['event' => $CAL->detail($id, $occ)]);
    } catch (CalendarUserError $e) {
        cal_error($e->getMessage(), 404);
    }
}

cal_require_post();
$in = cal_input();
$action = cal_str($in, 'action', 20);
$id = (int)($in['id'] ?? 0);
$scope = in_array($in['scope'] ?? 'all', ['all', 'this', 'following'], true) ? ($in['scope'] ?? 'all') : 'all';
$occ = isset($in['occ']) && is_string($in['occ']) && $in['occ'] !== '' ? $in['occ'] : null;
$notify = !array_key_exists('notify', $in) || !empty($in['notify']);
$fields = array_intersect_key($in, array_flip(['calendar_id', 'title', 'description', 'location', 'color', 'all_day', 'start', 'end',
                                                'recurrence', 'visibility', 'participants', 'reminders', 'links']));

switch ($action) {
    case 'create':
        cal_rate_limit('cal-write', 300, 600);
        $newId = $CAL->create($fields, $notify);
        cal_json(['id' => $newId, 'event' => $CAL->detail($newId)]);

    case 'update':
        cal_rate_limit('cal-write', 300, 600);
        $resultId = $CAL->update($id, $fields, $scope, $occ, $notify);
        cal_json(['id' => $resultId, 'event' => $CAL->detail($resultId)]);

    case 'move':
        cal_rate_limit('cal-write', 300, 600);
        $resultId = $CAL->move($id, cal_str($in, 'start', 25), cal_str($in, 'end', 25), $scope, $occ);
        cal_json(['id' => $resultId]);

    case 'delete':
        cal_rate_limit('cal-write', 300, 600);
        $files = $CAL->delete($id, $scope, $occ, $notify);
        foreach ($files as $rel) {
            if ($path = CalendarPaths::resolveStored((string)$rel)) @unlink($path);
        }
        cal_json();

    case 'respond':
        $CAL->respond($id, cal_str($in, 'response', 20));
        cal_json(['event' => $CAL->detail($id)]);
}
cal_error('Unknown action');
