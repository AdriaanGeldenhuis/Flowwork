<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    
    $boardId = (int)($_GET['board_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 50);
    
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    if ($limit > 200) $limit = 200;
    
    // Check access
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Check if table exists
    $stmt = $DB->query("SHOW TABLES LIKE 'board_activity'");
    if ($stmt->rowCount() === 0) {
        // Create table
        $DB->exec("
            CREATE TABLE `board_activity` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `board_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `action` varchar(50) NOT NULL,
              `item_id` int(11) DEFAULT NULL,
              `details` text,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `board_id` (`board_id`),
              KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    // Get activities
    $stmt = $DB->prepare("
        SELECT 
            a.*,
            u.first_name,
            u.last_name,
            u.email,
            bi.title AS item_title,
            CASE
                WHEN TIMESTAMPDIFF(SECOND, a.created_at, NOW()) < 60 THEN 'Just now'
                WHEN TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, a.created_at, NOW()), ' min ago')
                WHEN TIMESTAMPDIFF(HOUR, a.created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, a.created_at, NOW()), ' hours ago')
                WHEN TIMESTAMPDIFF(DAY, a.created_at, NOW()) < 7 THEN CONCAT(TIMESTAMPDIFF(DAY, a.created_at, NOW()), ' days ago')
                ELSE DATE_FORMAT(a.created_at, '%b %d, %Y')
            END AS time_ago
        FROM board_activity a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN board_items bi ON a.item_id = bi.id
        WHERE a.board_id = ?
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$boardId, $limit]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse details JSON
    foreach ($activities as &$activity) {
        $activity['details'] = $activity['details'] ? json_decode($activity['details'], true) : [];
    }
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Activities loaded',
        'data' => [
            'activities' => $activities
        ]
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Activity List Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;