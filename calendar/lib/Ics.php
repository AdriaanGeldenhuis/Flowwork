<?php
// calendar/lib/Ics.php
//
// iCalendar (RFC 5545) export and import.
// Export: times in UTC, all-day events as VALUE=DATE with an exclusive end,
// repeating events with RRULE/EXDATE, changed occurrences with RECURRENCE-ID,
// text escaped and lines folded at 75 octets.
// Import: unfolds lines, reads DATE / UTC / TZID / floating times into the
// company's time zone, DTEND or DURATION, RRULE (supported subset), EXDATE,
// RECURRENCE-ID, CLASS and STATUS:CANCELLED.

final class Ics
{
    /**
     * @param array $events rows: id, title, description, location, start_datetime, end_datetime, all_day,
     *                      recurrence, exdates_json, created_at, updated_at, ics_uid, busy (bool),
     *                      parent_uid + recurrence_id for a changed occurrence of a series
     */
    public static function build(string $calendarName, array $events, DateTimeZone $tz, string $host): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Flowwork//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
                  'X-WR-CALNAME:' . self::escape($calendarName), 'X-WR-TIMEZONE:' . $tz->getName(),
                  'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
        foreach ($events as $ev) {
            [$s, $e] = CalendarRepo::span($ev);
            $allDay = !empty($ev['all_day']);
            $uid = !empty($ev['parent_uid']) ? $ev['parent_uid'] : self::uid($ev, $host);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::escape($uid);
            $lines[] = 'DTSTAMP:' . self::utc((string)($ev['updated_at'] ?: $ev['created_at'] ?: 'now'), $tz);
            if (!empty($ev['parent_uid']) && !empty($ev['recurrence_id'])) {
                $lines[] = $allDay ? 'RECURRENCE-ID;VALUE=DATE:' . self::ymd($ev['recurrence_id'])
                                   : 'RECURRENCE-ID:' . self::utc($ev['recurrence_id'], $tz);
            }
            if ($allDay) {
                $lines[] = 'DTSTART;VALUE=DATE:' . self::ymd($s);
                $lines[] = 'DTEND;VALUE=DATE:' . (new DateTimeImmutable(substr($e, 0, 10)))->modify('+1 day')->format('Ymd');
            } else {
                $lines[] = 'DTSTART:' . self::utc($s, $tz);
                $lines[] = 'DTEND:' . self::utc($e, $tz);
            }
            if (!empty($ev['recurrence'])) {
                try {
                    $r = Recurrence::parse($ev['recurrence']);
                    $rule = Recurrence::build(['until' => null] + $r);
                    if ($r['until'] && !$r['count']) {
                        $rule .= ';UNTIL=' . ($allDay ? str_replace('-', '', $r['until']) : self::utc($r['until'] . ' 23:59:59', $tz));
                    }
                    $lines[] = 'RRULE:' . $rule;
                    $ex = array_keys(Recurrence::exdates($ev['exdates_json'] ?? null));
                    if ($ex) {
                        $time = substr($s, 11);
                        $lines[] = $allDay ? 'EXDATE;VALUE=DATE:' . implode(',', array_map(fn($d) => str_replace('-', '', $d), $ex))
                                           : 'EXDATE:' . implode(',', array_map(fn($d) => self::utc($d . ' ' . $time, $tz), $ex));
                    }
                } catch (CalendarUserError $x) { /* unsupported rule: export the first occurrence only */ }
            }
            if (!empty($ev['busy'])) {
                $lines[] = 'SUMMARY:Busy';
                $lines[] = 'CLASS:PRIVATE';
            } else {
                $lines[] = 'SUMMARY:' . self::escape((string)$ev['title']);
                if (trim((string)($ev['location'] ?? '')) !== '') $lines[] = 'LOCATION:' . self::escape((string)$ev['location']);
                if (trim((string)($ev['description'] ?? '')) !== '') $lines[] = 'DESCRIPTION:' . self::escape((string)$ev['description']);
                if (($ev['visibility'] ?? 'default') !== 'default') $lines[] = 'CLASS:PRIVATE';
            }
            if (!empty($ev['url'])) $lines[] = 'URL:' . $ev['url'];
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:' . ($allDay ? 'TRANSPARENT' : 'OPAQUE');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    public static function uid(array $ev, string $host): string
    {
        return !empty($ev['ics_uid']) ? (string)$ev['ics_uid'] : 'fw-cal-' . (int)$ev['id'] . '@' . $host;
    }

    /**
     * @return array{events: array, skipped: int, simplified: int}
     *   each event: uid, title, description, location, all_day, start, end, recurrence, exdates, recurrence_id, visibility
     */
    public static function parse(string $text, DateTimeZone $tz, int $max = 2000): array
    {
        $text = preg_replace("/^\xEF\xBB\xBF/", '', $text);
        $text = preg_replace("/\r?\n[ \t]/", '', $text);
        $events = [];
        $skipped = 0;
        $simplified = 0;
        $cur = null;
        $depth = 0; // nested components inside a VEVENT (VALARM)
        foreach (preg_split("/\r?\n|\r/", $text) as $line) {
            if ($line === '') continue;
            $up = strtoupper($line);
            if ($up === 'BEGIN:VEVENT') { $cur = []; $depth = 0; continue; }
            if ($cur === null) continue;
            if (str_starts_with($up, 'BEGIN:')) { $depth++; continue; }
            if (str_starts_with($up, 'END:') && $up !== 'END:VEVENT') { $depth = max(0, $depth - 1); continue; }
            if ($up === 'END:VEVENT') {
                $ev = self::toEvent($cur, $tz, $simplified);
                if ($ev) $events[] = $ev; else $skipped++;
                $cur = null;
                if (count($events) >= $max) break;
                continue;
            }
            if ($depth > 0) continue;
            $p = self::property($line);
            if (!$p) continue;
            if ($p['name'] === 'EXDATE') $cur['EXDATE'][] = $p;
            else $cur[$p['name']] = $p;
        }
        return ['events' => $events, 'skipped' => $skipped, 'simplified' => $simplified];
    }

    private static function toEvent(array $c, DateTimeZone $tz, int &$simplified): ?array
    {
        if (empty($c['DTSTART'])) return null;
        if (strtoupper(trim($c['STATUS']['value'] ?? '')) === 'CANCELLED') return null;
        $start = self::when($c['DTSTART'], $tz);
        if (!$start) return null;
        $allDay = $start['date'];
        if (!empty($c['DTEND']) && ($end = self::when($c['DTEND'], $tz))) {
            $endAt = $end['value'];
            if ($allDay) $endAt = (new DateTimeImmutable(substr($endAt, 0, 10)))->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        } elseif (!empty($c['DURATION']) && ($secs = self::duration($c['DURATION']['value'])) !== null) {
            $endAt = date('Y-m-d H:i:s', strtotime($start['value']) + $secs - ($allDay ? 1 : 0));
        } else {
            $endAt = $allDay ? substr($start['value'], 0, 10) . ' 23:59:59' : $start['value'];
        }
        if ($endAt < $start['value']) $endAt = $allDay ? substr($start['value'], 0, 10) . ' 23:59:59' : $start['value'];

        $rule = null;
        if (!empty($c['RRULE'])) {
            try { $rule = Recurrence::normalise($c['RRULE']['value']); } catch (CalendarUserError $e) { $simplified++; $rule = null; }
        }
        $exdates = [];
        foreach ($c['EXDATE'] ?? [] as $p) {
            foreach (explode(',', $p['value']) as $v) {
                $w = self::when(['value' => trim($v), 'params' => $p['params']], $tz);
                if ($w) $exdates[] = substr($w['value'], 0, 10);
            }
        }
        $class = strtoupper(trim($c['CLASS']['value'] ?? ''));
        $title = trim(self::unescape($c['SUMMARY']['value'] ?? ''));
        return [
            'uid'           => mb_substr(trim($c['UID']['value'] ?? ''), 0, 255) ?: null,
            'title'         => $title !== '' ? mb_substr($title, 0, 500) : '(No title)',
            'description'   => mb_substr(trim(self::unescape($c['DESCRIPTION']['value'] ?? '')), 0, 20000) ?: null,
            'location'      => mb_substr(trim(self::unescape($c['LOCATION']['value'] ?? '')), 0, 255) ?: null,
            'all_day'       => $allDay,
            'start'         => $allDay ? substr($start['value'], 0, 10) . ' 00:00:00' : $start['value'],
            'end'           => $endAt,
            'recurrence'    => $rule,
            'exdates'       => array_values(array_unique($exdates)),
            'recurrence_id' => !empty($c['RECURRENCE-ID']) && ($rid = self::when($c['RECURRENCE-ID'], $tz)) ? $rid['value'] : null,
            'visibility'    => in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true) ? 'private' : 'default',
        ];
    }

    /** name;PARAM=x;P2="y":value */
    private static function property(string $line): ?array
    {
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') $inQuote = !$inQuote;
            elseif ($ch === ':' && !$inQuote) break;
        }
        if ($i >= $len) return null;
        $head = substr($line, 0, $i);
        $value = substr($line, $i + 1);
        $parts = str_getcsv($head, ';', '"', '');
        $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) {
            if (!str_contains($p, '=')) continue;
            [$k, $v] = explode('=', $p, 2);
            $params[strtoupper($k)] = trim($v, '"');
        }
        return ['name' => $name, 'params' => $params, 'value' => $value];
    }

    /** @return array{value:string, date:bool}|null value in the company time zone */
    private static function when(array $p, DateTimeZone $tz): ?array
    {
        $v = trim($p['value']);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? ['value' => "$m[1]-$m[2]-$m[3] 00:00:00", 'date' => true] : null;
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/i', $v, $m)) return null;
        $local = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?: '00');
        try {
            if (!empty($m[7])) {
                $src = new DateTimeZone('UTC');
            } elseif (!empty($p['params']['TZID'])) {
                $src = self::zone($p['params']['TZID']) ?? $tz;
            } else {
                $src = $tz; // floating time: as written
            }
            $d = new DateTimeImmutable($local, $src);
            return ['value' => $d->setTimezone($tz)->format('Y-m-d H:i:s'), 'date' => false];
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function zone(string $id): ?DateTimeZone
    {
        $id = trim($id, '/ ');
        $windows = ['South Africa Standard Time' => 'Africa/Johannesburg', 'GMT Standard Time' => 'Europe/London',
                    'W. Europe Standard Time' => 'Europe/Berlin', 'Eastern Standard Time' => 'America/New_York',
                    'Pacific Standard Time' => 'America/Los_Angeles', 'Central Standard Time' => 'America/Chicago',
                    'UTC' => 'UTC', 'Coordinated Universal Time' => 'UTC', 'E. Africa Standard Time' => 'Africa/Nairobi',
                    'Arabian Standard Time' => 'Asia/Dubai', 'AUS Eastern Standard Time' => 'Australia/Sydney'];
        try { return new DateTimeZone($windows[$id] ?? $id); } catch (Throwable $e) { return null; }
    }

    /** ISO 8601 duration (P1DT2H, PT30M, P1W) in seconds. */
    private static function duration(string $v): ?int
    {
        if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim($v), $m)) return null;
        $s = ((int)($m[2] ?? 0)) * 604800 + ((int)($m[3] ?? 0)) * 86400 + ((int)($m[4] ?? 0)) * 3600 + ((int)($m[5] ?? 0)) * 60 + (int)($m[6] ?? 0);
        return ($m[1] ?? '') === '-' ? null : $s;
    }

    private static function utc(string $local, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable($local, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function ymd(string $v): string
    {
        return str_replace('-', '', substr($v, 0, 10));
    }

    public static function escape(string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
    }

    public static function unescape(string $s): string
    {
        return preg_replace_callback('/\\\\([\\\\;,nN])/', fn($m) => ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1], $s);
    }

    /** Fold to 75 octets per line without splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $out = [];
        $cur = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line) as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) {
                $out[] = $cur;
                $cur = ' ';
                $limit = 75;
            }
            $cur .= $ch;
        }
        $out[] = $cur;
        return implode("\r\n", $out);
    }
}
