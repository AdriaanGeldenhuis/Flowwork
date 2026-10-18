<?php
// home/lib/HomeBrief.php
//
// The "today" data behind the Flowwork home page: the user's calendar for
// today, their open Projects work and their unread mail. Each part is
// best-effort and independent — a module that is missing, not set up yet or
// failing returns ['ok' => false] and the home page shows its plain fallback.
// Everything is scoped to the signed-in user in the active company through the
// modules' own access rules (CalendarRepo, ProjectLink, MailRepo).

class HomeBrief
{
    /** Local "now" (Y-m-d H:i:s) in the company's calendar timezone, else the server's. */
    public static function now(PDO $db, int $companyId): string
    {
        try {
            self::loadCalendar();
            return CalendarRepo::nowLocal($db, $companyId);
        } catch (Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    /** @return array{calendar:array, work:array, mail:array} */
    public static function collect(PDO $db, int $companyId, int $userId, string $role, string $now): array
    {
        return [
            'calendar' => self::calendar($db, $companyId, $userId, $role, $now),
            'work'     => self::work($db, $companyId, $userId, $role),
            'mail'     => self::mail($db, $companyId, $userId),
        ];
    }

    // ---------------------------------------------------------------- calendar

    /**
     * Today's events the user takes part in (busy blocks of other people's
     * private calendars and declined invitations left out), all-day first.
     */
    public static function calendar(PDO $db, int $companyId, int $userId, string $role, string $now): array
    {
        $out = ['ok' => false, 'events' => [], 'count' => 0, 'next' => null];
        try {
            self::loadCalendar();
            CalendarSchema::ensure($db);
            $repo = new CalendarRepo($db, $companyId, $userId, $role);
            $day = substr($now, 0, 10);
            $feed = array_values(array_filter($repo->feed($day . ' 00:00:00', $day . ' 23:59:59'),
                fn($e) => !$e['busy'] && $e['response'] !== 'declined'));

            $events = [];
            foreach ($feed as $e) {
                $allDay = !empty($e['all_day']);
                $start = (string)$e['start'];
                $end = (string)$e['end'];
                $events[] = [
                    'id'       => (int)$e['id'],
                    'title'    => (string)$e['title'],
                    'location' => $e['location'] !== null ? (string)$e['location'] : '',
                    'calendar' => $e['calendar_name'] !== null ? (string)$e['calendar_name'] : '',
                    'color'    => self::color($e['color'] ?? null, '#7a1f30'),
                    'all_day'  => $allDay,
                    'start'    => $start,
                    'end'      => $end,
                    'time'     => $allDay ? '' : substr($start, 11, 5),
                    'until'    => $allDay ? '' : (substr($end, 0, 10) === $day ? substr($end, 11, 5) : ''),
                    'past'     => !$allDay && $end < $now,
                    'live'     => !$allDay && $start <= $now && $end >= $now,
                    'url'      => '/calendar/?' . http_build_query(['view' => 'day', 'date' => $day, 'event' => (int)$e['id']]),
                ];
            }
            usort($events, fn($a, $b) => ($b['all_day'] <=> $a['all_day']) ?: strcmp($a['start'], $b['start']));

            $next = null;
            foreach ($events as $e) {
                if ($e['all_day'] || $e['past']) continue;
                if ($next === null || $e['start'] < $next['start']) $next = $e;
            }
            $out = ['ok' => true, 'events' => $events, 'count' => count($events), 'next' => $next];
        } catch (Throwable $e) {
            error_log('[home] calendar: ' . $e->getMessage());
        }
        return $out;
    }

    // ------------------------------------------------------------------- work

    /** Counts from ProjectLink::myWorkSummary plus the first open items, soonest due first. */
    public static function work(PDO $db, int $companyId, int $userId, string $role): array
    {
        $out = ['ok' => false, 'overdue' => 0, 'today' => 0, 'week' => 0, 'open' => 0, 'projects' => 0, 'items' => []];
        try {
            self::loadProjects();
            $sum = ProjectLink::myWorkSummary($db, $companyId, $userId, $role);
            $items = [];
            if ($sum['open'] > 0) {
                $today = ProjectRepo::today();
                foreach (ProjectLink::myWork(ProjectLink::repo($db, $companyId, $userId, $role), 5) as $it) {
                    $due = $it['due'];
                    $items[] = [
                        'title'   => (string)$it['title'],
                        'project' => (string)$it['project'],
                        'board'   => (string)$it['board'],
                        'color'   => self::color($it['color'] ?? null, '#7a1f30'),
                        'due'     => $due,
                        'when'    => $due === null ? 'none' : ($due < $today ? 'overdue' : ($due === $today ? 'today' : 'later')),
                        'status'  => $it['status'] ? ['label' => (string)$it['status']['label'], 'color' => self::color($it['status']['color'] ?? null, '#85766b')] : null,
                        'url'     => (string)$it['url'],
                    ];
                }
            }
            $out = ['ok' => true] + $sum + ['items' => $items];
        } catch (Throwable $e) {
            error_log('[home] projects: ' . $e->getMessage());
        }
        return $out;
    }

    // ------------------------------------------------------------------- mail

    /** Unread inbox conversations (count + the newest four) of the user's own mailboxes. */
    public static function mail(PDO $db, int $companyId, int $userId): array
    {
        $out = ['ok' => false, 'accounts' => 0, 'unread' => 0, 'drafts' => 0, 'threads' => []];
        try {
            $lib = dirname(__DIR__, 2) . '/mail/lib/autoload.php';
            if (!is_file($lib)) return $out;
            require_once $lib;
            MailSchema::ensure($db);
            $accounts = count(MailRepo::accounts($db, $companyId, $userId));
            if ($accounts === 0) return ['ok' => true, 'accounts' => 0] + $out;

            $counts = MailRepo::counts($db, $companyId, $userId);
            $threads = [];
            if ($counts['inbox'] > 0) {
                $list = MailRepo::listThreads($db, $companyId, $userId, ['view' => 'inbox', 'filter' => 'unread', 'limit' => 10]);
                foreach (array_slice($list['threads'], 0, 4) as $t) {
                    // Participants in the order they joined ("Alice, me"); the avatar is the first who is not me.
                    $names = array_map('strval', $t['participants']);
                    $other = array_values(array_filter($names, fn($n) => $n !== 'me'));
                    $threads[] = [
                        'from'    => $names ? implode(', ', $names) : '(unknown)',
                        'who'     => $other[0] ?? ($names[0] ?? '?'),
                        'subject' => trim($t['subject']) !== '' ? (string)$t['subject'] : '(no subject)',
                        'snippet' => (string)$t['snippet'],
                        'date'    => (string)($t['date'] ?? ''),
                        'count'   => (int)$t['count'],
                        'url'     => '/mail/?open=' . rawurlencode($t['key']),
                    ];
                }
            }
            $out = ['ok' => true, 'accounts' => $accounts, 'unread' => (int)$counts['inbox'],
                    'drafts' => (int)$counts['drafts'], 'threads' => $threads];
        } catch (Throwable $e) {
            error_log('[home] mail: ' . $e->getMessage());
        }
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /** A #rgb / #rrggbb colour, else the fallback — values go into style="--c:…". */
    public static function color($value, string $fallback): string
    {
        $v = is_string($value) ? trim($value) : '';
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? $v : $fallback;
    }

    private static function loadCalendar(): void
    {
        require_once dirname(__DIR__, 2) . '/calendar/lib/autoload.php';
    }

    private static function loadProjects(): void
    {
        require_once dirname(__DIR__, 2) . '/projects/lib/autoload.php';
    }
}
