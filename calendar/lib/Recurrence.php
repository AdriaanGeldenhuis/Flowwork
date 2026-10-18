<?php
// calendar/lib/Recurrence.php
//
// Repeating events, stored as an RFC 5545 RRULE on calendar_events.recurrence.
// Supported: FREQ=DAILY|WEEKLY|MONTHLY|YEARLY, INTERVAL, COUNT, UNTIL,
// BYDAY (weekly: MO,WE…; monthly: 2TU, -1FR) and BYMONTHDAY (monthly: 15, -1).
// Skipped occurrences live in calendar_events.exdates_json as ['Y-m-d', …].
// All times are wall-clock times in the company's time zone (as stored).

final class Recurrence
{
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    private const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    private const ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', -1 => 'last', -2 => 'second-last'];
    private const MAX_ITERATIONS = 20000;

    /**
     * Parse an RRULE ("FREQ=WEEKLY;BYDAY=MO,WE" or "RRULE:FREQ=…").
     * @return array{freq:string, interval:int, count:?int, until:?string, byday:array, bymonthday:int[]}|null
     *         null when empty; throws CalendarUserError for a rule we cannot repeat correctly.
     */
    public static function parse(?string $rule): ?array
    {
        $rule = trim((string)$rule);
        if ($rule === '') return null;
        if (stripos($rule, 'RRULE:') === 0) $rule = substr($rule, 6);
        $parts = [];
        foreach (explode(';', $rule) as $p) {
            if ($p === '' || !str_contains($p, '=')) continue;
            [$k, $v] = explode('=', $p, 2);
            $parts[strtoupper(trim($k))] = strtoupper(trim($v));
        }
        $freq = $parts['FREQ'] ?? '';
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            throw new CalendarUserError('This repeat rule is not supported.');
        }
        foreach (array_keys($parts) as $k) {
            if (!in_array($k, ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'WKST'], true)) {
                throw new CalendarUserError('This repeat rule is not supported.');
            }
        }
        $interval = max(1, min(999, (int)($parts['INTERVAL'] ?? 1)));
        $count = isset($parts['COUNT']) ? max(1, min(1000, (int)$parts['COUNT'])) : null;
        $until = null;
        if (!empty($parts['UNTIL'])) {
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $parts['UNTIL'], $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new CalendarUserError('The repeat end date is not valid.');
            }
            $until = "$m[1]-$m[2]-$m[3]";
        }
        $byday = [];
        if (!empty($parts['BYDAY'])) {
            foreach (explode(',', $parts['BYDAY']) as $d) {
                if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim($d), $m)) {
                    throw new CalendarUserError('This repeat rule is not supported.');
                }
                $n = ($m[1] ?? '') === '' ? 0 : (int)$m[1];
                if ($n !== 0 && ($freq !== 'MONTHLY' || $n < -5 || $n > 5)) {
                    throw new CalendarUserError('This repeat rule is not supported.');
                }
                $byday[] = ['wd' => self::DAYS[$m[2]], 'n' => $n];
            }
            if ($freq === 'DAILY' || $freq === 'YEARLY') throw new CalendarUserError('This repeat rule is not supported.');
        }
        $bymonthday = [];
        if (!empty($parts['BYMONTHDAY'])) {
            if ($freq !== 'MONTHLY') throw new CalendarUserError('This repeat rule is not supported.');
            foreach (explode(',', $parts['BYMONTHDAY']) as $d) {
                $d = (int)$d;
                if ($d === 0 || $d < -31 || $d > 31) throw new CalendarUserError('This repeat rule is not supported.');
                $bymonthday[] = $d;
            }
        }
        return ['freq' => $freq, 'interval' => $interval, 'count' => $count, 'until' => $until,
                'byday' => $byday, 'bymonthday' => $bymonthday];
    }

    /** Canonical RRULE string for a parsed rule. */
    public static function build(array $r): string
    {
        $out = ['FREQ=' . $r['freq']];
        if ($r['interval'] > 1) $out[] = 'INTERVAL=' . $r['interval'];
        if ($r['byday']) {
            $names = array_flip(self::DAYS);
            $out[] = 'BYDAY=' . implode(',', array_map(fn($d) => ($d['n'] ? $d['n'] : '') . $names[$d['wd']], $r['byday']));
        }
        if ($r['bymonthday']) $out[] = 'BYMONTHDAY=' . implode(',', $r['bymonthday']);
        if ($r['count']) $out[] = 'COUNT=' . $r['count'];
        elseif ($r['until']) $out[] = 'UNTIL=' . str_replace('-', '', $r['until']);
        return implode(';', $out);
    }

    /** Validate a user-supplied rule; returns the canonical form or null for "does not repeat". */
    public static function normalise(?string $rule): ?string
    {
        $r = self::parse($rule);
        return $r ? self::build($r) : null;
    }

    /** Exception dates from calendar_events.exdates_json. @return array<string,true> */
    public static function exdates(?string $json): array
    {
        $out = [];
        foreach ((array)json_decode((string)$json, true) as $d) {
            if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) $out[substr($d, 0, 10)] = true;
        }
        return $out;
    }

    /**
     * Occurrences of a series that overlap [$from, $to] (inclusive, 'Y-m-d H:i:s').
     * @return array<int, array{start:string, end:string}>
     */
    public static function occurrences(string $start, string $end, ?string $rule, array $exdates,
                                       string $from, string $to, int $max = 500): array
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $dur = max(0, $e->getTimestamp() - $s->getTimestamp());
        $fromDt = new DateTimeImmutable($from);
        $toDt = new DateTimeImmutable($to);
        $r = $rule ? self::parse($rule) : null;
        if (!$r) {
            return ($s <= $toDt && $e >= $fromDt) ? [['start' => $s->format('Y-m-d H:i:s'), 'end' => $e->format('Y-m-d H:i:s')]] : [];
        }
        $out = [];
        $n = 0;
        // Without COUNT the series can be entered just before the window instead of at its start.
        $skipTo = $r['count'] ? null : $fromDt->modify('-' . ($dur + 86400) . ' seconds');
        foreach (self::candidates($s, $r, $skipTo) as $c) {
            if ($c < $s) continue;
            if ($r['until'] !== null && $c->format('Y-m-d') > $r['until']) break;
            if ($c > $toDt) break;
            $n++;
            if ($r['count'] !== null && $n > $r['count']) break;
            $cEnd = $c->modify('+' . $dur . ' seconds');
            if ($cEnd < $fromDt || isset($exdates[$c->format('Y-m-d')])) continue;
            $out[] = ['start' => $c->format('Y-m-d H:i:s'), 'end' => $cEnd->format('Y-m-d H:i:s')];
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /** First occurrence starting at or after $after (for reminders), or null. */
    public static function nextStart(string $start, string $end, string $rule, array $exdates, string $after, string $limit): ?array
    {
        $after = new DateTimeImmutable($after);
        foreach (self::occurrences($start, $end, $rule, $exdates, $after->format('Y-m-d H:i:s'), $limit, 50) as $o) {
            if (new DateTimeImmutable($o['start']) >= $after) return $o;
        }
        return null;
    }

    /** True when the series still has an occurrence on or after the given date. */
    public static function endsBefore(?string $rule, string $date): bool
    {
        $r = $rule ? self::parse($rule) : null;
        return $r && $r['until'] !== null && $r['until'] < $date;
    }

    /** The rule cut so its last occurrence is before $occDate ('Y-m-d'). Used for "this and following". */
    public static function untilBefore(string $rule, string $occDate): string
    {
        $r = self::parse($rule);
        $r['count'] = null;
        $r['until'] = (new DateTimeImmutable($occDate))->modify('-1 day')->format('Y-m-d');
        return self::build($r);
    }

    /** Same rule with COUNT reduced by the occurrences already used before $occStart (for a split series). */
    public static function remainder(string $rule, string $seriesStart, string $occStart): string
    {
        $r = self::parse($rule);
        if ($r['count']) {
            // COUNT counts generated occurrences, skipped (EXDATE) ones included.
            $s = new DateTimeImmutable($seriesStart);
            $occ = new DateTimeImmutable($occStart);
            $before = 0;
            foreach (self::candidates($s, $r, null) as $c) {
                if ($c < $s) continue;
                if ($c >= $occ || $before >= $r['count']) break;
                $before++;
            }
            $r['count'] = max(1, $r['count'] - $before);
        }
        return self::build($r);
    }

    /** Human description, e.g. "Every 2 weeks on Monday, Wednesday · 10 times". */
    public static function describe(?string $rule, string $start): string
    {
        try { $r = self::parse($rule); } catch (CalendarUserError $e) { return 'Repeats'; }
        if (!$r) return '';
        $s = new DateTimeImmutable($start);
        $iv = $r['interval'];
        switch ($r['freq']) {
            case 'DAILY':
                $txt = $iv === 1 ? 'Daily' : "Every $iv days";
                break;
            case 'WEEKLY':
                $days = $r['byday'] ? array_map(fn($d) => $d['wd'], $r['byday']) : [(int)$s->format('N')];
                sort($days);
                if ($days === [1, 2, 3, 4, 5] && $iv === 1) { $txt = 'Every weekday (Mon–Fri)'; break; }
                $names = array_map(fn($d) => self::DAY_NAMES[$d], $days);
                $txt = ($iv === 1 ? 'Weekly' : "Every $iv weeks") . ' on ' . implode(', ', $names);
                break;
            case 'MONTHLY':
                $txt = $iv === 1 ? 'Monthly' : "Every $iv months";
                if ($r['byday']) {
                    $d = $r['byday'][0];
                    $txt .= ' on the ' . (self::ORDINALS[$d['n']] ?? ($d['n'] . 'th')) . ' ' . self::DAY_NAMES[$d['wd']];
                } elseif ($r['bymonthday']) {
                    $md = $r['bymonthday'][0];
                    $txt .= $md === -1 ? ' on the last day' : ' on day ' . $md;
                } else {
                    $txt .= ' on day ' . (int)$s->format('j');
                }
                break;
            default:
                $txt = ($iv === 1 ? 'Annually' : "Every $iv years") . ' on ' . $s->format('j F');
        }
        if ($r['count']) $txt .= ' · ' . $r['count'] . ' time' . ($r['count'] === 1 ? '' : 's');
        elseif ($r['until']) $txt .= ' · until ' . (new DateTimeImmutable($r['until']))->format('j M Y');
        return $txt;
    }

    /**
     * Candidate occurrence starts in chronological order (before COUNT / UNTIL / EXDATE).
     * @return Generator<DateTimeImmutable>
     */
    private static function candidates(DateTimeImmutable $s, array $r, ?DateTimeImmutable $skipTo): Generator
    {
        $iv = $r['interval'];
        $time = [(int)$s->format('H'), (int)$s->format('i'), (int)$s->format('s')];
        $guard = 0;
        switch ($r['freq']) {
            case 'DAILY':
                $k = 0;
                if ($skipTo && $skipTo > $s) $k = max(0, intdiv(self::daysBetween($s, $skipTo), $iv) - 1);
                while ($guard++ < self::MAX_ITERATIONS) {
                    yield $s->modify('+' . ($k * $iv) . ' days');
                    $k++;
                }
                return;
            case 'WEEKLY':
                $days = $r['byday'] ? array_map(fn($d) => $d['wd'], $r['byday']) : [(int)$s->format('N')];
                $days = array_values(array_unique($days));
                sort($days);
                $anchor = $s->modify('-' . ((int)$s->format('N') - 1) . ' days')->setTime(...$time);
                $k = 0;
                if ($skipTo && $skipTo > $s) $k = max(0, intdiv(intdiv(self::daysBetween($anchor, $skipTo), 7), $iv) - 1);
                while ($guard++ < self::MAX_ITERATIONS) {
                    $weekStart = $anchor->modify('+' . ($k * $iv * 7) . ' days');
                    foreach ($days as $wd) yield $weekStart->modify('+' . ($wd - 1) . ' days');
                    $k++;
                }
                return;
            case 'MONTHLY':
                $first = $s->modify('first day of this month')->setTime(...$time);
                $k = 0;
                if ($skipTo && $skipTo > $s) {
                    $months = ((int)$skipTo->format('Y') - (int)$s->format('Y')) * 12 + (int)$skipTo->format('n') - (int)$s->format('n');
                    $k = max(0, intdiv($months, $iv) - 1);
                }
                while ($guard++ < self::MAX_ITERATIONS) {
                    $month = $first->modify('+' . ($k * $iv) . ' months');
                    $dim = (int)$month->format('t');
                    $dates = [];
                    if ($r['byday']) {
                        foreach ($r['byday'] as $d) {
                            $day = self::nthWeekday($month, $d['wd'], $d['n'] ?: 1);
                            if ($day !== null) $dates[] = $day;
                        }
                    } elseif ($r['bymonthday']) {
                        foreach ($r['bymonthday'] as $md) {
                            $day = $md > 0 ? $md : $dim + $md + 1;
                            if ($day >= 1 && $day <= $dim) $dates[] = $day;
                        }
                    } else {
                        $day = (int)$s->format('j');
                        if ($day <= $dim) $dates[] = $day;
                    }
                    $dates = array_values(array_unique($dates));
                    sort($dates);
                    foreach ($dates as $day) yield $month->modify('+' . ($day - 1) . ' days');
                    $k++;
                }
                return;
            default: // YEARLY
                $k = 0;
                if ($skipTo && $skipTo > $s) $k = max(0, intdiv((int)$skipTo->format('Y') - (int)$s->format('Y'), $iv) - 1);
                $m = (int)$s->format('n');
                $d = (int)$s->format('j');
                while ($guard++ < self::MAX_ITERATIONS) {
                    $y = (int)$s->format('Y') + $k * $iv;
                    if (checkdate($m, $d, $y)) yield $s->setDate($y, $m, $d);
                    $k++;
                }
        }
    }

    /** Day of month of the n-th (1..5, or -1..-5 from the end) weekday in the month, or null. */
    private static function nthWeekday(DateTimeImmutable $monthStart, int $wd, int $n): ?int
    {
        $dim = (int)$monthStart->format('t');
        $firstWd = (int)$monthStart->format('N');
        if ($n > 0) {
            $day = 1 + (($wd - $firstWd + 7) % 7) + ($n - 1) * 7;
        } else {
            $lastWd = (int)$monthStart->modify('+' . ($dim - 1) . ' days')->format('N');
            $day = $dim - (($lastWd - $wd + 7) % 7) + ($n + 1) * 7;
        }
        return ($day >= 1 && $day <= $dim) ? $day : null;
    }

    private static function daysBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return (int)$a->setTime(0, 0)->diff($b->setTime(0, 0))->format('%r%a');
    }
}
