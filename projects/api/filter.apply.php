<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$filters = json_decode($_GET['filters'] ?? '{}', true);

if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'viewer');

try {
    $sql = "
        SELECT 
            bi.*, bg.name as group_name,
            u.first_name, u.last_name
        FROM board_items bi
        JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
    ";
    
    $params = [$boardId, $COMPANY_ID];
    
    // Apply filters
    if (!empty($filters['status'])) {
        $sql .= " AND bi.status_label = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['assignee'])) {
        $sql .= " AND bi.assigned_to = ?";
        $params[] = $filters['assignee'];
    }
    
    if (!empty($filters['priority'])) {
        $sql .= " AND bi.priority = ?";
        $params[] = $filters['priority'];
    }
    
    if (!empty($filters['group'])) {
        $sql .= " AND bi.group_id = ?";
        $params[] = $filters['group'];
    }
    
    if (!empty($filters['due_from'])) {
        $sql .= " AND bi.due_date >= ?";
        $params[] = $filters['due_from'];
    }
    
    if (!empty($filters['due_to'])) {
        $sql .= " AND bi.due_date <= ?";
        $params[] = $filters['due_to'];
    }
    
    $sql .= " ORDER BY bg.position, bi.position";
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok(['items' => $items, 'count' => count($items)]);
    
} catch (Exception $e) {
    error_log("Filter apply error: " . $e->getMessage());
    respond_error('Filter failed', 500);
}