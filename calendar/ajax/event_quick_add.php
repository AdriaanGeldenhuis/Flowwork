<?php
// /calendar/ajax/event_quick_add.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');
$calendarId = $input['calendar_id'] ?? null;

if (empty($text)) {
    echo json_encode(['ok' => false, 'error' => 'Text is required']);
    exit;
}

// Simple NLP parser
$parsed = parseEventText($text);

// Get default calendar if not specified
if (!$calendarId) {
    $stmt = $DB->prepare("
        SELECT id FROM calendars 
        WHERE company_id = ? AND owner_id = ? AND calendar_type = 'personal'
        ORDER BY id ASC LIMIT 1
    ");
    $stmt->execute([$companyId, $userId]);
    $cal = $stmt->fetch();
    $calendarId = $cal['id'] ?? null;
}

if (!$calendarId) {
    echo json_encode(['ok' => false, 'error' => 'No calendar available']);
    exit;
}

try {
    $stmt = $DB->prepare("
        INSERT INTO calendar_events 
        (company_id, calendar_id, title, description, location, start_datetime, end_datetime, all_day, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $calendarId,
        $parsed['title'],
        $parsed['description'],
        $parsed['location'],
        $parsed['start'],
        $parsed['end'],
        $parsed['all_day'] ? 1 : 0,
        $userId
    ]);

    $eventId = $DB->lastInsertId();

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'event_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['event_id' => $eventId, 'title' => $parsed['title']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'parsed' => $parsed,
        'message' => 'Event created successfully'
    ]);

} catch (Exception $e) {
    error_log("Event quick add error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create event']);
}

// ========== NLP PARSER ==========
function parseEventText($text) {
    $result = [
        'title' => $text,
        'description' => '',
        'location' => '',
        'start' => date('Y-m-d H:i:s', strtotime('now')),
        'end' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        'all_day' => false
    ];

    // Extract location (at X, @ X)
    if (preg_match('/(at|@)\s+([^,]+)/i', $text, $match)) {
        $result['location'] = trim($match[2]);
        $text = str_replace($match[0], '', $text);
    }

    // Extract time (tomorrow, friday, next week, 3pm, 14:00)
    $timePatterns = [
        '/\btomorrow\b/i' => '+1 day',
        '/\bnext week\b/i' => '+1 week',
        '/\bmonday\b/i' => 'next Monday',
        '/\btuesday\b/i' => 'next Tuesday',
        '/\bwednesday\b/i' => 'next Wednesday',
        '/\bthursday\b/i' => 'next Thursday',
        '/\bfriday\b/i' => 'next Friday',
        '/\bsaturday\b/i' => 'next Saturday',
        '/\bsunday\b/i' => 'next Sunday',
    ];

    foreach ($timePatterns as $pattern => $adjustment) {
        if (preg_match($pattern, $text, $match)) {
            $result['start'] = date('Y-m-d H:i:s', strtotime($adjustment));
            $result['end'] = date('Y-m-d H:i:s', strtotime($adjustment . ' +1 hour'));
            $text = preg_replace($pattern, '', $text);
            break;
        }
    }

    // Extract specific time (3pm, 14:00, 9:30am)
    if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i', $text, $match)) {
        $hour = (int)$match[1];
        $minute = isset($match[2]) ? (int)$match[2] : 0;
        $meridiem = strtolower($match[3] ?? '');

        if ($meridiem === 'pm' && $hour < 12) $hour += 12;
        if ($meridiem === 'am' && $hour === 12) $hour = 0;

        $baseDate = strtotime($result['start']);
        $result['start'] = date('Y-m-d', $baseDate) . ' ' . sprintf('%02d:%02d:00', $hour, $minute);
        $result['end'] = date('Y-m-d H:i:s', strtotime($result['start'] . ' +1 hour'));
        
        $text = preg_replace('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i', '', $text);
    }

    // Extract duration (for 2h, for 30min)
    if (preg_match('/for\s+(\d+)\s*(h|hour|hours|min|minute|minutes)/i', $text, $match)) {
        $duration = (int)$match[1];
        $unit = strtolower($match[2]);
        
        if (strpos($unit, 'h') === 0) {
            $result['end'] = date('Y-m-d H:i:s', strtotime($result['start'] . " +{$duration} hours"));
        } else {
            $result['end'] = date('Y-m-d H:i:s', strtotime($result['start'] . " +{$duration} minutes"));
        }
        
        $text = preg_replace('/for\s+(\d+)\s*(h|hour|hours|min|minute|minutes)/i', '', $text);
    }

    // Clean up title
    $result['title'] = trim(preg_replace('/\s+/', ' ', $text));

    return $result;
}