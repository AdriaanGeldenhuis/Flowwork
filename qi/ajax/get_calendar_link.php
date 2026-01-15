<?php
declare(strict_types=1);
// Return the most recent calendar event linked to a quote or invoice.

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        getenv('FW_DSN') ?: '',
        getenv('FW_DB_USER') ?: '',
        getenv('FW_DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!in_array($type, ['quote','invoice'], true) || $id < 1) {
        echo json_encode(['event' => null]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT l.event_id, e.title, e.start_datetime, e.end_datetime, e.id\n"
      . "FROM calendar_event_links l\n"
      . "JOIN calendar_events e ON e.id = l.event_id\n"
      . "WHERE l.linked_type = ? AND l.linked_id = ?\n"
      . "ORDER BY e.start_datetime DESC LIMIT 1"
    );
    $stmt->execute([$type, $id]);
    $row = $stmt->fetch();
    echo json_encode(['event' => $row ?: null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}