<?php
// /calendar/cron/send_reminders.php
// Run every 5 minutes: */5 * * * * php /path/to/calendar/cron/send_reminders.php

require_once __DIR__ . '/../../init.php';

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$now = date('Y-m-d H:i:s');
$lookAheadMinutes = 60; // Check reminders due in next hour

try {
    // Find reminders that need to be sent
    $stmt = $DB->prepare("
        SELECT 
            r.id as reminder_id,
            r.event_id,
            r.user_id,
            r.minutes_before,
            r.channel,
            e.title as event_title,
            e.start_datetime,
            e.location,
            e.description,
            e.company_id,
            u.email,
            u.first_name,
            c.name as company_name
        FROM calendar_event_reminders r
        JOIN calendar_events e ON r.event_id = e.id
        JOIN users u ON r.user_id = u.id
        JOIN companies c ON e.company_id = c.id
        WHERE r.sent_at IS NULL
        AND r.snoozed_until IS NULL
        AND TIMESTAMPDIFF(MINUTE, ?, e.start_datetime) <= r.minutes_before
        AND TIMESTAMPDIFF(MINUTE, ?, e.start_datetime) >= 0
        LIMIT 100
    ");
    $stmt->execute([$now, $now]);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sentCount = 0;

    foreach ($reminders as $rem) {
        $sent = false;

        if ($rem['channel'] === 'email') {
            $sent = sendReminderEmail($rem);
        } elseif ($rem['channel'] === 'in_app') {
            $sent = createInAppNotification($rem);
        }

        if ($sent) {
            // Mark as sent
            $stmt = $DB->prepare("
                UPDATE calendar_event_reminders 
                SET sent_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$rem['reminder_id']]);
            $sentCount++;
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Sent $sentCount reminders\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("Calendar reminders error: " . $e->getMessage());
}

// ========== HELPER FUNCTIONS ==========

/** Site host for links and the From address; there is no HTTP_HOST under cron. */
function reminderHost(): string {
    $host = parse_url(defined('APP_BASE_URL') ? APP_BASE_URL : '', PHP_URL_HOST);
    return $host ?: (string)($_SERVER['HTTP_HOST'] ?? 'www.flowwork.app');
}

function sendReminderEmail($reminder) {
    $to = $reminder['email'];
    $host = reminderHost();
    $mailHost = preg_replace('/^www\./', '', $host);
    $subject = "Reminder: " . $reminder['event_title'];
    
    $startDate = new DateTime($reminder['start_datetime']);
    $timeStr = $startDate->format('l, F j, Y \a\t g:i A');
    
    $body = "Hi " . $reminder['first_name'] . ",\n\n";
    $body .= "This is a reminder for your upcoming event:\n\n";
    $body .= "EVENT: " . $reminder['event_title'] . "\n";
    $body .= "WHEN: " . $timeStr . "\n";
    
    if ($reminder['location']) {
        $body .= "WHERE: " . $reminder['location'] . "\n";
    }
    
    if ($reminder['description']) {
        $body .= "\nDESCRIPTION:\n" . $reminder['description'] . "\n";
    }
    
    $body .= "\nView event: https://" . $host . "/calendar/event_view.php?id=" . $reminder['event_id'] . "\n\n";
    $body .= "---\n";
    $body .= $reminder['company_name'] . " Calendar\n";
    
    $headers = "From: noreply@" . $mailHost . "\r\n";
    $headers .= "Reply-To: noreply@" . $mailHost . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($to, $subject, $body, $headers);
}

function createInAppNotification($reminder) {
    global $DB;
    
    require_once __DIR__ . '/../../includes/notify.php';

    $start = strtotime($reminder['start_datetime']);
    $message = "Event starts at " . date('g:i A', $start);
    if ($reminder['location']) {
        $message .= " at " . $reminder['location'];
    }

    // Bell + phone. A reminder that reaches a phone only after the event
    // started is useless, so it expires shortly after the start time.
    $id = fw_notify($DB, (int)$reminder['company_id'], (int)$reminder['user_id'], 'calendar_reminder',
                    (string)$reminder['event_title'], $message,
                    '/calendar/event_view.php?id=' . (int)$reminder['event_id'], [
                        'ch' => 'calendar', 'grp' => 'calendar',
                        'tag' => 'cal:r' . (int)$reminder['reminder_id'],
                        'ttl' => max(300, $start - time() + 900),
                    ]);
    if (!$id) {
        error_log("In-app notification error: reminder " . (int)$reminder['reminder_id'] . " could not be stored");
    }
    return $id !== null;
}