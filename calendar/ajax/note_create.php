<?php
// /calendar/ajax/note_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$calendarId = $input['calendar_id'] ?? null;
$noteDate = $input['note_date'] ?? date('Y-m-d');
$title = trim($input['title'] ?? '');
$body = trim($input['body'] ?? '');
$color = $input['color'] ?? '#fbbf24';

if (!$calendarId) {
    echo json_encode(['ok' => false, 'error' => 'Calendar ID required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        INSERT INTO calendar_notes 
        (company_id, calendar_id, user_id, note_date, title, body, color, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$companyId, $calendarId, $userId, $noteDate, $title, $body, $color]);

    $noteId = $DB->lastInsertId();

    echo json_encode([
        'ok' => true,
        'note_id' => $noteId,
        'message' => 'Note created successfully'
    ]);

} catch (Exception $e) {
    error_log("Note create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create note']);
}