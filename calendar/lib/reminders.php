<?php
// /calendar/lib/reminders.php
//
// Sends calendar reminders that are due (email, or bell + phone notification).
// Used by calendar/cron/send_reminders.php (CLI) and by the web tick
// notifications/api/tick.php, which runs every minute: a reminder is only
// sent between its reminder time and the event start, so the job has to run
// often (the host's own cron is limited to every two hours).
//
// Event times are wall-clock times in the company's calendar time zone, so
// "now" is taken in that zone per company — not the server's. Every attendee
// has their own reminder rows; people who declined get nothing. Repeating
// events remind before each occurrence (last_occurrence remembers the one
// already sent); one-off events set sent_at.

require_once __DIR__ . '/autoload.php';

/** Seconds after the start at which a missed reminder is no longer worth sending. */
const FW_CAL_REMINDER_GRACE = 300;

/**
 * @return int reminders sent
 */
function fw_calendar_send_due_reminders(PDO $db): int
{
    CalendarSchema::ensure($db);
    $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // Wide SQL window (any time zone, leads up to four weeks); the exact check is per company below.
    $lo = $utc->modify('-1 day')->format('Y-m-d H:i:s');
    $hi = $utc->modify('+29 days')->format('Y-m-d H:i:s');
    $dueBy = $utc->modify('+15 hours')->format('Y-m-d H:i:s');

    $cols = "r.id AS reminder_id, r.event_id, r.user_id, r.minutes_before, r.channel, r.snoozed_until, r.last_occurrence,
             e.title AS event_title, e.start_datetime, e.end_datetime, e.all_day, e.location, e.description, e.company_id,
             e.recurrence, e.exdates_json, u.email, u.first_name, c.name AS company_name, p.response";
    $from = "FROM calendar_event_reminders r
             JOIN calendar_events e ON e.id = r.event_id
             JOIN users u ON u.id = r.user_id AND u.status = 'active'
             JOIN companies c ON c.id = e.company_id
        LEFT JOIN calendar_event_participants p ON p.event_id = e.id AND p.user_id = r.user_id";

    $single = $db->prepare("SELECT $cols $from
        WHERE r.sent_at IS NULL AND (e.recurrence IS NULL OR e.recurrence = '')
          AND e.start_datetime BETWEEN ? AND ?
          AND DATE_SUB(e.start_datetime, INTERVAL r.minutes_before MINUTE) <= ?
     ORDER BY e.start_datetime LIMIT 500");
    $single->execute([$lo, $hi, $dueBy]);

    $series = $db->prepare("SELECT $cols $from
        WHERE e.recurrence IS NOT NULL AND e.recurrence <> '' AND e.start_datetime <= ?
     ORDER BY r.id LIMIT 3000");
    $series->execute([$hi]);

    $markSent = $db->prepare("UPDATE calendar_event_reminders SET sent_at = NOW(), snoozed_until = NULL WHERE id = ?");
    $markOcc = $db->prepare("UPDATE calendar_event_reminders SET last_occurrence = ?, snoozed_until = NULL WHERE id = ?");
    $sent = 0;

    foreach ($single->fetchAll(PDO::FETCH_ASSOC) as $rem) {
        $now = CalendarRepo::nowLocal($db, (int)$rem['company_id']);
        [$start] = CalendarRepo::span($rem);
        $state = fw_calendar_reminder_state($rem, $start, $now);
        if ($state === 'wait') continue;
        if ($state === 'send' && fw_calendar_deliver_reminder($db, $rem, $start, $now)) $sent++;
        elseif ($state === 'send') continue; // delivery failed: retry on the next run
        // Sent, expired, or the attendee declined: done with this one.
        $markSent->execute([$rem['reminder_id']]);
    }

    foreach ($series->fetchAll(PDO::FETCH_ASSOC) as $rem) {
        if (($rem['response'] ?? null) === 'declined') continue;
        $now = CalendarRepo::nowLocal($db, (int)$rem['company_id']);
        [$s, $e] = CalendarRepo::span($rem);
        $lead = (int)$rem['minutes_before'] * 60;
        try {
            $next = Recurrence::nextStart($s, $e, $rem['recurrence'], Recurrence::exdates($rem['exdates_json']),
                                          date('Y-m-d H:i:s', strtotime($now) - FW_CAL_REMINDER_GRACE),
                                          date('Y-m-d H:i:s', strtotime($now) + $lead + 86400));
        } catch (Throwable $x) {
            continue;
        }
        if (!$next || $next['start'] === $rem['last_occurrence']) continue;
        $occ = $rem;
        $occ['start_datetime'] = $next['start'];
        $occ['end_datetime'] = $next['end'];
        $state = fw_calendar_reminder_state($occ, $next['start'], $now);
        if ($state !== 'send') continue;
        if (fw_calendar_deliver_reminder($db, $occ, $next['start'], $now)) {
            $markOcc->execute([$next['start'], $rem['reminder_id']]);
            $sent++;
        }
    }
    return $sent;
}

/** 'send' | 'wait' | 'done' (expired or declined) */
function fw_calendar_reminder_state(array $rem, string $start, string $now): string
{
    if (($rem['response'] ?? null) === 'declined') return 'done';
    $startTs = strtotime($start);
    $nowTs = strtotime($now);
    if ($nowTs > $startTs + FW_CAL_REMINDER_GRACE) return 'done';
    if (!empty($rem['snoozed_until']) && $nowTs < strtotime($rem['snoozed_until'])) return 'wait';
    return $nowTs >= $startTs - (int)$rem['minutes_before'] * 60 ? 'send' : 'wait';
}

function fw_calendar_deliver_reminder(PDO $db, array $rem, string $start, string $now): bool
{
    $rem['when_text'] = fw_calendar_reminder_when($start, !empty($rem['all_day']), $now);
    $rem['occ_date'] = substr($start, 0, 10);
    if ($rem['channel'] === 'email') return fw_calendar_reminder_email($rem);
    return fw_calendar_reminder_in_app($db, $rem, $start);
}

/** "Today at 09:00", "Tomorrow (all day)", "Tue 21 Oct at 14:30" */
function fw_calendar_reminder_when(string $start, bool $allDay, string $now): string
{
    $d = new DateTimeImmutable($start);
    $today = substr($now, 0, 10);
    $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $day = $d->format('Y-m-d') === $today ? 'Today' : ($d->format('Y-m-d') === $tomorrow ? 'Tomorrow' : $d->format('D j M'));
    return $allDay ? $day . ' (all day)' : $day . ' at ' . $d->format('H:i');
}

/** Site host for links and the From address; there is no HTTP_HOST under cron. */
function fw_calendar_reminder_host(): string {
    $host = parse_url(defined('APP_BASE_URL') ? APP_BASE_URL : '', PHP_URL_HOST);
    return $host ?: (string)($_SERVER['HTTP_HOST'] ?? 'www.flowwork.app');
}

function fw_calendar_reminder_email(array $reminder): bool {
    $to = trim((string)$reminder['email']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return true; // nothing we can deliver to; do not retry forever
    $host = fw_calendar_reminder_host();
    $mailHost = preg_replace('/^www\./', '', $host);
    $title = trim(preg_replace('/[\r\n]+/', ' ', (string)$reminder['event_title']));
    $subject = 'Reminder: ' . $title . ' — ' . $reminder['when_text'];

    $body = "Hi " . $reminder['first_name'] . ",\n\n";
    $body .= "This is a reminder for your upcoming event:\n\n";
    $body .= "EVENT: " . $title . "\n";
    $body .= "WHEN:  " . $reminder['when_text'] . "\n";
    if ($reminder['location']) {
        $body .= "WHERE: " . $reminder['location'] . "\n";
    }
    if ($reminder['description']) {
        $body .= "\n" . $reminder['description'] . "\n";
    }
    $body .= "\nOpen in Flowwork: https://" . $host . "/calendar/?event=" . (int)$reminder['event_id'] . "&date=" . $reminder['occ_date'] . "\n\n";
    $body .= "---\n";
    $body .= $reminder['company_name'] . " Calendar\n";

    $headers = "From: " . mb_encode_mimeheader($reminder['company_name'] . ' Calendar', 'UTF-8') . " <noreply@" . $mailHost . ">\r\n";
    $headers .= "Reply-To: noreply@" . $mailHost . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "X-Mailer: Flowwork Calendar";

    return (bool)mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);
}

function fw_calendar_reminder_in_app(PDO $db, array $reminder, string $start): bool {
    require_once __DIR__ . '/../../includes/notify.php';

    $message = $reminder['when_text'];
    if ($reminder['location']) {
        $message .= ' · ' . $reminder['location'];
    }
    $tz = CalendarRepo::companyTz($db, (int)$reminder['company_id']);
    $startTs = (new DateTimeImmutable($start, $tz))->getTimestamp();

    // Bell + phone. A reminder that reaches a phone only after the event
    // started is useless, so it expires shortly after the start time.
    $id = fw_notify($db, (int)$reminder['company_id'], (int)$reminder['user_id'], 'calendar_reminder',
                    (string)$reminder['event_title'], $message,
                    '/calendar/?event=' . (int)$reminder['event_id'] . '&date=' . $reminder['occ_date'], [
                        'ch' => 'calendar', 'grp' => 'calendar',
                        'tag' => 'cal:r' . (int)$reminder['reminder_id'] . ':' . $reminder['occ_date'],
                        'ttl' => max(300, $startTs - time() + 900),
                    ]);
    if (!$id) {
        error_log("In-app notification error: reminder " . (int)$reminder['reminder_id'] . " could not be stored");
    }
    return $id !== null;
}
