<?php
// calendar/lib/CalendarLink.php
//
// The calendar's API for other modules.
//
//   require_once __DIR__ . '/../../calendar/lib/autoload.php';
//   CalendarSchema::ensure($DB);
//   $id = CalendarLink::followUp($DB, $companyId, $userId, $role, 'crm_account', 12, 'Call back', '2026-10-21 10:00', 60, ['in_app']);
//   $upcoming = CalendarLink::upcomingFor($DB, $companyId, $userId, $role, 'project', 5);
//   CalendarLink::url('2026-10-21', 'invoice:44');   // deep link: open the calendar on a date, item highlighted
//
// Module dates (project, task, invoice, quote, deal, pay day) are not copied
// onto the calendar any more — CalendarSources reads them live. Writers that
// used to copy them call removeMirrors() so old copies disappear.

final class CalendarLink
{
    /** CRM's own names for link targets → stored link types. */
    public static function linkType(string $type): string
    {
        return ['account' => 'crm_account', 'opportunity' => 'crm_opportunity', 'interaction' => 'crm_interaction'][$type] ?? $type;
    }

    /**
     * A follow-up on the user's own calendar, linked to a record, with reminders.
     * @param string[] $channels any of 'in_app', 'email'
     * @return int event id
     */
    public static function followUp(PDO $db, int $companyId, int $userId, string $role, string $linkType, int $linkId,
                                    string $title, string $due, int $minutesBefore = 60, array $channels = ['in_app'],
                                    string $description = '', int $durationMinutes = 30): int
    {
        CalendarSchema::ensure($db);
        $repo = new CalendarRepo($db, $companyId, $userId, $role);
        $start = CalendarRepo::parseWhen($due, false, false);
        if ($start === null) throw new CalendarUserError('Invalid follow-up date/time');
        $channels = array_values(array_intersect($channels, ['email', 'in_app'])) ?: ['in_app'];
        $minutesBefore = max(0, min(10080, $minutesBefore));
        return $repo->create([
            'calendar_id' => $repo->defaultCalendarId(),
            'title'       => $title,
            'description' => $description,
            'all_day'     => false,
            'start'       => $start,
            'end'         => date('Y-m-d H:i:s', strtotime($start) + max(5, $durationMinutes) * 60),
            'reminders'   => array_map(fn($ch) => ['minutes_before' => $minutesBefore, 'channel' => $ch], $channels),
            'links'       => [['type' => self::linkType($linkType), 'id' => $linkId]],
        ], false);
    }

    /**
     * Upcoming events linked to a record that this user can see (soonest first).
     * @return array<int, array{id:int, title:string, start_datetime:string, all_day:bool, channels:string[], url:string}>
     */
    public static function upcomingFor(PDO $db, int $companyId, int $userId, string $role, string $linkType, int $linkId, int $limit = 10): array
    {
        CalendarSchema::ensure($db);
        $repo = new CalendarRepo($db, $companyId, $userId, $role);
        $now = CalendarRepo::nowLocal($db, $companyId);
        $st = $db->prepare("SELECT e.*, (SELECT GROUP_CONCAT(DISTINCT r.channel) FROM calendar_event_reminders r
                                          WHERE r.event_id = e.id AND r.user_id = ?) AS channels
                              FROM calendar_event_links l JOIN calendar_events e ON e.id = l.event_id
                             WHERE l.linked_type = ? AND l.linked_id = ? AND e.company_id = ?
                               AND (e.end_datetime >= ? OR (e.recurrence IS NOT NULL AND e.recurrence <> ''))
                               AND EXISTS (SELECT 1 FROM calendar_event_participants p WHERE p.event_id = e.id)
                          ORDER BY e.start_datetime LIMIT 100");
        $st->execute([$userId, self::linkType($linkType), $linkId, $companyId, $now]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            if ($repo->eventAccess($ev)['see'] !== 'full') continue;
            [$s, $e] = CalendarRepo::span($ev);
            if ($ev['recurrence']) {
                try {
                    $next = Recurrence::nextStart($s, $e, $ev['recurrence'], Recurrence::exdates($ev['exdates_json']), $now,
                                                  date('Y-m-d H:i:s', strtotime($now . ' +2 years')));
                } catch (CalendarUserError $x) { $next = null; }
                if (!$next) continue;
                $s = $next['start'];
            }
            $out[] = ['id' => (int)$ev['id'], 'title' => $ev['title'], 'start_datetime' => $s, 'all_day' => (bool)$ev['all_day'],
                      'channels' => $ev['channels'] ? explode(',', $ev['channels']) : [],
                      'url' => '/calendar/?event=' . (int)$ev['id'] . '&date=' . substr($s, 0, 10)];
        }
        usort($out, fn($a, $b) => strcmp($a['start_datetime'], $b['start_datetime']));
        return array_slice($out, 0, $limit);
    }

    /**
     * A project's calendar: events linked to the project or to tasks on its
     * boards (only those the user may see, repeats expanded), plus the
     * project's start and due dates and its tasks' due dates.
     * @return array{events: array, items: array}
     */
    public static function projectTimeline(PDO $db, int $companyId, int $userId, string $role, int $projectId, string $fromDate, string $toDate): array
    {
        CalendarSchema::ensure($db);
        $repo = new CalendarRepo($db, $companyId, $userId, $role);
        $st = $db->prepare("SELECT DISTINCT l.event_id FROM calendar_event_links l
                              JOIN calendar_events e ON e.id = l.event_id AND e.company_id = ?
                             WHERE (l.linked_type = 'project' AND l.linked_id = ?)
                                OR (l.linked_type = 'item' AND l.linked_id IN (
                                       SELECT i.id FROM board_items i JOIN project_boards b ON b.board_id = i.board_id
                                        WHERE b.project_id = ? AND i.company_id = ?))");
        $st->execute([$companyId, $projectId, $projectId, $companyId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $events = $ids ? $repo->feed($fromDate . ' 00:00:00', $toDate . ' 23:59:59', null, $ids) : [];

        $items = [];
        $st = $db->prepare("SELECT project_id, name, start_date, end_date FROM projects WHERE project_id = ? AND company_id = ?");
        $st->execute([$projectId, $companyId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            $url = '/projects/view.php?project_id=' . $projectId;
            foreach (['start_date' => [' starts', 'project-start'], 'end_date' => [' due', 'project-due']] as $col => [$suffix, $key]) {
                $d = substr((string)$p[$col], 0, 10);
                if ($d && $d !== '0000-00-00' && $d >= $fromDate && $d <= $toDate) {
                    $items[] = ['key' => $key . ':' . $projectId, 'source' => 'projects', 'title' => $p['name'] . $suffix, 'start' => $d, 'end' => $d, 'url' => $url];
                }
            }
        }
        $st = $db->prepare("SELECT i.id, i.title, i.due_date, i.board_id, b.title AS board_title FROM board_items i
                              JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
                             WHERE b.project_id = ? AND i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL)
                               AND (b.archived = 0 OR b.archived IS NULL) AND i.due_date BETWEEN ? AND ?
                          ORDER BY i.due_date LIMIT 1000");
        $st->execute([$projectId, $companyId, $fromDate, $toDate]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $d = substr((string)$t['due_date'], 0, 10);
            $items[] = ['key' => 'item:' . $t['id'], 'source' => 'tasks', 'title' => $t['title'], 'sub' => $t['board_title'],
                        'start' => $d, 'end' => $d, 'url' => '/projects/board.php?board_id=' . (int)$t['board_id'] . '&item=' . (int)$t['id']];
        }
        return ['events' => $events, 'items' => $items];
    }

    /**
     * Delete the copies the retired syncs (and the old Quotes & Invoices hook)
     * wrote for a record: events linked to it that have no participants. Events
     * people created themselves always have an organiser and are kept.
     */
    public static function removeMirrors(PDO $db, int $companyId, string $linkType, int $linkId): int
    {
        $st = $db->prepare("SELECT e.id FROM calendar_event_links l JOIN calendar_events e ON e.id = l.event_id
                             WHERE l.linked_type = ? AND l.linked_id = ? AND e.company_id = ?
                               AND NOT EXISTS (SELECT 1 FROM calendar_event_participants p WHERE p.event_id = e.id)");
        $st->execute([$linkType, $linkId, $companyId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;
        $in = implode(',', $ids);
        foreach (['calendar_event_links', 'calendar_event_reminders', 'calendar_event_attachments'] as $t) {
            $db->exec("DELETE FROM `$t` WHERE event_id IN ($in)");
        }
        $db->exec("DELETE FROM calendar_events WHERE id IN ($in) AND company_id = " . (int)$companyId);
        return count($ids);
    }

    /** Deep link into the calendar: a day, optionally with a live item highlighted ('invoice:12'). */
    public static function url(string $date, ?string $focus = null, string $view = 'month'): string
    {
        $q = ['view' => $view, 'date' => substr($date, 0, 10)];
        if ($focus) $q['focus'] = $focus;
        return '/calendar/?' . http_build_query($q);
    }
}
