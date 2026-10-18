<?php
// /projects/guest-changes.php?token=… — the board's change cursor, so an open
// guest page can offer a refresh. Needs the guest's session for the invitation.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $g = Guests::current($DB, (string)($_GET['token'] ?? ''));
    if (!$g) { http_response_code(401); echo json_encode(['ok' => false, 'signed_out' => true]); exit; }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    echo json_encode(['ok' => true, 'cursor' => Activity::cursor($DB, (int)$g['board_id'])]);
} catch (Throwable $e) {
    error_log('[projects/guest-changes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
