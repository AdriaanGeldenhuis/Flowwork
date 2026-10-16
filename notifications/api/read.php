<?php
// POST /notifications/api/read.php  {id}  mark one notification read
// POST /notifications/api/read.php  {all: true}  mark all read
// (Moved from calendar/ajax/notifications_mark_read.php and _mark_all_read.php.)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php'; // also validates the CSRF token on POST

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
session_write_close();

$in = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($in)) $in = $_POST;

try {
    if (!empty($in['all'])) {
        $DB->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE company_id = ? AND user_id = ? AND is_read = 0")
           ->execute([$companyId, $userId]);
    } else {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Notification ID required']);
            exit;
        }
        $DB->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND company_id = ? AND user_id = ?")
           ->execute([$id, $companyId, $userId]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[notifications/read] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to mark as read']);
}
