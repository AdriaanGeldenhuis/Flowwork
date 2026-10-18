<?php
// /calendar/api/calendars.php
//   GET                                         calendars the user can see, with permissions
//   POST {action:'create', name, color, type:'personal'|'team'|'project', description, project_id (project calendars)}
//   POST {action:'update', id, name, color, description}
//   POST {action:'delete', id}                  deletes the calendar and its events
//   POST {action:'share', id, share:[{user_id, role:'view'|'edit'}]}   personal calendars
//   POST {action:'reset_feed', id}              new private subscription link
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    cal_json(['calendars' => cal_calendars_payload($CAL)]);
}

cal_require_post();
cal_rate_limit('cal-calendars', 60, 600);
$in = cal_input();
$action = cal_str($in, 'action', 20);

$color = function () use ($in): string {
    $c = cal_str($in, 'color', 7);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) throw new CalendarUserError('Choose a colour from the list.');
    return strtolower($c);
};
$name = function () use ($in): string {
    $n = trim(preg_replace('/\s+/u', ' ', cal_str($in, 'name', 255)));
    if ($n === '') throw new CalendarUserError('Give the calendar a name.');
    return $n;
};
$managed = function () use ($in, $CAL): array {
    $c = $CAL->calendar((int)($in['id'] ?? 0));
    if (!$c) throw new CalendarUserError('Calendar not found.');
    if (!$c['perms']['manage']) throw new CalendarUserError('Only the owner or an admin can change this calendar.');
    return $c;
};

switch ($action) {
    case 'create':
        $type = in_array($in['type'] ?? '', ['team', 'project'], true) ? $in['type'] : 'personal';
        if ($type !== 'personal' && $CAL_ROLE === 'viewer') throw new CalendarUserError('Viewers cannot create shared calendars.');
        $projectId = null;
        if ($type === 'project') {
            // Seen by the people who can open the project (CalendarRepo::calendarPerms).
            $projectId = (int)($in['project_id'] ?? 0);
            if ($projectId <= 0 || !CalendarRepo::projectsAvailable() || !ProjectLink::belongs($DB, $CAL_COMPANY_ID, $projectId)
                || !ProjectLink::canSeeProject($DB, $CAL_COMPANY_ID, $CAL_USER_ID, $CAL_ROLE, $projectId)) {
                throw new CalendarUserError('Project not found.');
            }
        }
        $desc = cal_str($in, 'description', 2000);
        $id = $CAL->insertCalendar($type, $name(), $color(), $desc !== '' ? $desc : null, $projectId);
        $CAL->audit('calendar_created', ['calendar_id' => $id, 'type' => $type] + ($projectId ? ['project_id' => $projectId] : []));
        $CAL->allCalendars(true);
        cal_json(['id' => $id, 'calendars' => cal_calendars_payload($CAL)]);

    case 'update':
        $c = $managed();
        $desc = cal_str($in, 'description', 2000);
        $DB->prepare("UPDATE calendars SET name = ?, color = ?, description = ? WHERE id = ? AND company_id = ?")
           ->execute([$name(), $color(), $desc !== '' ? $desc : null, (int)$c['id'], $CAL_COMPANY_ID]);
        $CAL->audit('calendar_updated', ['calendar_id' => (int)$c['id']]);
        $CAL->allCalendars(true);
        cal_json(['calendars' => cal_calendars_payload($CAL)]);

    case 'delete':
        $c = $managed();
        if ($c['calendar_type'] === 'personal' && (int)$c['owner_id'] === $CAL_USER_ID) {
            $st = $DB->prepare("SELECT COUNT(*) FROM calendars WHERE company_id = ? AND owner_id = ? AND calendar_type = 'personal' AND (is_active = 1 OR is_active IS NULL)");
            $st->execute([$CAL_COMPANY_ID, $CAL_USER_ID]);
            if ((int)$st->fetchColumn() <= 1) throw new CalendarUserError('This is your own calendar — you need at least one.');
        }
        $DB->beginTransaction();
        try {
            $st = $DB->prepare("SELECT a.file_path FROM calendar_event_attachments a JOIN calendar_events e ON e.id = a.event_id WHERE e.calendar_id = ? AND e.company_id = ?");
            $st->execute([(int)$c['id'], $CAL_COMPANY_ID]);
            $files = $st->fetchAll(PDO::FETCH_COLUMN);
            $ids = "SELECT id FROM calendar_events WHERE calendar_id = " . (int)$c['id'] . " AND company_id = " . $CAL_COMPANY_ID;
            foreach (['calendar_event_attachments', 'calendar_event_links', 'calendar_event_reminders', 'calendar_event_participants'] as $t) {
                $DB->exec("DELETE FROM `$t` WHERE event_id IN ($ids)");
            }
            $DB->prepare("DELETE FROM calendar_events WHERE calendar_id = ? AND company_id = ?")->execute([(int)$c['id'], $CAL_COMPANY_ID]);
            $DB->prepare("DELETE FROM calendars WHERE id = ? AND company_id = ?")->execute([(int)$c['id'], $CAL_COMPANY_ID]);
            $CAL->audit('calendar_deleted', ['calendar_id' => (int)$c['id'], 'name' => $c['name']]);
            $DB->commit();
        } catch (Throwable $e) {
            if ($DB->inTransaction()) $DB->rollBack();
            throw $e;
        }
        foreach ($files as $rel) if ($p = CalendarPaths::resolveStored((string)$rel)) @unlink($p);
        $CAL->allCalendars(true);
        cal_json(['calendars' => cal_calendars_payload($CAL)]);

    case 'share':
        $c = $managed();
        if ($c['calendar_type'] === 'project') throw new CalendarUserError('Project calendars are already visible to the people on the project.');
        if ($c['calendar_type'] !== 'personal') throw new CalendarUserError('Shared calendars are already visible to everyone.');
        $want = [];
        foreach (is_array($in['share'] ?? null) ? $in['share'] : [] as $s) {
            $uid = (int)($s['user_id'] ?? 0);
            if ($uid > 0 && $uid !== (int)$c['owner_id']) $want[$uid] = ($s['role'] ?? 'view') === 'edit' ? 'edit' : 'view';
        }
        $valid = array_column($CAL->people(array_keys($want)), 'id');
        $share = [];
        foreach ($want as $uid => $role) if (in_array($uid, $valid, true)) $share[] = ['user_id' => $uid, 'role' => $role];
        $DB->prepare("UPDATE calendars SET sharing_json = ? WHERE id = ? AND company_id = ?")
           ->execute([$share ? json_encode($share) : null, (int)$c['id'], $CAL_COMPANY_ID]);
        $CAL->audit('calendar_shared', ['calendar_id' => (int)$c['id'], 'people' => count($share)]);
        $CAL->allCalendars(true);
        cal_json(['calendars' => cal_calendars_payload($CAL)]);

    case 'reset_feed':
        $c = $managed();
        $DB->prepare("UPDATE calendars SET ics_token = ? WHERE id = ? AND company_id = ?")
           ->execute([bin2hex(random_bytes(24)), (int)$c['id'], $CAL_COMPANY_ID]);
        $CAL->audit('calendar_feed_reset', ['calendar_id' => (int)$c['id']]);
        $CAL->allCalendars(true);
        cal_json(['calendars' => cal_calendars_payload($CAL)]);
}
cal_error('Unknown action');
