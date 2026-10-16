<?php
// /crm/includes/calendar_followup.php
// Follow-up reminders for CRM records ride the calendar: the follow-up is an
// event on the user's own calendar, linked to the account or deal, with a
// reminder per channel. Delivery (email / bell + phone) is handled by the
// calendar's reminder job (calendar/lib/reminders.php).

require_once __DIR__ . '/../../calendar/lib/autoload.php';

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
 * @throws Exception on validation problems (message is safe to show).
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
    if (!in_array($linkedType, ['account', 'opportunity'], true)) {
        throw new Exception('Invalid follow-up target type');
    }
    $title = trim($title);
    if ($title === '') {
        throw new Exception('Follow-up title is required');
    }
    try {
        return CalendarLink::followUp($DB, $companyId, $userId, (string)($_SESSION['role'] ?? 'member'),
                                      $linkedType, $linkedId, $title, $dueDatetime, $minutesBefore, $channels, 'CRM follow-up');
    } catch (CalendarUserError $e) {
        throw new Exception($e->getMessage());
    }
}
