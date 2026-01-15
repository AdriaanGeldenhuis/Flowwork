<?php
// /calendar/ajax/notifications_mark_all_read.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $stmt = $DB->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW()
        WHERE company_id = ? AND user_id = ? AND is_read = 0
    ");
    $stmt->execute([$companyId, $userId]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Mark all read error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to mark all as read']);
}