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
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $filters = json_decode($_POST['filters'] ?? '[]', true);
    
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    if (!is_array($filters)) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Invalid filters format']));
    }
    
    // Verify board access
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards 
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Build query
    $sql = "
        SELECT DISTINCT bi.*,
            bg.name AS group_name,
            bg.color AS group_color,
            u.first_name,
            u.last_name
        FROM board_items bi
        LEFT JOIN board_groups bg ON bi.group_id = bg.id
        LEFT JOIN users u ON bi.assigned_to = u.id
        WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
    ";
    
    $params = [$boardId, $COMPANY_ID];
    
    // Apply filters (simplified for now)
    foreach ($filters as $filter) {
        $field = $filter['field'] ?? '';
        $operator = $filter['operator'] ?? 'equals';
        $value = $filter['value'] ?? '';
        
        if (empty($field)) continue;
        
        if ($field === 'title' && $operator === 'contains') {
            $sql .= " AND bi.title LIKE ?";
            $params[] = '%' . $value . '%';
        } elseif ($field === 'status_label' && $operator === 'equals') {
            $sql .= " AND bi.status_label = ?";
            $params[] = $value;
        }
    }
    
    $sql .= " ORDER BY bg.position, bi.position";
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Filters applied successfully',
        'data' => [
            'items' => $items,
            'count' => count($items),
            'filters_applied' => count($filters)
        ]
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Filter Apply Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;