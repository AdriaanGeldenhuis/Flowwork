<?php
// /calendar/ajax/notifications_mark_read.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$notificationId = $input['id'] ?? null;

if (!$notificationId) {
    echo json_encode(['ok' => false, 'error' => 'Notification ID required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW()
        WHERE id = ? AND company_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $companyId, $userId]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Mark read error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to mark as read']);
}