<?php
// /crm/includes/calendar_followup.php
// Creates follow-up reminders for CRM records by riding the calendar stack:
// a calendar_events row + a polymorphic calendar_event_links row + one
// calendar_event_reminders row per channel. Delivery (email / in-app
// notification) is then handled by the existing calendar/cron/send_reminders.php.
// Pattern lifted from qi/lib/CalendarLinker.php.

/**
 * Create a follow-up calendar event linked to a CRM record.
 *
 * @param PDO    $DB
 * @param int    $companyId
 * @param int    $userId        Creator and reminder recipient.
 * @param string $linkedType    'account' or 'opportunity'.
 * @param int    $linkedId
 * @param string $title
 * @param string $dueDatetime   'Y-m-d H:i:s' (or 'Y-m-d\TH:i' from datetime-local).
 * @param int    $minutesBefore Reminder lead time.
 * @param array  $channels      Any of ['email', 'in_app'].
 * @return int   The calendar event id.
 * @throws Exception on validation problems.
 */
function crm_create_followup(
    PDO $DB,
    int $companyId,
    int $userId,
    string $linkedType,
    int $linkedId,
    string $title,
    string $dueDatetime,
    int $minutesBefore = 60,
    array $channels = ['in_app']
): int {
    if (!in_array($linkedType, ['account', 'opportunity'])) {
        throw new Exception('Invalid follow-up target type');
    }

    $title = trim($title);
    if ($title === '') {
        throw new Exception('Follow-up title is required');
    }

    // Normalize datetime-local ("2026-07-04T15:30") to SQL format
    $dueDatetime = str_replace('T', ' ', trim($dueDatetime));
    if (strlen($dueDatetime) === 16) {
        $dueDatetime .= ':00';
    }
    $ts = strtotime($dueDatetime);
    if ($ts === false) {
        throw new Exception('Invalid follow-up date/time');
    }
    $start = date('Y-m-d H:i:s', $ts);
    $end = date('Y-m-d H:i:s', $ts + 1800); // 30-minute block

    $minutesBefore = max(0, min(10080, $minutesBefore)); // clamp to <= 7 days
    $channels = array_values(array_intersect($channels, ['email', 'in_app']));
    if (empty($channels)) {
        $channels = ['in_app'];
    }

    // Find the company's active calendar (lowest id), else create a CRM one
    $stmt = $DB->prepare("SELECT id FROM calendars WHERE company_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $stmt->execute([$companyId]);
    $calendarId = (int)$stmt->fetchColumn();
    if (!$calendarId) {
        $ins = $DB->prepare("INSERT INTO calendars (company_id, calendar_type, name, color, is_active) VALUES (?, 'personal', 'CRM', '#06b6d4', 1)");
        $ins->execute([$companyId]);
        $calendarId = (int)$DB->lastInsertId();
    }

    $ins = $DB->prepare("
        INSERT INTO calendar_events
            (company_id, calendar_id, title, description, start_datetime, end_datetime, all_day, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
    ");
    $ins->execute([$companyId, $calendarId, $title, 'CRM follow-up', $start, $end, $userId]);
    $eventId = (int)$DB->lastInsertId();

    $link = $DB->prepare("INSERT INTO calendar_event_links (event_id, linked_type, linked_id) VALUES (?, ?, ?)");
    $link->execute([$eventId, $linkedType, $linkedId]);

    $rem = $DB->prepare("INSERT INTO calendar_event_reminders (event_id, user_id, minutes_before, channel) VALUES (?, ?, ?, ?)");
    foreach ($channels as $channel) {
        $rem->execute([$eventId, $userId, $minutesBefore, $channel]);
    }

    return $eventId;
}
