<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

if ($USER_ROLE !== 'admin' && $USER_ROLE !== 'manager') {
    respond_error('Access denied', 403);
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$boardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : null;
$limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

try {
    $sql = "
        SELECT 
            bal.id, bal.action, bal.details, bal.ip_address, bal.created_at,
            u.first_name, u.last_name, u.email
        FROM board_audit_log bal
        LEFT JOIN users u ON bal.user_id = u.id
        WHERE bal.company_id = ?
    ";
    $params = [$COMPANY_ID];
    
    if ($boardId) {
        $sql .= " AND bal.board_id = ?";
        $params[] = $boardId;
    } elseif ($projectId) {
        $sql .= " AND bal.board_id IN (SELECT board_id FROM project_boards WHERE project_id = ?)";
        $params[] = $projectId;
    }
    
    $sql .= " ORDER BY bal.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = str_replace('SELECT bal.id, bal.action, bal.details, bal.ip_address, bal.created_at, u.first_name, u.last_name, u.email', 'SELECT COUNT(*)', explode('ORDER BY', $sql)[0]);
    $stmt = $DB->prepare($countSql);
    $stmt->execute(array_slice($params, 0, -2)); // Remove LIMIT and OFFSET
    $total = (int)$stmt->fetchColumn();
    
    respond_ok([
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
    
} catch (Exception $e) {
    error_log("Audit log error: " . $e->getMessage());
    respond_error('Failed to load audit log', 500);
}