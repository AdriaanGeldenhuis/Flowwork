<?php
// GET /notifications/api/list.php — the signed-in user's bell: latest 50 + unread count.
// (Moved from calendar/ajax/notifications_get.php; the bell is not calendar-specific.)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/notify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
session_write_close();

try {
    $rows = fw_notify_db($DB, function () use ($DB, $companyId, $userId) {
        $st = $DB->prepare("SELECT id, type, title, message, url, link, is_read, created_at FROM notifications
                             WHERE company_id = ? AND user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50");
        $st->execute([$companyId, $userId]);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $DB->prepare("SELECT COUNT(*) FROM notifications WHERE company_id = ? AND user_id = ? AND is_read = 0");
        $st->execute([$companyId, $userId]);
        return [$list, (int)$st->fetchColumn()];
    });
    foreach ($rows[0] as &$n) {
        $n['id'] = (int)$n['id'];
        $n['is_read'] = (int)$n['is_read'];
    }
    unset($n);
    echo json_encode(['ok' => true, 'notifications' => $rows[0], 'unread_count' => $rows[1]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[notifications/list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load notifications']);
}
