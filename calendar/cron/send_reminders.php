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

function sendReminderEmail($reminder) {
    $to = $reminder['email'];
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
    
    $body .= "\nView event: https://" . $_SERVER['HTTP_HOST'] . "/calendar/event_view.php?id=" . $reminder['event_id'] . "\n\n";
    $body .= "---\n";
    $body .= $reminder['company_name'] . " Calendar\n";
    
    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($to, $subject, $body, $headers);
}

function createInAppNotification($reminder) {
    global $DB;
    
    try {
        // Create notification (assuming you have a notifications system)
        // If not, you can create a simple notifications table
        $stmt = $DB->prepare("
            INSERT INTO notifications 
            (company_id, user_id, type, title, message, link, created_at)
            VALUES (?, ?, 'calendar_reminder', ?, ?, ?, NOW())
        ");
        
        $message = "Event starts at " . date('g:i A', strtotime($reminder['start_datetime']));
        if ($reminder['location']) {
            $message .= " at " . $reminder['location'];
        }
        
        $stmt->execute([
            $reminder['company_id'],
            $reminder['user_id'],
            $reminder['event_title'],
            $message,
            '/calendar/event_view.php?id=' . $reminder['event_id']
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("In-app notification error: " . $e->getMessage());
        return false;
    }
}