<?php
// /calendar/ajax/notifications_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Get notifications
    $stmt = $DB->prepare("
        SELECT * FROM notifications
        WHERE company_id = ? AND user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$companyId, $userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread count
    $stmt = $DB->prepare("
        SELECT COUNT(*) as cnt FROM notifications
        WHERE company_id = ? AND user_id = ? AND is_read = 0
    ");
    $stmt->execute([$companyId, $userId]);
    $unreadCount = $stmt->fetch()['cnt'];

    echo json_encode([
        'ok' => true,
        'notifications' => $notifications,
        'unread_count' => (int)$unreadCount
    ]);

} catch (Exception $e) {
    error_log("Notifications get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load notifications']);
}