<?php
// GET /calendar/api/feed.php?from=2026-10-01&to=2026-11-08
// Events the user can see in the range (repeating events expanded) plus the
// live items from other modules (projects, tasks, invoices, quotes, deals,
// pay days) that the company allows. The client hides calendars and sources
// the user switched off, so toggling them needs no new request.
require_once __DIR__ . '/_bootstrap.php';

$from = cal_date($_GET['from'] ?? '');
$to = cal_date($_GET['to'] ?? '');
if (!$from || !$to || $to < $from) cal_error('Choose a valid date range.');
if ((strtotime($to) - strtotime($from)) > 400 * 86400) cal_error('That range is too long.');

$events = $CAL->feed($from . ' 00:00:00', $to . ' 23:59:59');
$items = [];
if (($_GET['sources'] ?? '1') !== '0') {
    $sources = new CalendarSources($DB, $CAL);
    $all = array_fill_keys(array_keys(CalendarSources::META), true);
    $items = $sources->items($from, $to, $all);
}

cal_json(['from' => $from, 'to' => $to, 'events' => $events, 'items' => $items,
          'now' => CalendarRepo::nowLocal($DB, $CAL_COMPANY_ID)]);
