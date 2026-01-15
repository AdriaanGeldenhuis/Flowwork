<?php
// /calendar/ajax/ics_import.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

if (!isset($_FILES['ics_file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$calendarId = $_POST['calendar_id'] ?? null;

if (!$calendarId) {
    echo json_encode(['ok' => false, 'error' => 'Calendar ID required']);
    exit;
}

// Verify calendar access
$stmt = $DB->prepare("
    SELECT id FROM calendars 
    WHERE id = ? AND company_id = ? AND owner_id = ?
");
$stmt->execute([$calendarId, $companyId, $userId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Calendar not found or access denied']);
    exit;
}

try {
    $file = $_FILES['ics_file'];
    $content = file_get_contents($file['tmp_name']);
    
    // Simple ICS parser
    $lines = explode("\n", $content);
    $events = [];
    $currentEvent = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if ($line === 'BEGIN:VEVENT') {
            $currentEvent = [];
        } elseif ($line === 'END:VEVENT' && $currentEvent) {
            $events[] = $currentEvent;
            $currentEvent = null;
        } elseif ($currentEvent !== null && strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $currentEvent[$key] = $value;
        }
    }
    
    $imported = 0;
    
    foreach ($events as $evt) {
        if (!isset($evt['SUMMARY']) || !isset($evt['DTSTART'])) continue;
        
        $title = $evt['SUMMARY'];
        $description = $evt['DESCRIPTION'] ?? '';
        $location = $evt['LOCATION'] ?? '';
        
        // Parse dates
        $startStr = $evt['DTSTART'];
        $endStr = $evt['DTEND'] ?? $startStr;
        
        // Convert YYYYMMDDTHHMMSS to MySQL datetime
        $start = date('Y-m-d H:i:s', strtotime($startStr));
        $end = date('Y-m-d H:i:s', strtotime($endStr));
        
        $stmt = $DB->prepare("
            INSERT INTO calendar_events
            (company_id, calendar_id, title, description, location, start_datetime, end_datetime, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $calendarId,
            $title,
            $description,
            $location,
            $start,
            $end,
            $userId
        ]);
        
        $imported++;
    }
    
    echo json_encode(['ok' => true, 'imported' => $imported]);
    
} catch (Exception $e) {
    error_log("ICS import error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Import failed']);
}