<?php
/**
 * Near-real-time change feed for a board.
 * GET board_id + since (a board_audit_log id cursor); returns every audit
 * event after the cursor so realtime.js can patch the DOM without a reload.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);

if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'viewer');

try {
    $stmt = $DB->prepare("
        SELECT a.id, a.action, a.item_id, a.user_id, a.details, a.created_at,
               u.first_name, u.last_name
        FROM board_audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.board_id = ? AND a.company_id = ? AND a.id > ?
        ORDER BY a.id ASC
        LIMIT 100
    ");
    $stmt->execute([$boardId, $COMPANY_ID, $since]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lastId = $since;
    foreach ($events as &$event) {
        $event['details'] = $event['details'] ? json_decode($event['details'], true) : [];
        if ((int)$event['id'] > $lastId) $lastId = (int)$event['id'];
    }

    respond_ok([
        'last_id' => $lastId,
        'events' => $events,
    ]);

} catch (Exception $e) {
    error_log('Board changes error: ' . $e->getMessage());
    respond_error('Failed to load changes', 500);
}
