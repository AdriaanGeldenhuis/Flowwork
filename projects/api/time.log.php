<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$itemId = (int)($_POST['item_id'] ?? 0);
$date = trim($_POST['date'] ?? '');
$hours = (float)($_POST['hours'] ?? 0);
$billable = (int)($_POST['billable'] ?? 1);
$note = trim($_POST['note'] ?? '');

if (!$date || $hours <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Get project_id from item
$stmt = $DB->prepare("
    SELECT bi.board_id, pb.project_id 
    FROM board_items bi
    JOIN project_boards pb ON bi.board_id = pb.board_id
    WHERE bi.id = ? AND bi.company_id = ?
");
$stmt->execute([$itemId, $COMPANY_ID]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// Insert time log
$stmt = $DB->prepare("
    INSERT INTO timesheets 
    (company_id, project_id, item_id, user_id, date, hours, billable, note, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
    $COMPANY_ID, $item['project_id'], $itemId, $USER_ID, $date, $hours, $billable, $note
]);

$logId = $DB->lastInsertId();

echo json_encode(['ok' => true, 'log_id' => $logId]);