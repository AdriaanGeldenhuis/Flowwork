<?php
// /calendar/api/settings.php
//   GET   the user's calendar settings (company defaults filled in) and the sources available to them
//   POST  {week_start, work_start, work_end, default_view, default_reminder, show_weekends, time_24h,
//          default_duration, default_calendar_id, hidden_calendars[], sources{name: bool}} — any subset
require_once __DIR__ . '/_bootstrap.php';

$sourcesPayload = function () use ($DB, $CAL): array {
    $available = (new CalendarSources($DB, $CAL))->available();
    $out = [];
    foreach (CalendarSources::META as $name => $m) {
        $out[] = ['id' => $name, 'available' => $available[$name]] + $m;
    }
    return $out;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in = cal_input();
    if (array_key_exists('default_calendar_id', $in) && (int)$in['default_calendar_id'] > 0) {
        $c = $CAL->calendar((int)$in['default_calendar_id']);
        if (!$c || !$c['perms']['add']) throw new CalendarUserError('You cannot add events to that calendar.');
    }
    $settings = CalendarSettings::save($DB, $CAL_COMPANY_ID, $CAL_USER_ID, $in);
    cal_json(['settings' => $settings, 'sources' => $sourcesPayload()]);
}

cal_json(['settings' => CalendarSettings::load($DB, $CAL_COMPANY_ID, $CAL_USER_ID), 'sources' => $sourcesPayload()]);
