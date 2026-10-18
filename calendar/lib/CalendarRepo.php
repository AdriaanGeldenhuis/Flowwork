<?php
// calendar/lib/CalendarRepo.php
//
// Everything the calendar does with its own data, for one signed-in user in
// one company: which calendars and events they may see or change, the event
// feed (with repeating events expanded), create / update / move / delete with
// the "this event / this and following / all events" scopes, invitations and
// replies, reminders, links to other Flowwork records, and the bell + phone
// notifications that go with them.
//
// Access model
//   Calendars  personal  → the owner (plus people it is shared with)
//              team / resource → everyone in the company; members add events,
//                         the owner and admins manage the calendar
//              project   → people with access to the project
//   Events     the organiser and invitees always see an event. Others who can
//              see the calendar see it in full ("default"), as a Busy block
//              ("busy"), or not at all ("private").

final class CalendarRepo
{
    public const COLORS = ['#7a1f30', '#b08d57', '#2f7457', '#2f5c84', '#7a5ab8', '#b4577a',
                           '#c08a2b', '#1f7a7a', '#a8281e', '#4b4fa8', '#6b7d2b', '#5b6670'];

    /** Link types written by the retired syncs; an event carrying one and no participants is a mirror. */
    private const MIRROR_TYPES = "'project','item','invoice','quote'";

    private PDO $db;
    public int $companyId;
    public int $userId;
    public string $role;
    private ?array $calendars = null;
    private ?array $projectIds = null;

    public function __construct(PDO $db, int $companyId, int $userId, string $role)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->role = $role ?: 'viewer';
    }

    /** For the signed-in user (after auth_gate.php). */
    public static function forSession(PDO $db): self
    {
        return new self($db, (int)$_SESSION['company_id'], (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? 'viewer'));
    }

    public function isAdmin(): bool { return $this->role === 'admin'; }

    // =================================================================== time

    /** The company's calendar time zone (admin setting); event times are wall-clock times in it. */
    public static function companyTz(PDO $db, int $companyId): DateTimeZone
    {
        static $cache = [];
        if (isset($cache[$companyId])) return $cache[$companyId];
        $name = self::companySetting($db, $companyId, 'calendar_timezone', 'Africa/Johannesburg');
        try { $tz = new DateTimeZone($name); } catch (Throwable $e) { $tz = new DateTimeZone('Africa/Johannesburg'); }
        return $cache[$companyId] = $tz;
    }

    public static function nowLocal(PDO $db, int $companyId): string
    {
        return (new DateTimeImmutable('now', self::companyTz($db, $companyId)))->format('Y-m-d H:i:s');
    }

    public static function companySetting(PDO $db, int $companyId, string $key, string $default): string
    {
        try {
            $st = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
            $st->execute([$companyId, $key]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    // ============================================================== calendars

    /** All active calendars of the company, keyed by id (cached for the request). */
    public function allCalendars(bool $refresh = false): array
    {
        if ($this->calendars !== null && !$refresh) return $this->calendars;
        $st = $this->db->prepare("SELECT c.*, u.first_name AS owner_first, u.last_name AS owner_last
                                    FROM calendars c LEFT JOIN users u ON u.id = c.owner_id
                                   WHERE c.company_id = ? AND (c.is_active = 1 OR c.is_active IS NULL)
                                ORDER BY FIELD(c.calendar_type, 'personal', 'team', 'project', 'resource'), c.name, c.id");
        $st->execute([$this->companyId]);
        $this->calendars = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $this->calendars[(int)$c['id']] = $c;
        return $this->calendars;
    }

    /** @return array{see:bool, add:bool, manage:bool, mine:bool, share:?string} */
    public function calendarPerms(array $c): array
    {
        $uid = $this->userId;
        $owner = (int)($c['owner_id'] ?? 0);
        $mine = $owner === $uid;
        $member = $this->role !== 'viewer';
        $share = null;
        foreach ((array)json_decode((string)($c['sharing_json'] ?? ''), true) as $s) {
            if (is_array($s) && (int)($s['user_id'] ?? 0) === $uid) $share = ($s['role'] ?? 'view') === 'edit' ? 'edit' : 'view';
        }
        switch ($c['calendar_type']) {
            case 'personal':
                if ($mine) return ['see' => true, 'add' => true, 'manage' => true, 'mine' => true, 'share' => null];
                if ($owner === 0) { // created by an older integration without an owner: treat as shared
                    return ['see' => true, 'add' => $member, 'manage' => $this->isAdmin(), 'mine' => false, 'share' => null];
                }
                if ($share) return ['see' => true, 'add' => $share === 'edit', 'manage' => false, 'mine' => false, 'share' => $share];
                return ['see' => false, 'add' => false, 'manage' => false, 'mine' => false, 'share' => null];
            case 'project':
                $see = $mine || $this->isAdmin() || $share !== null
                    || ((int)$c['project_id'] > 0 && $this->canSeeProject((int)$c['project_id']));
                return ['see' => $see, 'add' => $see && ($mine || $member || $share === 'edit'),
                        'manage' => $mine || $this->isAdmin(), 'mine' => $mine, 'share' => $share];
            default: // team, resource
                return ['see' => true, 'add' => $mine || $member || $share === 'edit',
                        'manage' => $mine || $this->isAdmin(), 'mine' => $mine, 'share' => $share];
        }
    }

    /** Calendars this user can see, each with its 'perms'. */
    public function visibleCalendars(): array
    {
        $out = [];
        foreach ($this->allCalendars() as $id => $c) {
            $p = $this->calendarPerms($c);
            if ($p['see']) { $c['perms'] = $p; $out[$id] = $c; }
        }
        return $out;
    }

    public function calendar(int $id): ?array
    {
        $all = $this->allCalendars();
        if (!isset($all[$id])) return null;
        $c = $all[$id];
        $c['perms'] = $this->calendarPerms($c);
        return $c['perms']['see'] ? $c : null;
    }

    /** The user's own calendar for new events: their preference, else their first personal calendar (created if needed). */
    public function defaultCalendarId(): int
    {
        $pref = (int)(CalendarSettings::load($this->db, $this->companyId, $this->userId)['default_calendar_id'] ?? 0);
        if ($pref && ($c = $this->calendar($pref)) && $c['perms']['add']) return $pref;
        foreach ($this->allCalendars() as $id => $c) {
            if ($c['calendar_type'] === 'personal' && (int)$c['owner_id'] === $this->userId) return (int)$id;
        }
        return $this->withLock('own', function () {
            foreach ($this->allCalendars(true) as $id => $c) {
                if ($c['calendar_type'] === 'personal' && (int)$c['owner_id'] === $this->userId) return (int)$id;
            }
            $st = $this->db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $st->execute([$this->userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'My calendar';
            $id = $this->insertCalendar('personal', $name, self::COLORS[$this->userId % count(self::COLORS)], null);
            $this->allCalendars(true);
            return $id;
        });
    }

    /** Every company gets one shared "Company" calendar the first time somebody opens the calendar. */
    public function ensureCompanyCalendar(): void
    {
        foreach ($this->allCalendars() as $c) if ($c['calendar_type'] === 'team') return;
        $this->withLock('company', function () {
            $st = $this->db->prepare("SELECT 1 FROM calendars WHERE company_id = ? AND calendar_type = 'team' LIMIT 1");
            $st->execute([$this->companyId]);
            if (!$st->fetchColumn()) $this->insertCalendar('team', 'Company', '#b08d57', null);
            $this->allCalendars(true);
        });
    }

    public function insertCalendar(string $type, string $name, string $color, ?string $description, ?int $projectId = null): int
    {
        $this->db->prepare("INSERT INTO calendars (company_id, calendar_type, name, description, color, owner_id, project_id, is_active, ics_token, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())")
            ->execute([$this->companyId, $type, mb_substr($name, 0, 255), $description, $color, $this->userId, $projectId, bin2hex(random_bytes(24))]);
        return (int)$this->db->lastInsertId();
    }

    /** Loads the Projects library (ProjectLink); false when the module is not installed. */
    public static function projectsAvailable(): bool
    {
        if (class_exists('ProjectLink', false)) return true;
        $lib = CalendarPaths::root() . '/projects/lib/autoload.php';
        if (!is_file($lib)) return false;
        require_once $lib;
        return class_exists('ProjectLink', false);
    }

    /**
     * Projects this user can open (null = all, for admins) — the Projects
     * engine's rule: members, owner / creator / manager, projects shared with
     * the company, and projects with a board the user is a member of.
     * @return array<int,true>|null
     */
    public function projectIds(): ?array
    {
        if ($this->isAdmin()) return null;
        if ($this->projectIds !== null) return $this->projectIds;
        $ids = [];
        try {
            if (self::projectsAvailable()) {
                $ids = ProjectLink::visibleProjectIds($this->db, $this->companyId, $this->userId, $this->role);
                if ($ids === null) return null;
            }
        } catch (Throwable $e) {
            error_log('[calendar] project access: ' . $e->getMessage());
        }
        return $this->projectIds = $ids;
    }

    public function canSeeProject(int $projectId): bool
    {
        $ids = $this->projectIds();
        return $ids === null || isset($ids[$projectId]);
    }

    /** SQL limiting $column to the projects this user can open: '' = all (admins), null = none. */
    public function projectSql(string $column): ?string
    {
        $ids = $this->projectIds();
        if ($ids === null) return '';
        if (!$ids) return null;
        return ' AND ' . $column . ' IN (' . implode(',', array_map('intval', array_keys($ids))) . ')';
    }

    // ================================================================= events

    public function eventRow(int $eventId): ?array
    {
        $st = $this->db->prepare("SELECT e.*, c.name AS calendar_name, c.color AS calendar_color, c.calendar_type,
                                         c.owner_id AS calendar_owner_id
                                    FROM calendar_events e JOIN calendars c ON c.id = e.calendar_id
                                   WHERE e.id = ? AND e.company_id = ?");
        $st->execute([$eventId, $this->companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function participantRow(int $eventId, int $userId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM calendar_event_participants WHERE event_id = ? AND user_id = ?");
        $st->execute([$eventId, $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array{see:string, edit:bool, respond:bool, participant:?array}
     *   see: 'full' | 'busy' | 'none'
     */
    public function eventAccess(array $ev, ?array $participant = null, bool $participantKnown = false): array
    {
        if (!$participantKnown) $participant = $this->participantRow((int)$ev['id'], $this->userId);
        $creator = (int)$ev['created_by'] === $this->userId;
        $all = $this->allCalendars();
        $cal = $all[(int)$ev['calendar_id']] ?? null;
        $cp = $cal ? $this->calendarPerms($cal) : ['see' => false, 'add' => false, 'manage' => false];
        $see = 'none';
        if ($creator || $participant) $see = 'full';
        elseif ($cp['see']) $see = ['private' => 'none', 'busy' => 'busy'][$ev['visibility'] ?? 'default'] ?? 'full';
        $edit = $see === 'full' && ($creator || $cp['manage']
            || ($cp['add'] && $cal && $cal['calendar_type'] !== 'personal'));
        $respond = $participant !== null && $participant['role'] !== 'organizer';
        return ['see' => $see, 'edit' => $edit, 'respond' => $respond, 'participant' => $participant];
    }

    /** SQL fragment that hides the copies the retired syncs wrote (live sources show those dates now). */
    private static function notMirrorSql(string $alias = 'e'): string
    {
        return "NOT (NOT EXISTS (SELECT 1 FROM calendar_event_participants mp WHERE mp.event_id = $alias.id)
                     AND EXISTS (SELECT 1 FROM calendar_event_links ml WHERE ml.event_id = $alias.id
                                  AND ml.linked_type IN (" . self::MIRROR_TYPES . ")))";
    }

    /**
     * Events overlapping [$from, $to] ('Y-m-d H:i:s'), repeating events expanded.
     * @param int[]|null $calendarIds limit to these calendars (invitations are always included)
     * @param int[]|null $eventIds    limit to these events
     */
    public function feed(string $from, string $to, ?array $calendarIds = null, ?array $eventIds = null): array
    {
        $visible = $this->visibleCalendars();
        $calIds = array_keys($visible);
        if ($calendarIds !== null) $calIds = array_values(array_intersect($calIds, array_map('intval', $calendarIds)));
        $calIn = $calIds ? implode(',', array_map('intval', $calIds)) : '0';
        $st = $this->db->prepare("
            SELECT e.id, e.calendar_id, e.title, e.location, e.color, e.start_datetime, e.end_datetime, e.all_day,
                   e.recurrence, e.exdates_json, e.visibility, e.created_by, e.series_id,
                   (e.description IS NOT NULL AND e.description <> '') AS has_description,
                   c.name AS calendar_name, c.color AS calendar_color, c.calendar_type,
                   p.id AS p_id, p.role AS p_role, p.response AS p_response,
                   (SELECT COUNT(*) FROM calendar_event_participants pc WHERE pc.event_id = e.id) AS participant_count,
                   (SELECT COUNT(*) FROM calendar_event_attachments ac WHERE ac.event_id = e.id) AS attachment_count,
                   (SELECT COUNT(*) FROM calendar_event_links lc WHERE lc.event_id = e.id) AS link_count
              FROM calendar_events e
              JOIN calendars c ON c.id = e.calendar_id
         LEFT JOIN calendar_event_participants p ON p.event_id = e.id AND p.user_id = ?
             WHERE e.company_id = ?
               AND e.start_datetime <= ?
               AND (e.end_datetime >= ? OR (e.recurrence IS NOT NULL AND e.recurrence <> ''))
               AND (e.calendar_id IN ($calIn) OR p.id IS NOT NULL)
               AND " . self::notMirrorSql() . ($eventIds !== null ? " AND e.id IN (" . (implode(',', array_map('intval', $eventIds)) ?: '0') . ")" : '') . "
          ORDER BY e.start_datetime, e.id
             LIMIT 5000");
        $st->execute([$this->userId, $this->companyId, $to, $from]);
        $out = [];
        $fromDate = substr($from, 0, 10);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $participant = $ev['p_id'] ? ['role' => $ev['p_role'], 'response' => $ev['p_response']] : null;
            if ($participant && $participant['response'] === 'declined' && (int)$ev['created_by'] !== $this->userId) continue;
            $acc = $this->eventAccess($ev, $participant, true);
            if ($acc['see'] === 'none') continue;
            if ($ev['recurrence'] && Recurrence::endsBefore($ev['recurrence'], $fromDate)) continue;
            [$start, $end] = self::span($ev);
            try {
                $occs = $ev['recurrence']
                    ? Recurrence::occurrences($start, $end, $ev['recurrence'], Recurrence::exdates($ev['exdates_json']), $from, $to, 400)
                    : (($start <= $to && $end >= $from) ? [['start' => $start, 'end' => $end]] : []);
            } catch (CalendarUserError $e) {
                $occs = [['start' => $start, 'end' => $end]]; // a rule we cannot expand: show the first one
            }
            $base = $this->feedShape($ev, $acc, isset($visible[(int)$ev['calendar_id']]));
            foreach ($occs as $o) {
                $item = $base;
                $item['start'] = $ev['all_day'] ? substr($o['start'], 0, 10) : $o['start'];
                $item['end'] = $ev['all_day'] ? substr($o['end'], 0, 10) : $o['end'];
                if ($ev['recurrence']) {
                    $item['occ'] = $o['start'];
                    $item['key'] = $ev['id'] . '@' . str_replace(['-', ' ', ':'], '', $o['start']);
                }
                $out[] = $item;
            }
        }
        // Chronological (all-day first on a day), whichever series an occurrence came from.
        usort($out, fn($a, $b) => strcmp($a['start'], $b['start']) ?: ($a['id'] <=> $b['id']));
        return $out;
    }

    private function feedShape(array $ev, array $acc, bool $calendarVisible): array
    {
        $busy = $acc['see'] === 'busy';
        $response = $ev['p_response'] ?? null;
        $invited = $ev['p_id'] && $ev['p_role'] !== 'organizer' && (int)$ev['created_by'] !== $this->userId;
        return [
            'key'           => (string)$ev['id'],
            'id'            => (int)$ev['id'],
            'occ'           => null,
            'calendar_id'   => (int)$ev['calendar_id'],
            'calendar_name' => $calendarVisible ? $ev['calendar_name'] : null,
            'color'         => $ev['color'] ?: $ev['calendar_color'],
            'title'         => $busy ? 'Busy' : $ev['title'],
            'location'      => $busy ? null : ($ev['location'] ?: null),
            'all_day'       => (bool)$ev['all_day'],
            'recurring'     => (bool)$ev['recurrence'],
            'busy'          => $busy,
            'visibility'    => $ev['visibility'] ?: 'default',
            'invited'       => (bool)$invited,
            'via_invite'    => !$calendarVisible,
            'response'      => $invited ? $response : null,
            'editable'      => $acc['edit'],
            'attendees'     => $busy ? 0 : (int)$ev['participant_count'],
            'files'         => $busy ? 0 : (int)$ev['attachment_count'],
            'links'         => $busy ? 0 : (int)$ev['link_count'],
            'has_description' => !$busy && (bool)$ev['has_description'],
        ];
    }

    /**
     * Normalised [start, end] of an event row. All-day events run from 00:00:00
     * on the first day to 23:59:59 on the last; older writers stored an
     * exclusive midnight end, or end = start.
     */
    public static function span(array $ev): array
    {
        $s = (string)$ev['start_datetime'];
        $e = (string)$ev['end_datetime'];
        if ($e < $s) $e = $s;
        if (!empty($ev['all_day'])) {
            $sd = substr($s, 0, 10);
            $ed = substr($e, 0, 10);
            if (substr($e, 11) === '00:00:00' && $ed > $sd) $ed = (new DateTimeImmutable($ed))->modify('-1 day')->format('Y-m-d');
            return [$sd . ' 00:00:00', $ed . ' 23:59:59'];
        }
        return [$s, $e];
    }

    /** Full details of one event for the event panel. */
    public function detail(int $eventId, ?string $occ = null): array
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) throw new CalendarUserError('This event no longer exists.');
        $acc = $this->eventAccess($ev);
        if ($acc['see'] === 'none') throw new CalendarUserError('This event no longer exists.');
        [$start, $end] = self::span($ev);
        if ($occ && $ev['recurrence'] && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $occ)) {
            $occStart = strlen($occ) === 10 ? $occ . ' ' . substr($start, 11) : substr($occ . ':00', 0, 19);
            $dur = strtotime($end) - strtotime($start);
            $start = $occStart;
            $end = date('Y-m-d H:i:s', strtotime($occStart) + $dur);
        } else {
            $occ = null;
        }
        $cal = $this->calendar((int)$ev['calendar_id']);
        $busy = $acc['see'] === 'busy';
        $out = [
            'id'          => (int)$ev['id'],
            'occ'         => $occ ? $start : null,
            'calendar'    => ['id' => (int)$ev['calendar_id'], 'name' => $cal ? $cal['name'] : null,
                              'color' => $ev['calendar_color'], 'type' => $ev['calendar_type']],
            'title'       => $busy ? 'Busy' : $ev['title'],
            'description' => $busy ? '' : (string)$ev['description'],
            'location'    => $busy ? '' : (string)$ev['location'],
            'color'       => $ev['color'],
            'start'       => $ev['all_day'] ? substr($start, 0, 10) : $start,
            'end'         => $ev['all_day'] ? substr($end, 0, 10) : $end,
            'all_day'     => (bool)$ev['all_day'],
            'visibility'  => $ev['visibility'] ?: 'default',
            'recurrence'  => $busy ? null : ($ev['recurrence'] ?: null),
            'recurrence_text' => $ev['recurrence'] ? Recurrence::describe($ev['recurrence'], self::span($ev)[0]) : '',
            'series_start' => $ev['recurrence'] ? substr(self::span($ev)[0], 0, $ev['all_day'] ? 10 : 19) : null,
            'detached'    => (bool)$ev['series_id'],
            'busy'        => $busy,
            'perms'       => ['edit' => $acc['edit'], 'respond' => $acc['respond'] && !$busy],
            'my_response' => $acc['participant']['response'] ?? null,
            'participants' => [], 'reminders' => [], 'attachments' => [], 'links' => [],
            'created_by'  => null, 'created_at' => $ev['created_at'], 'updated_at' => $ev['updated_at'],
        ];
        if ($busy) return $out;

        $st = $this->db->prepare("SELECT p.user_id, p.role, p.response, u.first_name, u.last_name, u.email
                                    FROM calendar_event_participants p JOIN users u ON u.id = p.user_id
                                   WHERE p.event_id = ? ORDER BY p.role = 'organizer' DESC, u.first_name, u.last_name");
        $st->execute([$eventId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out['participants'][] = ['user_id' => (int)$p['user_id'], 'name' => trim($p['first_name'] . ' ' . $p['last_name']),
                                      'email' => $p['email'], 'role' => $p['role'], 'response' => $p['response']];
        }
        $st = $this->db->prepare("SELECT minutes_before, channel FROM calendar_event_reminders WHERE event_id = ? AND user_id = ? ORDER BY minutes_before");
        $st->execute([$eventId, $this->userId]);
        $out['reminders'] = array_map(fn($r) => ['minutes_before' => (int)$r['minutes_before'], 'channel' => $r['channel']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $this->db->prepare("SELECT id, file_name, file_size, mime_type, uploaded_at FROM calendar_event_attachments WHERE event_id = ? ORDER BY id");
        $st->execute([$eventId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out['attachments'][] = ['id' => (int)$a['id'], 'name' => $a['file_name'], 'size' => (int)$a['file_size'],
                                     'mime' => $a['mime_type'], 'url' => '/calendar/api/attachment.php?id=' . (int)$a['id']];
        }
        $out['links'] = (new CalendarLinks($this->db, $this))->forEvent($eventId);
        $st = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
        $st->execute([(int)$ev['created_by']]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) $out['created_by'] = ['id' => (int)$u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name'])];
        return $out;
    }

    // ============================================================== writing

    /** Validate and normalise event input. Returns only the keys present in $in (plus derived ones). */
    public function normaliseInput(array $in, bool $creating, ?array $current = null): array
    {
        $out = [];
        if ($creating || array_key_exists('calendar_id', $in)) {
            $calId = (int)($in['calendar_id'] ?? 0) ?: $this->defaultCalendarId();
            $cal = $this->calendar($calId);
            if (!$cal || !$cal['perms']['add']) throw new CalendarUserError('You cannot add events to that calendar.');
            $out['calendar_id'] = $calId;
        }
        if ($creating || array_key_exists('title', $in)) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')));
            if ($t === '') throw new CalendarUserError('Give the event a title.');
            $out['title'] = mb_substr($t, 0, 500);
        }
        foreach (['description' => 20000, 'location' => 255] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = trim(str_replace("\r\n", "\n", (string)$in[$k]));
                $out[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if (array_key_exists('color', $in)) {
            $c = trim((string)$in['color']);
            if ($c !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $c)) throw new CalendarUserError('Choose a colour from the list.');
            $out['color'] = $c === '' ? null : strtolower($c);
        }
        if (array_key_exists('visibility', $in)) {
            $v = (string)$in['visibility'];
            if (!in_array($v, ['default', 'busy', 'private'], true)) throw new CalendarUserError('Unknown visibility.');
            $out['visibility'] = $v;
        }
        if ($creating || array_key_exists('start', $in) || array_key_exists('end', $in) || array_key_exists('all_day', $in)) {
            $allDay = array_key_exists('all_day', $in) ? (!empty($in['all_day']) && $in['all_day'] !== 'false') : !empty($current['all_day']);
            if ($current) { // a partial change keeps what it does not mention
                [$cs, $ce] = self::span($current);
                if (!array_key_exists('start', $in)) $in['start'] = $cs;
                if (!array_key_exists('end', $in)) {
                    $in['end'] = date('Y-m-d H:i:s', (int)strtotime((string)$in['start']) + strtotime($ce) - strtotime($cs));
                }
            }
            $start = self::parseWhen($in['start'] ?? null, $allDay, false);
            $end = self::parseWhen(($in['end'] ?? '') !== '' ? $in['end'] : ($in['start'] ?? null), $allDay, true);
            if ($start === null || $end === null) throw new CalendarUserError('Choose when the event starts and ends.');
            if ($end < $start) throw new CalendarUserError('The event ends before it starts.');
            if (strtotime($end) - strtotime($start) > 400 * 86400) throw new CalendarUserError('An event can last at most 400 days.');
            $out['all_day'] = $allDay ? 1 : 0;
            $out['start_datetime'] = $start;
            $out['end_datetime'] = $end;
        }
        if (array_key_exists('recurrence', $in)) {
            $out['recurrence'] = Recurrence::normalise(is_string($in['recurrence']) ? $in['recurrence'] : null);
        }
        if (array_key_exists('participants', $in)) {
            $out['participants'] = $this->validParticipants(is_array($in['participants']) ? $in['participants'] : []);
        }
        if (array_key_exists('reminders', $in)) {
            $rem = [];
            foreach (is_array($in['reminders']) ? $in['reminders'] : [] as $r) {
                if (!is_array($r)) continue;
                $m = max(0, min(40320, (int)($r['minutes_before'] ?? 0)));
                $ch = ($r['channel'] ?? 'in_app') === 'email' ? 'email' : 'in_app';
                $rem[$m . $ch] = ['minutes_before' => $m, 'channel' => $ch];
            }
            if (count($rem) > 5) throw new CalendarUserError('Use at most 5 reminders.');
            $out['reminders'] = array_values($rem);
        }
        if (array_key_exists('links', $in)) {
            $links = new CalendarLinks($this->db, $this);
            $keep = [];
            foreach (is_array($in['links']) ? $in['links'] : [] as $l) {
                if (!is_array($l)) continue;
                $type = (string)($l['type'] ?? '');
                $id = (int)($l['id'] ?? 0);
                if ($links->canLink($type, $id)) $keep[$type . ':' . $id] = ['type' => $type, 'id' => $id];
            }
            if (count($keep) > 10) throw new CalendarUserError('Link at most 10 records.');
            $out['links'] = array_values($keep);
        }
        return $out;
    }

    /** 'Y-m-d' / 'Y-m-d H:i[:s]' / 'Y-m-dTH:i' → stored datetime, or null. */
    public static function parseWhen($v, bool $allDay, bool $isEnd): ?string
    {
        $v = trim(str_replace('T', ' ', (string)$v));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)) return null;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $date = "$m[1]-$m[2]-$m[3]";
        if ($allDay) return $date . ($isEnd ? ' 23:59:59' : ' 00:00:00');
        $h = (int)($m[4] ?? ($isEnd ? 23 : 0));
        $i = (int)($m[5] ?? ($isEnd ? 59 : 0));
        $s = (int)($m[6] ?? 0);
        if ($h > 23 || $i > 59 || $s > 59) return null;
        return sprintf('%s %02d:%02d:%02d', $date, $h, $i, $s);
    }

    /** Company colleagues only; the organiser is added separately. */
    private function validParticipants(array $list): array
    {
        $want = [];
        foreach ($list as $p) {
            $id = (int)(is_array($p) ? ($p['user_id'] ?? 0) : $p);
            if ($id <= 0 || $id === $this->userId) continue;
            $role = (is_array($p) && ($p['role'] ?? '') === 'optional') ? 'optional' : 'required';
            $want[$id] = $role;
        }
        if (count($want) > 100) throw new CalendarUserError('Invite at most 100 people.');
        if (!$want) return [];
        $ok = array_flip(array_map('intval', array_column($this->people(array_keys($want)), 'id')));
        $out = [];
        foreach ($want as $id => $role) if (isset($ok[$id])) $out[$id] = $role;
        return $out;
    }

    /** Active members of the company (optionally only these ids). */
    public function people(?array $ids = null): array
    {
        $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
                  FROM users u LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = ?
                 WHERE u.status = 'active' AND (uc.id IS NOT NULL OR u.company_id = ?)";
        $params = [$this->companyId, $this->companyId];
        if ($ids !== null) {
            if (!$ids) return [];
            $sql .= " AND u.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            $params = array_merge($params, array_map('intval', $ids));
        }
        $st = $this->db->prepare($sql . " ORDER BY u.first_name, u.last_name LIMIT 1000");
        $st->execute($params);
        return array_map(fn($u) => ['id' => (int)$u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']), 'email' => $u['email']],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Create an event. Returns the new id. */
    public function create(array $in, bool $notify = true): int
    {
        $data = $this->normaliseInput($in, true);
        if (!isset($data['reminders'])) {
            $min = (int)CalendarSettings::load($this->db, $this->companyId, $this->userId)['default_reminder'];
            $data['reminders'] = $min > 0 ? [['minutes_before' => $min, 'channel' => 'in_app']] : [];
        }
        $this->db->beginTransaction();
        try {
            $id = $this->insertEvent($data);
            $this->setParticipants($id, $data['participants'] ?? [], []);
            $this->setReminders($id, $data['reminders']);
            if (!empty($data['links'])) $this->setLinks($id, $data['links']);
            $this->audit('event_created', ['event_id' => $id, 'title' => $data['title'], 'calendar_id' => $data['calendar_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($notify && !empty($data['participants'])) {
            $this->notifyInvite($id, array_keys($data['participants']));
        }
        return $id;
    }

    private function insertEvent(array $d, array $extra = []): int
    {
        $row = $d + ['description' => null, 'location' => null, 'color' => null, 'recurrence' => null, 'visibility' => 'default'];
        $this->db->prepare("INSERT INTO calendar_events
                (company_id, calendar_id, title, description, location, color, start_datetime, end_datetime, all_day,
                 recurrence, exdates_json, visibility, ics_uid, series_id, recurrence_id, created_by, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$this->companyId, $row['calendar_id'], $row['title'], $row['description'], $row['location'], $row['color'],
                       $row['start_datetime'], $row['end_datetime'], $row['all_day'], $row['recurrence'],
                       $extra['exdates_json'] ?? null, $row['visibility'], $extra['ics_uid'] ?? null,
                       $extra['series_id'] ?? null, $extra['recurrence_id'] ?? null,
                       $extra['created_by'] ?? $this->userId, $this->userId]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an event. $scope: 'all' | 'this' | 'following' (repeating events; $occ = occurrence start).
     * Returns the id of the event that now holds the change (a new one for 'this' / 'following').
     */
    public function update(int $eventId, array $in, string $scope = 'all', ?string $occ = null, bool $notify = true): int
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) throw new CalendarUserError('This event no longer exists.');
        $acc = $this->eventAccess($ev);
        if (!$acc['edit']) throw new CalendarUserError('You cannot change this event.');
        $data = $this->normaliseInput($in, false, $ev);
        [$sStart, $sEnd] = self::span($ev);
        $occ = $this->validOcc($ev, $occ);

        if ($ev['recurrence'] && $occ !== null && $scope !== 'all' && !($scope === 'following' && substr($occ, 0, 10) === substr($sStart, 0, 10))) {
            return $scope === 'this' ? $this->detachOccurrence($ev, $occ, $data, $notify)
                                     : $this->splitSeries($ev, $occ, $data, $notify);
        }

        // Editing the whole series from one occurrence: shift the series by the same amount.
        if ($ev['recurrence'] && $occ !== null && isset($data['start_datetime'])) {
            $delta = strtotime($data['start_datetime']) - strtotime($occ);
            $len = strtotime($data['end_datetime']) - strtotime($data['start_datetime']);
            $ns = date('Y-m-d H:i:s', strtotime($sStart) + $delta);
            $data['start_datetime'] = $data['all_day'] ? substr($ns, 0, 10) . ' 00:00:00' : $ns;
            $ne = date('Y-m-d H:i:s', strtotime($data['start_datetime']) + $len);
            $data['end_datetime'] = $data['all_day'] ? substr($ne, 0, 10) . ' 23:59:59' : $ne;
        }

        $before = ['start' => $sStart, 'end' => $sEnd, 'location' => (string)$ev['location'], 'title' => $ev['title']];
        $this->db->beginTransaction();
        try {
            $this->writeFields($eventId, $data);
            $added = [];
            $removed = [];
            if (array_key_exists('participants', $data)) {
                [$added, $removed] = $this->setParticipants($eventId, $data['participants'], $this->currentParticipants($eventId));
            }
            if (array_key_exists('reminders', $data)) $this->setReminders($eventId, $data['reminders']);
            elseif ($added) $this->copyReminders($eventId, $added);
            if (array_key_exists('links', $data)) $this->setLinks($eventId, $data['links']);
            $this->audit('event_updated', ['event_id' => $eventId, 'fields' => array_keys($data)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($notify) {
            if ($added) $this->notifyInvite($eventId, $added);
            if ($removed) $this->notifyCancelled($ev, $removed, $occ);
            $after = $this->eventRow($eventId);
            [$aStart, $aEnd] = self::span($after);
            if ($aStart !== $before['start'] || $aEnd !== $before['end'] || (string)$after['location'] !== $before['location']) {
                $others = array_diff($this->attendeeIds($eventId), $added, [$this->userId]);
                $this->notifyChanged($after, $others);
            }
        }
        return $eventId;
    }

    /** Drag / resize: new start and end only. */
    public function move(int $eventId, string $start, string $end, string $scope = 'all', ?string $occ = null): int
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) throw new CalendarUserError('This event no longer exists.');
        return $this->update($eventId, ['start' => $start, 'end' => $end, 'all_day' => (bool)$ev['all_day']], $scope, $occ);
    }

    private function writeFields(int $eventId, array $data): void
    {
        $cols = ['calendar_id', 'title', 'description', 'location', 'color', 'visibility', 'all_day', 'start_datetime', 'end_datetime', 'recurrence'];
        $set = [];
        $params = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $data)) { $set[] = "`$c` = ?"; $params[] = $data[$c]; }
        }
        if (array_key_exists('recurrence', $data) && $data['recurrence'] === null) $set[] = "exdates_json = NULL";
        $set[] = 'updated_by = ?';
        $params[] = $this->userId;
        $set[] = 'updated_at = NOW()';
        $params[] = $eventId;
        $params[] = $this->companyId;
        $this->db->prepare("UPDATE calendar_events SET " . implode(', ', $set) . " WHERE id = ? AND company_id = ?")->execute($params);
    }

    /** Change one occurrence: skip it in the series and store it as its own event. */
    private function detachOccurrence(array $ev, string $occ, array $data, bool $notify): int
    {
        [$sStart, $sEnd] = self::span($ev);
        $len = strtotime($sEnd) - strtotime($sStart);
        $base = $this->rowAsData($ev);
        $base['start_datetime'] = $occ;
        $base['end_datetime'] = date('Y-m-d H:i:s', strtotime($occ) + $len);
        $base['recurrence'] = null;
        $new = array_merge($base, array_diff_key($data, ['recurrence' => 1]));
        $this->db->beginTransaction();
        try {
            $this->addExdate((int)$ev['id'], substr($occ, 0, 10));
            $id = $this->insertEvent($new, ['series_id' => (int)$ev['id'], 'recurrence_id' => $occ, 'created_by' => (int)$ev['created_by']]);
            $this->cloneChildren((int)$ev['id'], $id, $data);
            $this->audit('event_occurrence_changed', ['event_id' => (int)$ev['id'], 'occurrence' => $occ, 'new_event_id' => $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($notify && (isset($data['start_datetime']) || isset($data['location']))) {
            $this->notifyChanged($this->eventRow($id), array_diff($this->attendeeIds($id), [$this->userId]));
        }
        return $id;
    }

    /** Change this and following occurrences: end the series before $occ and start a new one there. */
    private function splitSeries(array $ev, string $occ, array $data, bool $notify): int
    {
        [$sStart, $sEnd] = self::span($ev);
        $len = strtotime($sEnd) - strtotime($sStart);
        $occDate = substr($occ, 0, 10);
        $base = $this->rowAsData($ev);
        $base['start_datetime'] = $occ;
        $base['end_datetime'] = date('Y-m-d H:i:s', strtotime($occ) + $len);
        $base['recurrence'] = Recurrence::remainder($ev['recurrence'], $sStart, $occ);
        $new = array_merge($base, $data);
        $ex = array_keys(Recurrence::exdates($ev['exdates_json']));
        $keep = array_values(array_filter($ex, fn($d) => $d < $occDate));
        $move = array_values(array_filter($ex, fn($d) => $d >= $occDate));
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE calendar_events SET recurrence = ?, exdates_json = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                ->execute([Recurrence::untilBefore($ev['recurrence'], $occDate), $keep ? json_encode($keep) : null, $this->userId, (int)$ev['id'], $this->companyId]);
            $id = $this->insertEvent($new, ['exdates_json' => ($new['recurrence'] && $move) ? json_encode($move) : null, 'created_by' => (int)$ev['created_by']]);
            $this->cloneChildren((int)$ev['id'], $id, $data);
            // Changed single occurrences after the split now belong to the new series.
            $this->db->prepare("UPDATE calendar_events SET series_id = ? WHERE series_id = ? AND company_id = ? AND recurrence_id >= ?")
                ->execute([$id, (int)$ev['id'], $this->companyId, $occDate . ' 00:00:00']);
            $this->audit('event_series_split', ['event_id' => (int)$ev['id'], 'from' => $occ, 'new_event_id' => $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($notify && (isset($data['start_datetime']) || isset($data['location']))) {
            $this->notifyChanged($this->eventRow($id), array_diff($this->attendeeIds($id), [$this->userId]));
        }
        return $id;
    }

    private function rowAsData(array $ev): array
    {
        return ['calendar_id' => (int)$ev['calendar_id'], 'title' => $ev['title'], 'description' => $ev['description'],
                'location' => $ev['location'], 'color' => $ev['color'], 'visibility' => $ev['visibility'] ?: 'default',
                'all_day' => (int)$ev['all_day'], 'recurrence' => $ev['recurrence']];
    }

    /** Copy participants (with their replies), reminders and links to a split-off event, applying $data's lists. */
    private function cloneChildren(int $fromId, int $toId, array $data): void
    {
        if (array_key_exists('participants', $data)) {
            $this->setParticipants($toId, $data['participants'], [], (int)$this->eventRow($toId)['created_by']);
        } else {
            $this->db->prepare("INSERT INTO calendar_event_participants (event_id, user_id, role, response, response_at)
                                SELECT ?, user_id, role, response, response_at FROM calendar_event_participants WHERE event_id = ?")
                ->execute([$toId, $fromId]);
        }
        if (array_key_exists('reminders', $data)) {
            $this->setReminders($toId, $data['reminders']);
        } else {
            $this->db->prepare("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel)
                                SELECT ?, user_id, minutes_before, channel FROM calendar_event_reminders WHERE event_id = ?")
                ->execute([$toId, $fromId]);
        }
        if (array_key_exists('links', $data)) {
            $this->setLinks($toId, $data['links']);
        } else {
            $this->db->prepare("INSERT INTO calendar_event_links (event_id, linked_type, linked_id)
                                SELECT ?, linked_type, linked_id FROM calendar_event_links WHERE event_id = ?")
                ->execute([$toId, $fromId]);
        }
    }

    private function addExdate(int $eventId, string $date): void
    {
        $st = $this->db->prepare("SELECT exdates_json FROM calendar_events WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$eventId, $this->companyId]);
        $ex = Recurrence::exdates((string)$st->fetchColumn());
        $ex[$date] = true;
        ksort($ex);
        $this->db->prepare("UPDATE calendar_events SET exdates_json = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
            ->execute([json_encode(array_keys($ex)), $this->userId, $eventId, $this->companyId]);
    }

    private function validOcc(array $ev, ?string $occ): ?string
    {
        if (!$ev['recurrence'] || !$occ) return null;
        $occ = str_replace('T', ' ', trim($occ));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $occ)) throw new CalendarUserError('Unknown occurrence.');
        [$sStart] = self::span($ev);
        if (strlen($occ) === 10) $occ .= ' ' . substr($sStart, 11);
        return substr($occ . ':00', 0, 19);
    }

    /**
     * Delete an event. $scope for repeating events: 'all' | 'this' | 'following'.
     * @return string[] stored attachment paths that are no longer referenced (caller removes the files)
     */
    public function delete(int $eventId, string $scope = 'all', ?string $occ = null, bool $notify = true): array
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) throw new CalendarUserError('This event no longer exists.');
        if (!$this->eventAccess($ev)['edit']) throw new CalendarUserError('You cannot delete this event.');
        $occ = $this->validOcc($ev, $occ);
        [$sStart] = self::span($ev);
        $attendees = array_diff($this->attendeeIds($eventId), [$this->userId]);
        if ($occ !== null && $scope === 'following' && substr($occ, 0, 10) <= substr($sStart, 0, 10)) $scope = 'all';
        if ($occ === null) $scope = 'all';

        if ($scope === 'this') {
            $this->db->beginTransaction();
            try {
                $this->addExdate($eventId, substr($occ, 0, 10));
                $this->audit('event_occurrence_deleted', ['event_id' => $eventId, 'occurrence' => $occ]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
            if ($notify) $this->notifyCancelled($ev, $attendees, $occ);
            return [];
        }
        if ($scope === 'following') {
            $this->db->beginTransaction();
            try {
                $this->db->prepare("UPDATE calendar_events SET recurrence = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([Recurrence::untilBefore($ev['recurrence'], substr($occ, 0, 10)), $this->userId, $eventId, $this->companyId]);
                $files = $this->deleteRows("series_id = ? AND recurrence_id >= ?", [$eventId, substr($occ, 0, 10) . ' 00:00:00']);
                $this->audit('event_series_ended', ['event_id' => $eventId, 'from' => $occ]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
            if ($notify) $this->notifyCancelled($ev, $attendees, $occ, true);
            return $files;
        }
        $this->db->beginTransaction();
        try {
            $files = $this->deleteRows("(id = ? OR series_id = ?)", [$eventId, $eventId]);
            $this->audit('event_deleted', ['event_id' => $eventId, 'title' => $ev['title']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($notify) $this->notifyCancelled($ev, $attendees, null);
        return $files;
    }

    /** Delete events matching $where (and their children); returns their attachment paths. */
    private function deleteRows(string $where, array $params): array
    {
        $st = $this->db->prepare("SELECT id FROM calendar_events WHERE company_id = ? AND $where");
        $st->execute(array_merge([$this->companyId], $params));
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $files = $this->db->query("SELECT file_path FROM calendar_event_attachments WHERE event_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['calendar_event_attachments', 'calendar_event_links', 'calendar_event_reminders', 'calendar_event_participants'] as $t) {
            $this->db->exec("DELETE FROM `$t` WHERE event_id IN ($in)");
        }
        $this->db->exec("DELETE FROM calendar_events WHERE id IN ($in) AND company_id = " . (int)$this->companyId);
        return $files;
    }

    /** Reply to an invitation (applies to the whole series). */
    public function respond(int $eventId, string $response): void
    {
        if (!in_array($response, ['accepted', 'declined', 'tentative'], true)) throw new CalendarUserError('Choose Yes, No or Maybe.');
        $ev = $this->eventRow($eventId);
        if (!$ev) throw new CalendarUserError('This event no longer exists.');
        $p = $this->participantRow($eventId, $this->userId);
        if (!$p) throw new CalendarUserError('You are not invited to this event.');
        if ($p['role'] === 'organizer') throw new CalendarUserError('You are the organiser of this event.');
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE calendar_event_participants SET response = ?, response_at = NOW() WHERE event_id = ? AND user_id = ?")
                ->execute([$response, $eventId, $this->userId]);
            if ($response === 'declined') {
                $this->db->prepare("DELETE FROM calendar_event_reminders WHERE event_id = ? AND user_id = ?")->execute([$eventId, $this->userId]);
            } else {
                $this->copyReminders($eventId, [$this->userId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        if ($response !== $p['response']) {
            $label = ['accepted' => 'accepted', 'declined' => 'declined', 'tentative' => 'might attend'][$response];
            $organiser = (int)$ev['created_by'];
            $st = $this->db->prepare("SELECT user_id FROM calendar_event_participants WHERE event_id = ? AND role = 'organizer' LIMIT 1");
            $st->execute([$eventId]);
            $organiser = (int)($st->fetchColumn() ?: $organiser);
            if ($organiser !== $this->userId) {
                $me = $this->userName($this->userId);
                $this->notifyUsers([$organiser], 'event_response', $me . ' ' . $label . ': ' . $ev['title'],
                    self::whenText($ev), $this->eventUrl($ev), ['who' => $me]);
            }
        }
    }

    public function search(string $q, int $limit = 40): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
        $calIn = implode(',', array_map('intval', array_keys($this->visibleCalendars()))) ?: '0';
        $now = self::nowLocal($this->db, $this->companyId);
        $st = $this->db->prepare("
            SELECT e.id, e.calendar_id, e.title, e.location, e.color, e.start_datetime, e.end_datetime, e.all_day, e.recurrence,
                   e.visibility, e.created_by, e.series_id, e.exdates_json, 0 AS has_description,
                   c.name AS calendar_name, c.color AS calendar_color, c.calendar_type,
                   p.id AS p_id, p.role AS p_role, p.response AS p_response, 0 AS participant_count, 0 AS attachment_count, 0 AS link_count
              FROM calendar_events e JOIN calendars c ON c.id = e.calendar_id
         LEFT JOIN calendar_event_participants p ON p.event_id = e.id AND p.user_id = ?
             WHERE e.company_id = ? AND (e.calendar_id IN ($calIn) OR p.id IS NOT NULL)
               AND (e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)
               AND " . self::notMirrorSql() . "
          ORDER BY (e.end_datetime < ?) ASC, ABS(TIMESTAMPDIFF(MINUTE, ?, e.start_datetime)) ASC
             LIMIT " . max(1, min(100, $limit * 2)));
        $st->execute([$this->userId, $this->companyId, $like, $like, $like, $now, $now]);
        $visible = $this->visibleCalendars();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $participant = $ev['p_id'] ? ['role' => $ev['p_role'], 'response' => $ev['p_response']] : null;
            $acc = $this->eventAccess($ev, $participant, true);
            if ($acc['see'] !== 'full') continue;
            [$s, $e] = self::span($ev);
            if ($ev['recurrence']) { // show the next occurrence of a series
                try {
                    $next = Recurrence::nextStart($s, $e, $ev['recurrence'], Recurrence::exdates($ev['exdates_json']), $now, date('Y-m-d H:i:s', strtotime($now . ' +2 years')));
                    if ($next) { $s = $next['start']; $e = $next['end']; }
                } catch (CalendarUserError $x) { /* first occurrence */ }
            }
            $item = $this->feedShape($ev, $acc, isset($visible[(int)$ev['calendar_id']]));
            $item['start'] = $ev['all_day'] ? substr($s, 0, 10) : $s;
            $item['end'] = $ev['all_day'] ? substr($e, 0, 10) : $e;
            if ($ev['recurrence']) $item['occ'] = $s;
            $out[] = $item;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Add events parsed by Ics::parse() to a calendar. Events seen before (same UID
     * in this calendar) are updated, so importing a file twice does not duplicate.
     * @return array{added:int, updated:int, skipped:int}
     */
    public function importIcs(int $calendarId, array $events): array
    {
        $cal = $this->calendar($calendarId);
        if (!$cal || !$cal['perms']['add']) throw new CalendarUserError('You cannot add events to that calendar.');
        $added = $updated = $skipped = 0;
        // Masters first, so changed occurrences (RECURRENCE-ID) find their series.
        usort($events, fn($a, $b) => ($a['recurrence_id'] !== null) <=> ($b['recurrence_id'] !== null));
        $find = $this->db->prepare("SELECT id FROM calendar_events WHERE company_id = ? AND calendar_id = ? AND ics_uid = ?
                                     AND recurrence_id <=> ? LIMIT 1");
        $this->db->beginTransaction();
        try {
            foreach ($events as $ev) {
                try {
                    $data = $this->normaliseInput([
                        'calendar_id' => $calendarId, 'title' => $ev['title'], 'description' => $ev['description'] ?? '',
                        'location' => $ev['location'] ?? '', 'all_day' => $ev['all_day'], 'start' => $ev['start'], 'end' => $ev['end'],
                        'recurrence' => $ev['recurrence_id'] ? null : $ev['recurrence'], 'visibility' => $ev['visibility'] ?? 'default',
                    ], true);
                } catch (CalendarUserError $e) {
                    $skipped++;
                    continue;
                }
                $ex = $ev['exdates'] ? json_encode(array_values($ev['exdates'])) : null;
                $existing = null;
                if ($ev['uid']) {
                    $find->execute([$this->companyId, $calendarId, $ev['uid'], $ev['recurrence_id']]);
                    $existing = (int)$find->fetchColumn() ?: null;
                }
                // A changed occurrence skips its date in the series (again after the
                // series was re-imported, which resets its skipped dates to the file's).
                $master = 0;
                if ($ev['recurrence_id'] && $ev['uid']) {
                    $find->execute([$this->companyId, $calendarId, $ev['uid'], null]);
                    if ($master = (int)$find->fetchColumn()) $this->addExdate($master, substr($ev['recurrence_id'], 0, 10));
                }
                if ($existing) {
                    $this->writeFields($existing, $data);
                    $this->db->prepare("UPDATE calendar_events SET exdates_json = ? WHERE id = ?")->execute([$ex, $existing]);
                    $updated++;
                    continue;
                }
                $extra = ['ics_uid' => $ev['uid'], 'exdates_json' => $ex];
                if ($master) $extra += ['series_id' => $master, 'recurrence_id' => $ev['recurrence_id']];
                $id = $this->insertEvent($data, $extra);
                $this->setParticipants($id, [], [], $this->userId);
                $added++;
            }
            $this->audit('calendar_imported', ['calendar_id' => $calendarId, 'added' => $added, 'updated' => $updated]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** Busy times of colleagues (for "find a time"): events they attend or organise, details hidden. */
    public function busy(array $userIds, string $from, string $to, ?int $excludeEventId = null): array
    {
        $ids = array_column($this->people(array_slice(array_map('intval', $userIds), 0, 50)), 'id');
        if (!$ids) return [];
        $in = implode(',', $ids);
        $st = $this->db->prepare("
            SELECT e.id, e.start_datetime, e.end_datetime, e.all_day, e.recurrence, e.exdates_json, p.user_id
              FROM calendar_event_participants p JOIN calendar_events e ON e.id = p.event_id
             WHERE e.company_id = ? AND p.user_id IN ($in) AND p.response <> 'declined'
               AND e.start_datetime <= ? AND (e.end_datetime >= ? OR (e.recurrence IS NOT NULL AND e.recurrence <> ''))
               AND e.id <> ?");
        $st->execute([$this->companyId, $to, $from, (int)$excludeEventId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            [$s, $e] = self::span($ev);
            try {
                $occs = Recurrence::occurrences($s, $e, $ev['recurrence'] ?: null, Recurrence::exdates($ev['exdates_json']), $from, $to, 200);
            } catch (CalendarUserError $x) { $occs = []; }
            foreach ($occs as $o) $out[] = ['user_id' => (int)$ev['user_id'], 'start' => $o['start'], 'end' => $o['end'], 'all_day' => (bool)$ev['all_day']];
        }
        return $out;
    }

    // ======================================================= children helpers

    /** @return array<int,string> user_id => role, excluding the organiser */
    private function currentParticipants(int $eventId): array
    {
        $st = $this->db->prepare("SELECT user_id, role FROM calendar_event_participants WHERE event_id = ? AND role <> 'organizer'");
        $st->execute([$eventId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']] = $r['role'];
        return $out;
    }

    /**
     * Make the guest list $want (user_id => role). The organiser row is kept / created.
     * @return array{0:int[],1:int[]} [added, removed]
     */
    private function setParticipants(int $eventId, array $want, array $current, ?int $organiser = null): array
    {
        $organiser = $organiser ?? (int)($this->eventRow($eventId)['created_by'] ?? $this->userId);
        $this->db->prepare("INSERT IGNORE INTO calendar_event_participants (event_id, user_id, role, response, response_at)
                            VALUES (?, ?, 'organizer', 'accepted', NOW())")->execute([$eventId, $organiser]);
        unset($want[$organiser], $current[$organiser]);
        $added = array_values(array_diff(array_keys($want), array_keys($current)));
        $removed = array_values(array_diff(array_keys($current), array_keys($want)));
        $ins = $this->db->prepare("INSERT INTO calendar_event_participants (event_id, user_id, role, response) VALUES (?, ?, ?, 'pending')
                                   ON DUPLICATE KEY UPDATE role = VALUES(role)");
        foreach ($want as $uid => $role) {
            if (in_array($uid, $added, true) || ($current[$uid] ?? null) !== $role) $ins->execute([$eventId, $uid, $role]);
        }
        if ($removed) {
            $in = implode(',', array_map('intval', $removed));
            $this->db->prepare("DELETE FROM calendar_event_participants WHERE event_id = ? AND user_id IN ($in)")->execute([$eventId]);
            $this->db->prepare("DELETE FROM calendar_event_reminders WHERE event_id = ? AND user_id IN ($in)")->execute([$eventId]);
        }
        return [$added, $removed];
    }

    /** Organiser and guests who have not declined. @return int[] */
    private function attendeeIds(int $eventId, bool $includeDeclined = false): array
    {
        $st = $this->db->prepare("SELECT user_id FROM calendar_event_participants WHERE event_id = ?" . ($includeDeclined ? '' : " AND response <> 'declined'"));
        $st->execute([$eventId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The event's reminders apply to everyone attending; replace them all. */
    private function setReminders(int $eventId, array $reminders): void
    {
        $this->db->prepare("DELETE FROM calendar_event_reminders WHERE event_id = ?")->execute([$eventId]);
        if (!$reminders) return;
        $ins = $this->db->prepare("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel) VALUES (?, ?, ?, ?)");
        foreach ($this->attendeeIds($eventId) as $uid) {
            foreach ($reminders as $r) $ins->execute([$eventId, $uid, $r['minutes_before'], $r['channel']]);
        }
    }

    /** Give these users the organiser's reminders if they have none yet. */
    private function copyReminders(int $eventId, array $userIds): void
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) return;
        $st = $this->db->prepare("SELECT minutes_before, channel FROM calendar_event_reminders WHERE event_id = ? AND user_id = ?");
        $st->execute([$eventId, (int)$ev['created_by']]);
        $template = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$template) return;
        $has = $this->db->prepare("SELECT 1 FROM calendar_event_reminders WHERE event_id = ? AND user_id = ? LIMIT 1");
        $ins = $this->db->prepare("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel) VALUES (?, ?, ?, ?)");
        foreach ($userIds as $uid) {
            $has->execute([$eventId, $uid]);
            if ($has->fetchColumn()) continue;
            foreach ($template as $r) $ins->execute([$eventId, $uid, (int)$r['minutes_before'], $r['channel']]);
        }
    }

    private function setLinks(int $eventId, array $links): void
    {
        $this->db->prepare("DELETE FROM calendar_event_links WHERE event_id = ?")->execute([$eventId]);
        $ins = $this->db->prepare("INSERT INTO calendar_event_links (event_id, linked_type, linked_id, created_at) VALUES (?, ?, ?, NOW())");
        foreach ($links as $l) $ins->execute([$eventId, $l['type'], $l['id']]);
    }

    // ========================================================== notifications

    public function eventUrl(array $ev): string
    {
        return '/calendar/?event=' . (int)$ev['id'] . '&date=' . substr((string)$ev['start_datetime'], 0, 10);
    }

    /** "Tue 21 Oct, 09:00–10:00" · "Tue 21 Oct (all day)" · "21–23 Oct" */
    public static function whenText(array $ev): string
    {
        [$s, $e] = self::span($ev);
        $sd = new DateTimeImmutable($s);
        $ed = new DateTimeImmutable($e);
        if (!empty($ev['all_day'])) {
            if ($sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('D j M') . ' (all day)';
            return $sd->format('j M') . ' – ' . $ed->format('j M');
        }
        if ($sd->format('Y-m-d') === $ed->format('Y-m-d')) return $sd->format('D j M, H:i') . '–' . $ed->format('H:i');
        return $sd->format('D j M, H:i') . ' – ' . $ed->format('D j M, H:i');
    }

    private function userName(int $userId): string
    {
        $st = $this->db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? trim($u['first_name'] . ' ' . $u['last_name']) : 'Someone';
    }

    private function notifyUsers(array $userIds, string $type, string $title, string $message, string $url, array $push = []): void
    {
        if (!$userIds) return;
        require_once CalendarPaths::root() . '/includes/notify.php';
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid === $this->userId) continue;
            try {
                fw_notify($this->db, $this->companyId, $uid, $type, $title, $message, $url, $push + ['ch' => 'calendar', 'grp' => 'calendar']);
            } catch (Throwable $e) {
                error_log('[calendar] notify ' . $type . ': ' . $e->getMessage());
            }
        }
    }

    private function notifyInvite(int $eventId, array $userIds): void
    {
        $ev = $this->eventRow($eventId);
        if (!$ev) return;
        $me = $this->userName($this->userId);
        $msg = self::whenText($ev) . ($ev['location'] ? ' · ' . $ev['location'] : '');
        if ($ev['recurrence']) $msg .= ' · ' . Recurrence::describe($ev['recurrence'], self::span($ev)[0]);
        $this->notifyUsers($userIds, 'event_invite', $me . ' invited you: ' . $ev['title'], $msg, $this->eventUrl($ev),
                           ['who' => $me, 'tag' => 'cal:e' . $eventId]);
    }

    private function notifyChanged(?array $ev, array $userIds): void
    {
        if (!$ev || !$userIds) return;
        $msg = 'Now ' . self::whenText($ev) . ($ev['location'] ? ' · ' . $ev['location'] : '');
        $this->notifyUsers($userIds, 'event_updated', 'Updated: ' . $ev['title'], $msg, $this->eventUrl($ev),
                           ['who' => $this->userName($this->userId), 'tag' => 'cal:e' . (int)$ev['id']]);
    }

    private function notifyCancelled(array $ev, array $userIds, ?string $occ, bool $following = false): void
    {
        if (!$userIds) return;
        if ($occ) {
            $when = (new DateTimeImmutable($occ))->format($ev['all_day'] ? 'D j M' : 'D j M, H:i');
            $msg = ($following ? 'From ' : 'On ') . $when . ($following ? ' onwards' : '');
        } else {
            $msg = self::whenText($ev);
        }
        $this->notifyUsers($userIds, 'event_cancelled', 'Cancelled: ' . $ev['title'], $msg,
                           '/calendar/?date=' . substr((string)($occ ?: $ev['start_datetime']), 0, 10),
                           ['who' => $this->userName($this->userId), 'tag' => 'cal:e' . (int)$ev['id']]);
    }

    // ================================================================ misc

    public function audit(string $action, array $details): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$this->companyId, $this->userId, $action, json_encode($details, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) { /* audit is best-effort */ }
    }

    /** Run $fn under a short named lock so two tabs do not create the same default calendar twice. */
    private function withLock(string $name, callable $fn)
    {
        $lock = 'fwcal:' . $name . ':' . $this->companyId . ':' . ($name === 'own' ? $this->userId : 0);
        $got = false;
        try { $got = (bool)$this->db->query("SELECT GET_LOCK(" . $this->db->quote($lock) . ", 5)")->fetchColumn(); } catch (Throwable $e) {}
        try {
            return $fn();
        } finally {
            if ($got) { try { $this->db->query("SELECT RELEASE_LOCK(" . $this->db->quote($lock) . ")"); } catch (Throwable $e) {} }
        }
    }

    /** Sliding-window limiter (files under storage/calendar/ratelimit). Returns seconds to wait (0 = allowed). */
    public static function rateLimit(string $key, int $max, int $windowSeconds): int
    {
        $dir = CalendarPaths::storageDir();
        if ($dir === null) return 0;
        $dir .= '/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $fh = @fopen($dir . '/' . sha1($key) . '.json', 'c+');
        if (!$fh) return 0;
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $hits = json_decode(stream_get_contents($fh) ?: '[]', true);
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
            if (count($hits) >= $max) return max(1, $hits[0] + $windowSeconds - $now);
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            return 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
