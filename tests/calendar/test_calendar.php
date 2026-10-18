<?php
/**
 * Calendar engine (calendar/lib) against the test database: schema, repeat
 * rules, who sees what, edit/delete scopes on repeating events, invitations,
 * reminders (company time zone), live Flowwork sources, retired-sync copies,
 * CRM follow-ups, project timelines, iCalendar import/export and settings.
 * Project and task dates, project links and project calendars follow the
 * Projects engine's rules (ProjectLink: who may open a project or board, the
 * people on an item, the items' mirrored dates).
 *
 *   php tests/calendar/test_calendar.php
 *
 * Needs the test database (tests/db/setup.sh) with its three company-1 users
 * (1 admin, 3 member, 4 admin). Everything the test creates is removed again
 * at the end, and the settings it changes are put back.
 */

require __DIR__ . '/../lib/bootstrap.php';
require FLOWWORK_ROOT . '/calendar/lib/autoload.php';
require FLOWWORK_ROOT . '/calendar/lib/reminders.php';
require FLOWWORK_ROOT . '/includes/notify.php';
require FLOWWORK_ROOT . '/qi/services/CalendarHook.php';
require FLOWWORK_ROOT . '/crm/includes/calendar_followup.php';

const C = TEST_COMPANY_ID;
const ADMIN = 1;
const MEMBER = 3;
const ADMIN2 = 4;

// ------------------------------------------------------------------ clean-up

CalendarSchema::ensure($DB); // so the tables exist before we take the high-water marks
CalendarRepo::projectsAvailable(); // loads the Projects engine (ProjectLink) …
ProjectSchema::ensure($DB);        // … provisioned before the fixtures, so its one-off backfill never rewrites them
fw_notify_install($DB);

$maxId = fn(string $t, string $col = 'id') => (int)$DB->query("SELECT COALESCE(MAX(`$col`), 0) FROM `$t`")->fetchColumn();
$mark = [
    'calendar_events' => $maxId('calendar_events'), 'calendars' => $maxId('calendars'),
    'notifications' => $maxId('notifications'), 'invoices' => $maxId('invoices'),
    'crm_accounts' => $maxId('crm_accounts'), 'projects' => $maxId('projects', 'project_id'),
];
$settingsBackup = $DB->query("SELECT * FROM calendar_settings WHERE company_id = " . C)->fetchAll(PDO::FETCH_ASSOC);
$companyBackup = $DB->query("SELECT setting_key, setting_value FROM company_settings
                              WHERE company_id = " . C . " AND setting_key LIKE 'calendar\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

register_shutdown_function(function () use ($DB, $mark, $settingsBackup, $companyBackup) {
    $DB->exec("SET FOREIGN_KEY_CHECKS = 0");
    $ev = "SELECT id FROM calendar_events WHERE id > {$mark['calendar_events']}";
    foreach (['calendar_event_participants', 'calendar_event_reminders', 'calendar_event_links', 'calendar_event_attachments'] as $t) {
        $DB->exec("DELETE FROM `$t` WHERE event_id IN ($ev)");
    }
    $DB->exec("DELETE FROM calendar_events WHERE id > {$mark['calendar_events']}");
    $DB->exec("DELETE FROM calendars WHERE id > {$mark['calendars']}");
    $DB->exec("DELETE FROM notifications WHERE id > {$mark['notifications']}");
    $DB->exec("DELETE FROM invoice_lines WHERE invoice_id > {$mark['invoices']}");
    $DB->exec("DELETE FROM invoices WHERE id > {$mark['invoices']}");
    $DB->exec("DELETE FROM crm_accounts WHERE id > {$mark['crm_accounts']}");
    $DB->exec("DELETE FROM board_item_people WHERE item_id IN (SELECT id FROM board_items WHERE title LIKE 'CalTest %')");
    $DB->exec("DELETE FROM board_members WHERE board_id IN (SELECT board_id FROM project_boards WHERE title LIKE 'CalTest %')");
    $DB->exec("DELETE FROM board_items WHERE title LIKE 'CalTest %'");
    $DB->exec("DELETE FROM project_boards WHERE title LIKE 'CalTest %'");
    $DB->exec("DELETE FROM project_members WHERE project_id > {$mark['projects']}");
    $DB->exec("DELETE FROM projects WHERE project_id > {$mark['projects']}");
    $DB->exec("DELETE FROM calendar_settings WHERE company_id = " . C);
    foreach ($settingsBackup as $row) {
        $cols = array_keys($row);
        $DB->prepare("INSERT INTO calendar_settings (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($row));
    }
    $DB->exec("DELETE FROM company_settings WHERE company_id = " . C . " AND setting_key LIKE 'calendar\\_%'");
    foreach ($companyBackup as $k => $v) {
        $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)")->execute([C, $k, $v]);
    }
    $DB->exec("SET FOREIGN_KEY_CHECKS = 1");
});

function company_setting(PDO $db, string $key, ?string $value): void
{
    $db->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key = ?")->execute([C, $key]);
    if ($value !== null) {
        $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, ?, ?)")->execute([C, $key, $value]);
    }
}

function repo(int $userId, string $role): CalendarRepo
{
    return new CalendarRepo($GLOBALS['DB'], C, $userId, $role);
}

function expect_user_error(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (CalendarUserError $e) {
        assert_true(true, $message);
        return;
    }
    fail("ASSERT FAILED: $message (no CalendarUserError)");
}

function feed_ids(array $feed): array
{
    return array_map(fn($e) => (int)$e['id'], $feed);
}

function feed_for(array $feed, int $id): array
{
    return array_values(array_filter($feed, fn($e) => (int)$e['id'] === $id));
}

function notifications_for(PDO $db, int $userId, string $type, int $after): array
{
    $st = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = ? AND id > ? ORDER BY id");
    $st->execute([$userId, $type, $after]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Event times are wall-clock times in the company zone; make sure it is not the server's (UTC here).
company_setting($DB, 'calendar_timezone', 'Africa/Johannesburg');
company_setting($DB, 'calendar_default_reminder', '0');
$DB->exec("DELETE FROM calendar_settings WHERE company_id = " . C);
$tz = CalendarRepo::companyTz($DB, C);
assert_eq('Africa/Johannesburg', $tz->getName(), 'company time zone read from company_settings');
$nowLocal = CalendarRepo::nowLocal($DB, C);
assert_true(abs(strtotime($nowLocal) - (time() + 7200)) < 5, 'nowLocal is the company wall clock (UTC+2), not the server clock');

// ===================================================================== schema

$marker = CalendarPaths::storageDir() . '/.schema-' . CalendarSchema::SCHEMA_VERSION;
@unlink($marker);
$rp = new ReflectionProperty(CalendarSchema::class, 'done');
$rp->setValue(null, false);
CalendarSchema::ensure($DB);
assert_true(is_file($marker), 'schema provisioning writes its marker');

$st = $DB->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('calendars','calendar_events','calendar_event_participants',
                         'calendar_event_reminders','calendar_event_links','calendar_event_attachments','calendar_settings')");
$coll = $st->fetchAll(PDO::FETCH_KEY_PAIR);
assert_eq(7, count($coll), 'all seven calendar tables exist');
foreach ($coll as $t => $c) assert_true(str_starts_with((string)$c, 'utf8mb4'), "$t is utf8mb4 ($c)");

$enum = $DB->query("SHOW COLUMNS FROM calendar_event_links LIKE 'linked_type'")->fetch(PDO::FETCH_ASSOC)['Type'];
foreach (CalendarSchema::LINK_TYPES as $t) assert_true(str_contains($enum, "'$t'"), "link type $t allowed");

$cols = fn($t) => array_column($DB->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['ics_uid', 'series_id', 'recurrence_id', 'updated_by', 'exdates_json', 'visibility'] as $c) {
    assert_true(in_array($c, $cols('calendar_events'), true), "calendar_events.$c");
}
foreach (['snoozed_until', 'last_occurrence'] as $c) assert_true(in_array($c, $cols('calendar_event_reminders'), true), "reminders.$c");
assert_true(in_array('prefs_json', $cols('calendar_settings'), true), 'calendar_settings.prefs_json');
assert_true(in_array('sharing_json', $cols('calendars'), true) && in_array('ics_token', $cols('calendars'), true), 'calendars sharing + feed token');

// ============================================================== repeat rules

$occ = fn($s, $e, $r, $x, $from, $to) => array_column(Recurrence::occurrences($s, $e, $r, $x, $from, $to, 400), 'start');

assert_eq(['2026-10-05 09:00:00', '2026-10-07 09:00:00', '2026-10-12 09:00:00', '2026-10-14 09:00:00'],
          $occ('2026-10-05 09:00:00', '2026-10-05 10:00:00', 'FREQ=WEEKLY;BYDAY=MO,WE', [], '2026-10-01 00:00:00', '2026-10-15 00:00:00'),
          'weekly on Monday and Wednesday');
assert_eq(['2026-10-05 09:00:00', '2026-10-07 09:00:00', '2026-10-14 09:00:00'],
          $occ('2026-10-05 09:00:00', '2026-10-05 10:00:00', 'FREQ=WEEKLY;BYDAY=MO,WE', ['2026-10-12' => true], '2026-10-01 00:00:00', '2026-10-15 00:00:00'),
          'a skipped date (exdate) is left out');
assert_eq(['2026-01-31 09:00:00', '2026-03-31 09:00:00', '2026-05-31 09:00:00', '2026-07-31 09:00:00'],
          $occ('2026-01-31 09:00:00', '2026-01-31 10:00:00', 'FREQ=MONTHLY;COUNT=4', [], '2026-01-01 00:00:00', '2026-12-31 23:59:59'),
          'monthly on the 31st skips shorter months (RFC 5545)');
assert_eq(['2024-02-29 00:00:00', '2028-02-29 00:00:00'],
          $occ('2024-02-29 00:00:00', '2024-02-29 23:59:59', 'FREQ=YEARLY', [], '2024-01-01 00:00:00', '2031-12-31 23:59:59'),
          'yearly on 29 February only in leap years');
assert_eq(['2026-10-13 09:00:00', '2026-11-10 09:00:00', '2026-12-08 09:00:00'],
          $occ('2026-10-13 09:00:00', '2026-10-13 10:00:00', 'FREQ=MONTHLY;BYDAY=2TU;COUNT=3', [], '2026-01-01 00:00:00', '2027-12-31 23:59:59'),
          'monthly on the second Tuesday, three times');
assert_eq(['2026-10-30 09:00:00', '2026-11-27 09:00:00', '2026-12-25 09:00:00'],
          $occ('2026-10-30 09:00:00', '2026-10-30 10:00:00', 'FREQ=MONTHLY;BYDAY=-1FR', [], '2026-10-01 00:00:00', '2026-12-31 23:59:59'),
          'monthly on the last Friday');
assert_eq(['2026-10-05 09:00:00', '2026-10-09 09:00:00', '2026-10-19 09:00:00', '2026-10-23 09:00:00'],
          $occ('2026-10-05 09:00:00', '2026-10-05 10:00:00', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,FR', [], '2026-10-01 00:00:00', '2026-10-31 23:59:59'),
          'every second week on Monday and Friday');
assert_eq(4, count($occ('2026-10-05 09:00:00', '2026-10-05 10:00:00', 'FREQ=DAILY;UNTIL=20261008', [], '2026-10-01 00:00:00', '2026-10-31 23:59:59')),
          'UNTIL is inclusive');
$t0 = microtime(true);
assert_eq(['2030-06-01 08:00:00', '2030-06-02 08:00:00'],
          $occ('2020-01-01 08:00:00', '2020-01-01 09:00:00', 'FREQ=DAILY', [], '2030-06-01 00:00:00', '2030-06-02 23:59:59'),
          'a window years after the series start');
assert_true(microtime(true) - $t0 < 0.5, 'far windows jump ahead instead of walking every occurrence');
assert_eq('FREQ=DAILY;UNTIL=20261007', Recurrence::untilBefore('FREQ=DAILY;COUNT=10', '2026-10-08'), 'end a series the day before an occurrence');
assert_eq('FREQ=DAILY;COUNT=7', Recurrence::remainder('FREQ=DAILY;COUNT=10', '2026-10-05 09:00:00', '2026-10-08 09:00:00'), 'COUNT left over after a split');
assert_true(Recurrence::endsBefore('FREQ=DAILY;UNTIL=20261010', '2026-10-11') && !Recurrence::endsBefore('FREQ=DAILY;UNTIL=20261010', '2026-10-10'), 'endsBefore');
assert_eq('FREQ=WEEKLY;BYDAY=MO', Recurrence::normalise('RRULE:freq=weekly;byday=mo'), 'rules are normalised');
assert_eq('Weekly on Monday, Wednesday', Recurrence::describe('FREQ=WEEKLY;BYDAY=MO,WE', '2026-10-05 09:00:00'), 'describe weekly');
assert_eq('Monthly on the second Tuesday · 3 times', Recurrence::describe('FREQ=MONTHLY;BYDAY=2TU;COUNT=3', '2026-10-13 09:00:00'), 'describe monthly');
expect_user_error(fn() => Recurrence::normalise('FREQ=SECONDLY'), 'unsupported rules are refused');
assert_eq(['2026-10-19' => true], Recurrence::exdates('["2026-10-19","bad"]'), 'invalid exdates are dropped');
assert_eq('2026-10-08 09:00:00', Recurrence::nextStart('2026-10-05 09:00:00', '2026-10-05 10:00:00', 'FREQ=DAILY', [], '2026-10-07 09:30:00', '2026-10-20 00:00:00')['start'],
          'next occurrence after a moment');

// ================================================================= calendars

$admin = repo(ADMIN, 'admin');
$mine = $admin->insertCalendar('personal', 'CalTest mine', '#7a1f30', null);
$team = $admin->insertCalendar('team', 'CalTest team', '#2f7457', null);
$member = repo(MEMBER, 'member');
$admin->allCalendars(true);

assert_true($admin->calendar($mine)['perms']['manage'], 'owner manages their calendar');
assert_eq(null, $member->calendar($mine), 'a personal calendar is private to its owner');
$tp = $member->calendar($team)['perms'];
assert_true($tp['see'] && $tp['add'] && !$tp['manage'], 'members see and add to team calendars but do not manage them');
assert_true(repo(ADMIN2, 'admin')->calendar($team)['perms']['manage'], 'admins manage team calendars');
assert_true(!repo(MEMBER, 'viewer')->calendar($team)['perms']['add'], 'viewers cannot add to team calendars');

$memberOwn = $member->defaultCalendarId();
$c = $member->calendar($memberOwn);
assert_true($c['calendar_type'] === 'personal' && (int)$c['owner_id'] === MEMBER, 'a personal calendar is created on first use');
assert_eq($memberOwn, repo(MEMBER, 'member')->defaultCalendarId(), 'and reused afterwards');

// ==================================================== create, read, validate

$day = date('Y-m-d', strtotime($nowLocal . ' +7 days'));
$ev = $admin->create(['calendar_id' => $mine, 'title' => '  Site   visit ✓ 🏗️ ', 'location' => 'Rivonia', 'description' => "Line 1\r\nLine 2",
                      'start' => "$day 10:00", 'end' => "$day 11:00"], false);
$row = $admin->eventRow($ev);
assert_eq('Site visit ✓ 🏗️', $row['title'], 'emoji and ✓ titles are stored (utf8mb4), whitespace collapsed');
assert_eq("Line 1\nLine 2", $row['description'], 'line endings normalised');
assert_eq("$day 10:00:00", $row['start_datetime'], 'start stored as wall-clock time');
$p = $DB->query("SELECT user_id, role, response FROM calendar_event_participants WHERE event_id = $ev")->fetchAll(PDO::FETCH_ASSOC);
assert_eq([['user_id' => ADMIN, 'role' => 'organizer', 'response' => 'accepted']], array_map(fn($r) => ['user_id' => (int)$r['user_id']] + $r, $p),
          'the creator is the organiser');

$admin->update($ev, ['start' => "$day 14:00"], 'all', null, false);
$row = $admin->eventRow($ev);
assert_eq(["$day 14:00:00", "$day 15:00:00"], [$row['start_datetime'], $row['end_datetime']], 'moving the start keeps the length');
$admin->update($ev, ['all_day' => true], 'all', null, false);
$row = $admin->eventRow($ev);
assert_eq(["$day 00:00:00", "$day 23:59:59", 1], [$row['start_datetime'], $row['end_datetime'], (int)$row['all_day']], 'switching to all day');
$admin->update($ev, ['all_day' => false, 'start' => "$day 10:00", 'end' => "$day 11:00"], 'all', null, false);

expect_user_error(fn() => $admin->create(['calendar_id' => $mine, 'title' => ' ', 'start' => "$day 10:00"], false), 'a title is required');
expect_user_error(fn() => $admin->create(['calendar_id' => $mine, 'title' => 'x', 'start' => "$day 10:00", 'end' => "$day 09:00"], false), 'end before start is refused');
expect_user_error(fn() => $admin->create(['calendar_id' => $mine, 'title' => 'x', 'start' => '2026-02-30 10:00'], false), 'impossible dates are refused');
expect_user_error(fn() => $admin->create(['calendar_id' => $mine, 'title' => 'x', 'start' => '2026-01-01', 'end' => '2027-06-01', 'all_day' => 1], false), 'events longer than 400 days are refused');
expect_user_error(fn() => $admin->create(['calendar_id' => $mine, 'title' => 'x', 'start' => "$day 10:00", 'color' => 'red;'], false), 'colours must be hex');
expect_user_error(fn() => $member->create(['calendar_id' => $mine, 'title' => 'x', 'start' => "$day 10:00"], false), 'cannot add to someone else\'s calendar');
expect_user_error(fn() => $member->update($ev, ['title' => 'hijack'], 'all', null, false), 'cannot change an event you cannot see');
expect_user_error(fn() => $member->detail($ev), 'cannot open an event you cannot see');

$from = "$day 00:00:00";
$to = "$day 23:59:59";
assert_true(in_array($ev, feed_ids($admin->feed($from, $to)), true), 'owner sees the event');
assert_true(!in_array($ev, feed_ids($member->feed($from, $to)), true), 'others do not see a private calendar');

// ================================================================== sharing

$DB->prepare("UPDATE calendars SET sharing_json = ? WHERE id = ?")->execute([json_encode([['user_id' => MEMBER, 'role' => 'view']]), $mine]);
$member = repo(MEMBER, 'member');
$f = feed_for($member->feed($from, $to), $ev);
assert_eq(1, count($f), 'shared (view): the event is visible');
assert_true(!$f[0]['editable'], 'shared (view): read only');
assert_true(!$member->calendar($mine)['perms']['add'], 'shared (view): cannot add');
$DB->prepare("UPDATE calendars SET sharing_json = ? WHERE id = ?")->execute([json_encode([['user_id' => MEMBER, 'role' => 'edit']]), $mine]);
$member = repo(MEMBER, 'member');
assert_true($member->calendar($mine)['perms']['add'], 'shared (edit): can add events');
$DB->prepare("UPDATE calendars SET sharing_json = NULL WHERE id = ?")->execute([$mine]);
$member = repo(MEMBER, 'member');

// ================================================================ visibility

$busyEv = $admin->create(['calendar_id' => $team, 'title' => 'Board meeting', 'location' => 'HQ', 'visibility' => 'busy',
                          'start' => "$day 08:00", 'end' => "$day 09:00"], false);
$privEv = $admin->create(['calendar_id' => $team, 'title' => 'Doctor', 'visibility' => 'private',
                          'start' => "$day 12:00", 'end' => "$day 12:30"], false);
$openEv = $admin->create(['calendar_id' => $team, 'title' => 'Toolbox talk', 'start' => "$day 07:00", 'end' => "$day 07:30"], false);
$mf = $member->feed($from, $to);
$b = feed_for($mf, $busyEv)[0] ?? null;
assert_true($b && $b['title'] === 'Busy' && $b['location'] === null && $b['busy'], '"busy" events show as Busy without details');
assert_eq('Busy', $member->detail($busyEv)['title'], 'and their detail is hidden too');
assert_eq([], feed_for($mf, $privEv), '"private" events are hidden from others');
$o = feed_for($mf, $openEv)[0] ?? null;
assert_true($o && $o['title'] === 'Toolbox talk' && $o['editable'], 'team calendar events are visible and editable by members');
assert_eq('Doctor', feed_for($admin->feed($from, $to), $privEv)[0]['title'] ?? null, 'the organiser still sees their private event');
$times = array_column($admin->feed($from, $to), 'start');
$sorted = $times;
sort($sorted);
assert_eq($sorted, $times, 'the feed is chronological');

// ======================================================= invitations & RSVP

$n0 = $maxId('notifications');
$inv = $admin->create(['calendar_id' => $mine, 'title' => 'Client walkthrough', 'start' => "$day 15:00", 'end' => "$day 16:00",
                       'participants' => [['user_id' => MEMBER], ['user_id' => 999999], ['user_id' => ADMIN]],
                       'reminders' => [['minutes_before' => 30, 'channel' => 'in_app']]]);
$parts = $DB->query("SELECT user_id, role FROM calendar_event_participants WHERE event_id = $inv ORDER BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_eq([ADMIN => 'organizer', MEMBER => 'required'], $parts, 'unknown users are dropped; the organiser is not invited twice');
$sent = notifications_for($DB, MEMBER, 'event_invite', $n0);
assert_eq(1, count($sent), 'the invitee gets an invitation');
assert_eq("/calendar/?event=$inv&date=$day", $sent[0]['url'], 'the invitation opens the event');
$rem = $DB->query("SELECT user_id, minutes_before FROM calendar_event_reminders WHERE event_id = $inv ORDER BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_eq([ADMIN => 30, MEMBER => 30], $rem, 'every attendee gets the event\'s reminders');

$mi = feed_for($member->feed($from, $to), $inv)[0] ?? null;
assert_true($mi && $mi['invited'] && $mi['via_invite'] && !$mi['editable'], 'the invitee sees it (via the invitation), read only');
assert_eq('pending', $mi['response'], 'no reply yet');
$member->respond($inv, 'accepted');
$r = notifications_for($DB, ADMIN, 'event_response', $n0);
assert_eq(1, count($r), 'the organiser hears about the reply');
assert_true(str_contains($r[0]['title'], 'accepted'), 'reply text');
$member->respond($inv, 'accepted');
assert_eq(1, count(notifications_for($DB, ADMIN, 'event_response', $n0)), 'repeating the same reply does not notify again');
expect_user_error(fn() => $admin->respond($inv, 'declined'), 'the organiser cannot reply to their own event');
expect_user_error(fn() => repo(ADMIN2, 'admin')->respond($inv, 'accepted'), 'only invitees can reply');
expect_user_error(fn() => $member->respond($inv, 'maybe-later'), 'unknown replies are refused');

$busy = $admin->busy([MEMBER], $from, $to);
assert_true((bool)array_filter($busy, fn($x) => $x['start'] === "$day 15:00:00"), 'free/busy shows the accepted invitation');
$member->respond($inv, 'declined');
assert_eq([], feed_for($member->feed($from, $to), $inv), 'a declined invitation leaves the invitee\'s calendar');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM calendar_event_reminders WHERE event_id = $inv AND user_id = " . MEMBER)->fetchColumn(),
          'declining removes the invitee\'s reminders');
assert_true(!array_filter($admin->busy([MEMBER], $from, $to), fn($x) => $x['start'] === "$day 15:00:00"), 'declined events are not busy time');
$member->respond($inv, 'tentative');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM calendar_event_reminders WHERE event_id = $inv AND user_id = " . MEMBER)->fetchColumn(),
          'replying maybe restores the reminders');

$n1 = $maxId('notifications');
$admin->update($inv, ['start' => "$day 16:00", 'end' => "$day 17:00"]);
assert_eq(1, count(notifications_for($DB, MEMBER, 'event_updated', $n1)), 'attendees hear about a new time');
$admin->update($inv, ['title' => 'Client walkthrough (v2)']);
assert_eq(1, count(notifications_for($DB, MEMBER, 'event_updated', $n1)), 'a title change alone does not notify');
$admin->update($inv, ['participants' => []]);
assert_eq(1, count(notifications_for($DB, MEMBER, 'event_cancelled', $n1)), 'removing someone tells them');

// ======================================================== repeating events

$mon = date('Y-m-d', strtotime('monday next week', strtotime($nowLocal . ' +14 days')));
$d = fn(int $weeks, string $time = '09:00:00') => date('Y-m-d', strtotime("$mon +$weeks weeks")) . ' ' . $time;
$series = $admin->create(['calendar_id' => $mine, 'title' => 'Weekly site meeting', 'start' => $d(0, '09:00'), 'end' => $d(0, '10:00'),
                          'recurrence' => 'FREQ=WEEKLY;COUNT=6', 'participants' => [MEMBER],
                          'reminders' => [['minutes_before' => 10, 'channel' => 'in_app']]], false);
$wf = $mon . ' 00:00:00';
$wt = date('Y-m-d', strtotime("$mon +8 weeks")) . ' 23:59:59';
$titles = fn() => array_map(fn($e) => $e['start'] . ' ' . $e['title'], array_values(array_filter($admin->feed($wf, $wt, [$mine]), fn($e) => $e['calendar_id'] === $mine && str_contains($e['title'], 'site meeting'))));
assert_eq(6, count($titles()), 'six weekly occurrences');
$one = feed_for($admin->feed($wf, $wt), $series)[1];
assert_eq($d(1), $one['occ'], 'occurrences carry their own start');
assert_true($one['key'] !== $series . '' && $one['recurring'], 'and a key of their own');

$detached = $admin->update($series, ['title' => 'Weekly site meeting (moved)', 'start' => $d(1, '11:00'), 'end' => $d(1, '12:00')], 'this', $d(1), false);
assert_true($detached !== $series, 'changing one occurrence stores it as its own event');
$dr = $admin->eventRow($detached);
assert_eq([$series, $d(1)], [(int)$dr['series_id'], $dr['recurrence_id']], 'which remembers its series and occurrence');
assert_true(isset(Recurrence::exdates($admin->eventRow($series)['exdates_json'])[substr($d(1), 0, 10)]), 'the series skips that date');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM calendar_event_participants WHERE event_id = $detached")->fetchColumn(), 'attendees copied to it');
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM calendar_event_reminders WHERE event_id = $detached")->fetchColumn(), 'reminders copied to it');
$t = $titles();
assert_eq(6, count($t), 'still six meetings');
assert_eq($d(1, '11:00:00') . ' Weekly site meeting (moved)', $t[1], 'the changed one in its new place');

$following = $admin->update($series, ['start' => $d(3, '13:00'), 'end' => $d(3, '14:00')], 'following', $d(3), false);
assert_eq('FREQ=WEEKLY;UNTIL=' . date('Ymd', strtotime($d(3) . ' -1 day')), $admin->eventRow($series)['recurrence'], '"this and following" ends the old series the day before');
assert_eq('FREQ=WEEKLY;COUNT=3', $admin->eventRow($following)['recurrence'], 'and the new series keeps the remaining count');
$t = $titles();
assert_eq(6, count($t), 'still six meetings after the split');
assert_eq([$d(3, '13:00:00'), $d(4, '13:00:00'), $d(5, '13:00:00')], array_map(fn($x) => substr($x, 0, 19), array_slice($t, 3)), 'later ones at the new time');

$admin->update($following, ['start' => $d(4, '14:00'), 'end' => $d(4, '15:00')], 'all', $d(4, '13:00:00'), false);
assert_eq($d(3, '14:00:00'), $admin->eventRow($following)['start_datetime'], 'editing "all" from an occurrence shifts the whole series by the same amount');

$admin->delete($following, 'this', $d(4, '14:00:00'), false);
assert_eq(5, count($titles()), 'deleting one occurrence');
$admin->delete($series, 'following', $d(2), false);
$t = $titles();
assert_eq([$d(0), $d(1, '11:00:00'), $d(3, '14:00:00'), $d(5, '14:00:00')], array_map(fn($x) => substr($x, 0, 19), $t), 'deleting "this and following" keeps earlier ones and the changed occurrence');
$admin->delete($series, 'all', null, false);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM calendar_events WHERE id IN ($series, $detached)")->fetchColumn(), 'deleting the series removes its changed occurrences');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM calendar_event_participants WHERE event_id IN ($series, $detached)")->fetchColumn(), 'and their attendees');
assert_eq(2, count($titles()), 'the split-off series is separate');
expect_user_error(fn() => $member->delete($following, 'all', null, false), 'invitees cannot delete an event');

// ================================================================= reminders

$now = CalendarRepo::nowLocal($DB, C);
$at = fn(int $minutes) => date('Y-m-d H:i:s', strtotime($now) + $minutes * 60);
$remFor = fn(int $eventId, int $userId) => $DB->query("SELECT * FROM calendar_event_reminders WHERE event_id = $eventId AND user_id = $userId")->fetch(PDO::FETCH_ASSOC);

$soon = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest soon', 'start' => $at(10), 'end' => $at(40),
                        'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);
$later = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest later', 'start' => $at(60), 'end' => $at(90),
                         'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);
$past = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest past', 'start' => $at(-30), 'end' => $at(-10),
                        'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);
$snoozed = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest snoozed', 'start' => $at(5), 'end' => $at(30),
                           'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);
$DB->prepare("UPDATE calendar_event_reminders SET snoozed_until = ? WHERE event_id = ?")->execute([$at(3), $snoozed]);
$declined = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest declined', 'start' => $at(10), 'end' => $at(40),
                            'participants' => [MEMBER], 'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);
$member->respond($declined, 'declined');
$DB->exec("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel) VALUES ($declined, " . MEMBER . ", 15, 'in_app')");
$yesterday = date('Y-m-d H:i:s', strtotime($at(10) . ' -1 day'));
$daily = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest daily', 'start' => $yesterday, 'end' => date('Y-m-d H:i:s', strtotime($yesterday) + 1800),
                         'recurrence' => 'FREQ=DAILY;COUNT=5', 'reminders' => [['minutes_before' => 15, 'channel' => 'in_app']]], false);

$n2 = $maxId('notifications');
fw_calendar_send_due_reminders($DB);
$got = array_column(notifications_for($DB, ADMIN, 'calendar_reminder', $n2), null, 'title');
assert_true(isset($got['CalTest soon']), 'a reminder whose time has come (in company time) is sent');
assert_eq("/calendar/?event=$soon&date=" . substr($at(10), 0, 10), $got['CalTest soon']['url'], 'it opens the event');
assert_true(str_contains($got['CalTest soon']['message'], ' at ' . substr($at(10), 11, 5)), 'with the start time: ' . $got['CalTest soon']['message']);
assert_true(!empty($remFor($soon, ADMIN)['sent_at']), 'and is marked sent');
assert_true(!isset($got['CalTest later']) && empty($remFor($later, ADMIN)['sent_at']), 'a reminder not yet due waits');
assert_true(!isset($got['CalTest past']) && !empty($remFor($past, ADMIN)['sent_at']), 'a reminder for an event that already started is dropped');
assert_true(!isset($got['CalTest snoozed']) && empty($remFor($snoozed, ADMIN)['sent_at']), 'a snoozed reminder waits');
assert_true(isset($got['CalTest declined']), 'the organiser of the declined event is reminded');
assert_eq([], array_filter(notifications_for($DB, MEMBER, 'calendar_reminder', $n2), fn($x) => $x['title'] === 'CalTest declined'), 'people who declined are not');
assert_true(isset($got['CalTest daily']), 'a repeating event reminds before today\'s occurrence');
assert_eq($at(10), $remFor($daily, ADMIN)['last_occurrence'], 'and remembers which occurrence it reminded about');
fw_calendar_send_due_reminders($DB);
assert_eq(count($got), count(notifications_for($DB, ADMIN, 'calendar_reminder', $n2)), 'running again sends nothing twice');
assert_eq('Tomorrow (all day)', fw_calendar_reminder_when('2026-10-17 00:00:00', true, '2026-10-16 08:00:00'), 'reminder wording (all day)');
assert_eq('Today at 14:30', fw_calendar_reminder_when('2026-10-16 14:30:00', false, '2026-10-16 08:00:00'), 'reminder wording (today)');

// ========================================================== live Flowwork data

$customer = make_customer($DB, 'CalTest Customer');
$soonDue = date('Y-m-d', strtotime($nowLocal . ' +3 days'));
$sentInv = make_invoice($DB, $customer, [[1, 100, 15]], ['status' => 'sent', 'due_date' => $soonDue]);
$draftInv = make_invoice($DB, $customer, [[1, 100, 15]], ['status' => 'draft', 'due_date' => $soonDue]);
$paidInv = make_invoice($DB, $customer, [[1, 100, 15]], ['status' => 'paid', 'due_date' => $soonDue]);
$all = array_fill_keys(array_keys(CalendarSettings::SOURCES), true);
$keys = fn(CalendarRepo $r, string $f, string $t) => array_column((new CalendarSources($DB, $r))->items($f, $t, $all), null, 'key');

$k = $keys($admin, $soonDue, $soonDue);
assert_true(isset($k["invoice:$sentInv"]), 'unpaid invoices show on their due date');
assert_eq("/qi/invoice_view.php?id=$sentInv", $k["invoice:$sentInv"]['url'] ?? null, 'linking to the invoice');
assert_true(!isset($k["invoice:$draftInv"]) && !isset($k["invoice:$paidInv"]), 'drafts and paid invoices do not');
company_setting($DB, 'calendar_sync_invoice_due', '0');
assert_true(!isset($keys($admin, $soonDue, $soonDue)["invoice:$sentInv"]), 'the company can switch a source off');
company_setting($DB, 'calendar_sync_invoice_due', null);
$k = array_column((new CalendarSources($DB, $admin))->items($soonDue, $soonDue, ['invoices' => false] + $all), null, 'key');
assert_true(!isset($k["invoice:$sentInv"]), 'and each user can hide one');

company_setting($DB, 'calendar_sync_payroll_dates', '1');
assert_true(CalendarSettings::availableSources($DB, C, 'admin')['payroll'], 'pay days are available to admins');
assert_true(!CalendarSettings::availableSources($DB, C, 'member')['payroll'], 'but never to members');
company_setting($DB, 'calendar_sync_payroll_dates', null);
assert_true(!CalendarSettings::availableSources($DB, C, 'admin')['payroll'], 'pay days are off by default');

// Projects and tasks: everything is set up before anyone looks, because the
// engine remembers for the rest of the request which projects a user can open.
$pTo = date('Y-m-d', strtotime("$soonDue +5 days"));
$past = date('Y-m-d', strtotime($nowLocal . ' -2 days'));
$newProject = function (string $name, array $f = []) use ($DB, $soonDue, $pTo): int {
    $DB->prepare("INSERT INTO projects (company_id, name, status, visibility, start_date, end_date, owner_id, created_by, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([C, $name, $f['status'] ?? 'active', $f['visibility'] ?? 'members', $soonDue, $pTo, ADMIN, ADMIN]);
    return (int)$DB->lastInsertId();
};
$newBoard = function (int $projectId, string $title, int $archived = 0) use ($DB): int {
    $DB->prepare("INSERT INTO project_boards (company_id, project_id, title, archived, created_at) VALUES (?, ?, ?, ?, NOW())")
       ->execute([C, $projectId, $title, $archived]);
    return (int)$DB->lastInsertId();
};
// An item as the engine leaves it: assigned_to is the first person, board_item_people lists everyone.
$newItem = function (int $boardId, string $title, array $people, array $f = []) use ($DB): int {
    $DB->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, assigned_to, due_date, start_date, end_date, progress, archived, created_by, created_at)
                  VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$boardId, C, $title, $people[0] ?? null, $f['due'] ?? null, $f['start'] ?? null, $f['end'] ?? null, $f['progress'] ?? 0, $f['archived'] ?? 0, ADMIN]);
    $id = (int)$DB->lastInsertId();
    foreach ($people as $uid) {
        $DB->prepare("INSERT INTO board_item_people (item_id, user_id, company_id, board_id) VALUES (?, ?, ?, ?)")->execute([$id, $uid, C, $boardId]);
    }
    return $id;
};
$project = $newProject('CalTest Project');
$DB->prepare("INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at) VALUES (?, ?, ?, 'member', 0, NOW())")
   ->execute([C, $project, MEMBER]);
$shared = $newProject('CalTest Shared', ['visibility' => 'company']);
$closed = $newProject('CalTest Closed', ['status' => 'completed']);
$DB->prepare("INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at) VALUES (?, ?, ?, 'member', 1, NOW())")
   ->execute([C, $closed, MEMBER]);
$crewProject = $newProject('CalTest Crew project');
$board = $newBoard($project, 'CalTest Board');
$oldBoard = $newBoard($project, 'CalTest Old board', 1);
$closedBoard = $newBoard($closed, 'CalTest Closed board');
$crewBoard = $newBoard($crewProject, 'CalTest Crew board');
$officeBoard = $newBoard($crewProject, 'CalTest Office board');
$DB->prepare("INSERT INTO board_members (board_id, user_id, role) VALUES (?, ?, 'editor')")->execute([$crewBoard, ADMIN2]);
$task = $newItem($board, 'CalTest Pour slab', [MEMBER], ['due' => $soonDue]);
$pair = $newItem($board, 'CalTest Order steel', [ADMIN, MEMBER], ['due' => $soonDue]);
$span = $newItem($board, 'CalTest Formwork', [MEMBER], ['start' => $soonDue, 'end' => $pTo, 'due' => $pTo]);
$doneTask = $newItem($board, 'CalTest Strip site', [MEMBER], ['due' => $soonDue, 'progress' => 100]);
$lateTask = $newItem($board, 'CalTest Late delivery', [MEMBER], ['due' => $past]);
$unassigned = $newItem($board, 'CalTest Unassigned', [], ['due' => $soonDue]);
$gone = $newItem($board, 'CalTest Archived task', [MEMBER], ['due' => $soonDue, 'archived' => 1]);
$oldTask = $newItem($oldBoard, 'CalTest Old board task', [MEMBER], ['due' => $soonDue]);
$closedTask = $newItem($closedBoard, 'CalTest Closed task', [MEMBER], ['due' => $soonDue]);
$crewTask = $newItem($crewBoard, 'CalTest Crew task', [ADMIN2], ['due' => $soonDue]);
$officeTask = $newItem($officeBoard, 'CalTest Office task', [MEMBER], ['due' => $soonDue]);
$member = repo(MEMBER, 'member');
$crew = repo(ADMIN2, 'member'); // a member of one board of the crew project, nothing else

$k = $keys($admin, $soonDue, $pTo);
assert_true(isset($k["project-start:$project"], $k["project-due:$project"]), 'project start and due dates show');
assert_eq("/projects/view.php?project_id=$project", $k["project-due:$project"]['url'] ?? null, 'linking to the project');
assert_true(!isset($k["project-due:$closed"]), 'completed projects do not');
$k = $keys($member, $soonDue, $pTo);
assert_true(isset($k["project-due:$project"]), 'project members see them');
assert_true(isset($k["project-due:$shared"]), 'and everyone sees a project shared with the company');
assert_true(!isset($k["project-due:$crewProject"]), 'but not projects they cannot open');
$k = $keys($crew, $soonDue, $pTo);
assert_true(isset($k["project-due:$crewProject"]), 'a board member sees the project of their board');
assert_true(!isset($k["project-due:$project"]), 'only people who can open a project see its dates');

$k = $keys($member, $soonDue, $soonDue);
assert_true(isset($k["item:$task"]), 'tasks I am on show on their due date');
assert_eq("/projects/board.php?board_id=$board&item=$task", $k["item:$task"]['url'] ?? null, 'opening the item on its board');
assert_true(isset($k["item:$pair"]), 'also when I am not the first person on the item');
assert_eq('done', $k["item:$doneTask"]['status'] ?? null, 'finished tasks show as done');
assert_true(!isset($k["item:$unassigned"]), 'tasks I am not on do not show');
assert_true(!isset($k["item:$gone"]) && !isset($k["item:$oldTask"]), 'nor archived tasks or tasks on archived boards');
assert_true(!isset($k["item:$closedTask"]), 'nor tasks of completed projects');
assert_true(!isset($k["item:$officeTask"]), 'nor tasks on projects I cannot open');
$k = $keys($admin, $soonDue, $soonDue);
assert_true(isset($k["item:$pair"]) && !isset($k["item:$task"]), 'everyone sees the tasks they are on');
$k = $keys($crew, $soonDue, $soonDue);
assert_true(isset($k["item:$crewTask"]), 'board members see the tasks they are on');
$mid = date('Y-m-d', strtotime("$soonDue +2 days"));
$k = $keys($member, $mid, $mid);
assert_eq([$soonDue, $pTo], [$k["item:$span"]['start'] ?? null, $k["item:$span"]['end'] ?? null], 'a task with a timeline spans it, ending on its due date');
$k = $keys($member, $past, $past);
assert_eq('overdue', $k["item:$lateTask"]['status'] ?? null, 'open tasks past their due date are overdue');

// ============================================== copies written by the old syncs

$DB->prepare("INSERT INTO calendar_events (company_id, calendar_id, title, start_datetime, end_datetime, all_day, created_by, created_at)
              VALUES (?, ?, 'Invoice due (old copy)', ?, ?, 1, ?, NOW())")->execute([C, $team, "$soonDue 00:00:00", "$soonDue 23:59:59", ADMIN]);
$mirror = (int)$DB->lastInsertId();
$DB->exec("INSERT INTO calendar_event_links (event_id, linked_type, linked_id) VALUES ($mirror, 'invoice', $sentInv)");
$chase = $admin->create(['calendar_id' => $team, 'title' => 'Chase payment', 'start' => "$soonDue 09:00", 'end' => "$soonDue 09:15",
                         'links' => [['type' => 'invoice', 'id' => $sentInv]]], false);
$ids = feed_ids($admin->feed("$soonDue 00:00:00", "$soonDue 23:59:59"));
assert_true(!in_array($mirror, $ids, true), 'old sync copies are hidden (the live item replaces them)');
assert_true(in_array($chase, $ids, true), 'events people linked to the invoice are shown');
assert_eq([], $admin->search('old copy'), 'and old copies are not searchable');
(new CalendarHook($DB))->handleInvoiceEvent(C, $sentInv, 'INV', $soonDue, ADMIN);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM calendar_events WHERE id = $mirror")->fetchColumn(), 'saving the invoice removes the old copy');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM calendar_events WHERE id = $chase")->fetchColumn(), 'but never an event a person made');
$lk = (new CalendarLinks($DB, $admin))->forEvent($chase);
assert_eq("/qi/invoice_view.php?id=$sentInv", $lk[0]['url'] ?? null, 'the event links back to the invoice');
assert_true(!(new CalendarLinks($DB, $admin))->canLink('invoice', 99999999), 'records that do not exist cannot be linked');
assert_true(!(new CalendarLinks($DB, repo(MEMBER, 'member')))->canLink('pay_run', 1), 'members cannot link pay runs');

// ============================================================ CRM follow-ups

$due = date('Y-m-d', strtotime($nowLocal . ' +2 days')) . 'T10:30';
$fu = crm_create_followup($DB, C, ADMIN, 'account', $customer, 'Call back about the quote', $due, 30, ['in_app', 'email']);
$fr = $admin->eventRow($fu);
assert_true($fr['calendar_type'] === 'personal' && (int)$fr['calendar_owner_id'] === ADMIN, 'a follow-up goes on the user\'s own calendar');
assert_eq(str_replace('T', ' ', $due) . ':00', $fr['start_datetime'], 'at the chosen time');
assert_eq('crm_account', $DB->query("SELECT linked_type FROM calendar_event_links WHERE event_id = $fu")->fetchColumn(), 'linked to the account');
assert_eq(['email', 'in_app'], $DB->query("SELECT CAST(channel AS CHAR) c FROM calendar_event_reminders WHERE event_id = $fu ORDER BY c")->fetchAll(PDO::FETCH_COLUMN), 'with a reminder per channel');
$up = CalendarLink::upcomingFor($DB, C, ADMIN, 'admin', 'account', $customer);
assert_eq([$fu], array_column($up, 'id'), 'the account lists its upcoming follow-ups');
assert_eq('/calendar/?event=' . $fu . '&date=' . substr($due, 0, 10), $up[0]['url'], 'each opening the calendar');
assert_eq([], CalendarLink::upcomingFor($DB, C, MEMBER, 'member', 'account', $customer), 'colleagues do not see someone\'s private follow-up');
try {
    crm_create_followup($DB, C, ADMIN, 'account', $customer, 'Bad', 'tomorrow-ish', 30);
    fail('ASSERT FAILED: a bad follow-up date is refused');
} catch (Exception $e) {
    assert_true(!($e instanceof CalendarUserError) && $e->getMessage() !== '', 'a bad follow-up date is refused with a message CRM can show');
}

// ========================================================= project timeline

$meet = $admin->create(['calendar_id' => $team, 'title' => 'CalTest site handover', 'start' => "$soonDue 11:00", 'end' => "$soonDue 12:00",
                        'links' => [['type' => 'project', 'id' => $project]]], false);
$taskMeet = $admin->create(['calendar_id' => $mine, 'title' => 'CalTest pour inspection', 'start' => "$soonDue 13:00", 'end' => "$soonDue 13:30",
                            'links' => [['type' => 'item', 'id' => $task]]], false);
$boardMeet = $admin->create(['calendar_id' => $team, 'title' => 'CalTest board review', 'start' => "$soonDue 15:00", 'end' => "$soonDue 15:30",
                             'links' => [['type' => 'board', 'id' => $board]]], false);
$tl = CalendarLink::projectTimeline($DB, C, ADMIN, 'admin', $project, $soonDue, $pTo);
assert_eq([$meet, $taskMeet, $boardMeet], feed_ids($tl['events']), 'the project calendar has events linked to it, its boards and its tasks');
$itemKeys = array_column($tl['items'], 'key');
sort($itemKeys);
$want = ["item:$task", "item:$pair", "item:$span", "item:$doneTask", "item:$unassigned", "project-due:$project", "project-start:$project"];
sort($want);
assert_eq($want, $itemKeys, 'plus its start, due and task dates (live tasks on live boards only)');
$byKey = array_column($tl['items'], null, 'key');
assert_eq("/projects/view.php?project_id=$project", $byKey["project-due:$project"]['url'] ?? null, 'project dates open the project');
assert_eq("/projects/board.php?board_id=$board&item=$span", $byKey["item:$span"]['url'] ?? null, 'task dates open the item');
assert_eq([$pTo, 'tasks', 'CalTest Board'], [$byKey["item:$span"]['start'] ?? null, $byKey["item:$span"]['source'] ?? null, $byKey["item:$span"]['sub'] ?? null], 'on its due date, with its board');
$tl = CalendarLink::projectTimeline($DB, C, MEMBER, 'member', $project, $soonDue, $pTo);
assert_eq([$meet, $boardMeet], feed_ids($tl['events']), 'but only the events each person may see');
$tl = CalendarLink::projectTimeline($DB, C, ADMIN2, 'member', $crewProject, $soonDue, $pTo);
assert_eq(["item:$crewTask"], array_values(array_filter(array_column($tl['items'], 'key'), fn($k) => str_starts_with($k, 'item:'))),
          'a board member sees the tasks of their own boards only');
assert_eq(['events' => [], 'items' => []], CalendarLink::projectTimeline($DB, C, MEMBER, 'member', $crewProject, $soonDue, $pTo),
          'and nothing of a project they cannot open');

// ===================================================== links to projects

$links = fn(CalendarRepo $r) => new CalendarLinks($DB, $r);
$d = $links($admin)->describe('board', $board);
assert_eq(['CalTest Board', 'CalTest Project', "/projects/board.php?board_id=$board", 'Board'], [$d['label'] ?? null, $d['sub'] ?? null, $d['url'] ?? null, $d['kind'] ?? null],
          'an event can be linked to a board, opening the board');
assert_eq("/projects/board.php?board_id=$board&item=$task", $links($admin)->describe('item', $task)['url'] ?? null, 'a task link opens the item on its board');
assert_eq("/projects/view.php?project_id=$project", $links($admin)->describe('project', $project)['url'] ?? null, 'a project link opens the project');
assert_eq([['type' => 'board', 'id' => $board]], array_map(fn($l) => ['type' => $l['type'], 'id' => $l['id']], $links($admin)->forEvent($boardMeet)),
          'the board link is kept on the event');
assert_true($links($crew)->canLink('board', $crewBoard) && !$links($crew)->canLink('board', $officeBoard), 'board members can link their board, not the others');
assert_true($links($crew)->canLink('item', $crewTask) && !$links($crew)->canLink('item', $officeTask), 'and the tasks on it');
assert_true($links($member)->canLink('project', $shared) && !$links($member)->canLink('project', $crewProject), 'projects follow who may open them');
$found = fn(CalendarRepo $r, string $q) => array_map(fn($l) => $l['type'] . ':' . $l['id'], $links($r)->search($q));
assert_eq(["item:$unassigned"], $found($member, 'CalTest Unassigned'), 'search finds any task the user can see, not only their own');
assert_eq(["project:$shared"], $found($member, 'CalTest Shared'), 'and projects shared with the company');
assert_eq([], $found($member, 'CalTest Crew'), 'but nothing on projects the user cannot open');
assert_eq([], $found($admin, 'CalTest Archived task'), 'nor archived tasks');
assert_eq([], $found($admin, 'CalTest Old board'), 'nor archived boards or their tasks');
$crewFound = $found($crew, 'CalTest Crew');
sort($crewFound);
assert_eq(["board:$crewBoard", "item:$crewTask", "project:$crewProject"], $crewFound, 'boards are searchable too');

// ====================================================== project calendars

$pcal = $admin->insertCalendar('project', 'CalTest project calendar', '#7a5ab8', null, $project);
assert_true(repo(MEMBER, 'member')->calendar($pcal) !== null, 'a project calendar is seen by the people who can open the project');
assert_true(repo(ADMIN2, 'member')->calendar($pcal) === null, 'and by nobody else');

// ================================================================ iCalendar

$host = 'flowwork.test';
$icsRows = [[
    'id' => 1, 'ics_uid' => null, 'title' => 'Comma, semi; back\\slash', 'description' => "Two\nlines", 'location' => 'Room 1',
    'start_datetime' => '2026-11-05 09:00:00', 'end_datetime' => '2026-11-05 10:00:00', 'all_day' => 0,
    'recurrence' => 'FREQ=WEEKLY;COUNT=3', 'exdates_json' => '["2026-11-12"]', 'created_at' => '2026-10-01 08:00:00', 'updated_at' => null,
], [
    'id' => 2, 'ics_uid' => null, 'title' => str_repeat('Long title ', 12), 'description' => '', 'location' => '',
    'start_datetime' => '2026-11-06 00:00:00', 'end_datetime' => '2026-11-07 23:59:59', 'all_day' => 1,
    'recurrence' => null, 'exdates_json' => null, 'created_at' => '2026-10-01 08:00:00', 'updated_at' => null,
]];
$ics = Ics::build('CalTest', $icsRows, $tz, $host);
assert_true(str_contains($ics, "SUMMARY:Comma\\, semi\\; back\\\\slash"), 'text is escaped');
assert_true(str_contains($ics, 'DTSTART:20261105T070000Z'), 'times are written in UTC');
assert_true(str_contains($ics, 'DTSTART;VALUE=DATE:20261106') && str_contains($ics, 'DTEND;VALUE=DATE:20261108'), 'all-day ends are exclusive dates');
assert_true(str_contains($ics, 'RRULE:FREQ=WEEKLY;COUNT=3') && str_contains($ics, 'EXDATE:20261112T070000Z'), 'repeat rule and skipped dates');
foreach (explode("\r\n", $ics) as $line) assert_true(strlen($line) <= 75, 'lines are folded at 75 octets');
assert_true(str_contains($ics, 'UID:fw-cal-1@' . $host), 'stable UIDs');

$parsed = Ics::parse($ics, $tz);
assert_eq(2, count($parsed['events']), 'what we export we can read back');
$e0 = $parsed['events'][0];
assert_eq(['Comma, semi; back\\slash', "Two\nlines", '2026-11-05 09:00:00', 'FREQ=WEEKLY;COUNT=3', ['2026-11-12']],
          [$e0['title'], $e0['description'], $e0['start'], $e0['recurrence'], $e0['exdates']], 'round trip');
assert_eq(['2026-11-06 00:00:00', '2026-11-07 23:59:59', true], [$parsed['events'][1]['start'], $parsed['events'][1]['end'], $parsed['events'][1]['all_day']], 'all-day round trip');

$foreign = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:a@x\r\nSUMMARY:London call\r\nDTSTART;TZID=Europe/London:20261105T090000\r\nDURATION:PT45M\r\nCLASS:PRIVATE\r\n"
    . "BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nSUMMARY:alarm text\r\nEND:VALARM\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:b@x\r\nSUMMARY:Cancelled thing\r\nSTATUS:CANCELLED\r\nDTSTART:20261105T100000Z\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:c@x\r\nSUMMARY:Stand-up\r\nDTSTART:20261102T070000Z\r\nDTEND:20261102T071500Z\r\nRRULE:FREQ=DAILY;COUNT=5\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:c@x\r\nRECURRENCE-ID:20261104T070000Z\r\nSUMMARY:Stand-up (late)\r\nDTSTART:20261104T080000Z\r\nDTEND:20261104T081500Z\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:d@x\r\nSUMMARY:Fol\r\n ded\r\nDTSTART;VALUE=DATE:20261110\r\nEND:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$p = Ics::parse($foreign, $tz);
$byUid = [];
foreach ($p['events'] as $e) $byUid[$e['uid'] . ($e['recurrence_id'] ? '#' : '')] = $e;
assert_eq(4, count($p['events']), 'cancelled events are skipped');
assert_eq(['2026-11-05 11:00:00', '2026-11-05 11:45:00', 'private'], [$byUid['a@x']['start'], $byUid['a@x']['end'], $byUid['a@x']['visibility']],
          'TZID times converted to company time; DURATION; CLASS:PRIVATE');
assert_eq('London call', $byUid['a@x']['title'], 'alarm properties do not leak into the event');
assert_eq('2026-11-02 09:00:00', $byUid['c@x']['start'], 'UTC times converted to company time');
assert_eq('Folded', $byUid['d@x']['title'], 'folded lines are joined');
assert_eq('2026-11-10 23:59:59', $byUid['d@x']['end'], 'an all-day event without an end lasts one day');

$imp = $admin->insertCalendar('personal', 'CalTest import', '#2f5c84', null);
$admin->allCalendars(true);
assert_eq(['added' => 4, 'updated' => 0, 'skipped' => 0], $admin->importIcs($imp, $p['events']), 'import');
assert_eq(['added' => 0, 'updated' => 4, 'skipped' => 0], $admin->importIcs($imp, $p['events']), 'importing the same file again updates instead of duplicating');
$su = $DB->query("SELECT id, exdates_json FROM calendar_events WHERE calendar_id = $imp AND ics_uid = 'c@x' AND recurrence_id IS NULL")->fetch(PDO::FETCH_ASSOC);
assert_true(isset(Recurrence::exdates($su['exdates_json'])['2026-11-04']), 'a changed occurrence skips its date in the series');
$late = $DB->query("SELECT series_id FROM calendar_events WHERE calendar_id = $imp AND ics_uid = 'c@x' AND recurrence_id IS NOT NULL")->fetchColumn();
assert_eq((int)$su['id'], (int)$late, 'and points at it');
$standups = array_filter($admin->feed('2026-11-02 00:00:00', '2026-11-08 23:59:59', [$imp]), fn($e) => str_starts_with($e['title'], 'Stand-up'));
assert_eq(['2026-11-02 09:00:00', '2026-11-03 09:00:00', '2026-11-04 10:00:00', '2026-11-05 09:00:00', '2026-11-06 09:00:00'],
          array_values(array_column($standups, 'start')), 'imported repeats expand with the changed one in place');
expect_user_error(fn() => $member->importIcs($imp, $p['events']), 'cannot import into someone else\'s calendar');

// ================================================================== search

assert_true(in_array($inv, feed_ids($admin->search('walkthrough (v2')), true), 'search by title');
assert_true(in_array($ev, feed_ids($admin->search('rivonia')), true), 'and by location, any case');
assert_eq([], $member->search('Doctor'), 'search never reveals private events');
assert_eq([], $admin->search('x'), 'one-letter searches return nothing');
$s = $admin->search('Stand-up');
assert_true((bool)$s && $s[0]['start'] >= '2026-11-02', 'repeating events are found at their next occurrence');

// ================================================================= settings

$def = CalendarSettings::load($DB, C, MEMBER);
assert_eq(['week', 1, '08:00', '17:00', 0], [$def['default_view'], $def['week_start'], $def['work_start'], $def['work_end'], $def['default_reminder']],
          'users start from the company defaults');
company_setting($DB, 'calendar_default_view', 'month');
assert_eq('month', CalendarSettings::companyDefaults($DB, C)['default_view'], 'admins change the defaults');
$saved = CalendarSettings::save($DB, C, MEMBER, ['default_view' => 'agenda', 'week_start' => 0, 'work_start' => '07:30', 'work_end' => '16:00',
                                                 'time_24h' => false, 'default_duration' => 30, 'hidden_calendars' => ['12', 'src:invoices', '<b>', 'inv'],
                                                 'sources' => ['quotes' => false]]);
assert_eq(['agenda', 0, '07:30', '16:00', false, 30, ['12', 'src:invoices', 'inv'], false, true],
          [$saved['default_view'], $saved['week_start'], $saved['work_start'], $saved['work_end'], $saved['time_24h'], $saved['default_duration'],
           $saved['hidden_calendars'], $saved['sources']['quotes'], $saved['sources']['invoices']], 'personal settings saved and read back');
expect_user_error(fn() => CalendarSettings::save($DB, C, MEMBER, ['default_view' => 'decade']), 'unknown views are refused');
expect_user_error(fn() => CalendarSettings::save($DB, C, MEMBER, ['work_start' => '18:00', 'work_end' => '09:00']), 'working hours must end after they start');
expect_user_error(fn() => CalendarSettings::save($DB, C, MEMBER, ['default_duration' => 7]), 'durations come from the list');

// ================================================================ misc

$key = 'test:' . bin2hex(random_bytes(6));
assert_eq([0, 0], [CalendarRepo::rateLimit($key, 2, 60), CalendarRepo::rateLimit($key, 2, 60)], 'rate limit allows the first requests');
assert_true(CalendarRepo::rateLimit($key, 2, 60) > 0, 'and then asks to wait');
@unlink(CalendarPaths::storageDir() . '/ratelimit/' . sha1($key) . '.json');
assert_eq('/calendar/?view=month&date=2026-10-21&focus=invoice%3A12', CalendarLink::url('2026-10-21 08:00:00', 'invoice:12'), 'deep link helper');
assert_eq('crm_opportunity', CalendarLink::linkType('opportunity'), 'CRM link names map to stored types');

echo 'calendar: OK (' . $GLOBALS['__assert_count'] . " assertions)\n";
