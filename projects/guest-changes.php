<?php
/**
 * Lightweight change probe for guest views.
 * Validated by the same board_guests token as guest-view.php (no session
 * auth); returns the board's latest audit id so the guest page can show a
 * "board updated" banner when data goes stale.
 */
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT id, board_id, expires_at
        FROM board_guests
        WHERE token = ? AND status = 'active'
    ");
    $stmt->execute([$token]);
    $guest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$guest || ($guest['expires_at'] && strtotime($guest['expires_at']) < time())) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired token']);
        exit;
    }

    $stmt = $DB->prepare("SELECT COALESCE(MAX(id), 0) FROM board_audit_log WHERE board_id = ?");
    $stmt->execute([$guest['board_id']]);
    $lastId = (int)$stmt->fetchColumn();

    echo json_encode(['ok' => true, 'last_id' => $lastId]);

} catch (Exception $e) {
    error_log('Guest changes error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
