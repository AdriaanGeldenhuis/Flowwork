<?php
// /mail/ajax/message_mark_read.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$threadId = isset($input['thread_id']) ? (int)$input['thread_id'] : 0;

if (!$threadId) {
    echo json_encode(['ok' => false, 'error' => 'Missing thread_id']);
    exit;
}

try {
    // Mark all messages in thread as read (tenant-checked)
    $stmt = $DB->prepare("
        UPDATE emails e
        JOIN email_accounts a ON e.account_id = a.account_id
        SET e.is_read = 1
        WHERE e.thread_id = :thread_id
          AND a.company_id = :company_id
          AND a.user_id = :user_id
    ");
    $stmt->execute([
        'thread_id' => $threadId,
        'company_id' => $companyId,
        'user_id' => $userId
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Mark read error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to mark as read']);
}