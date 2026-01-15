<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_respond.php';

$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = min(100, max(10, (int)($_GET['per'] ?? 50)));
$offset = ($page - 1) * $per;

$where = ["p.company_id = ?"];
$params = [$COMPANY_ID];

// Filter by user access (non-admins)
if ($USER_ROLE !== 'admin') {
    $where[] = "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.project_id AND pm.user_id = ?)";
    $params[] = $USER_ID;
}

if ($status) {
    $where[] = "p.status = ?";
    $params[] = $status;
}

if ($search) {
    $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

try {
    // Get total count
    $stmt = $DB->prepare("SELECT COUNT(*) FROM projects p WHERE $whereClause");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Get projects
    $params[] = $per;
    $params[] = $offset;
    
    $stmt = $DB->prepare("
        SELECT p.*, 
               u.first_name AS manager_first, u.last_name AS manager_last,
               (SELECT COUNT(*) FROM project_boards pb WHERE pb.project_id = p.project_id) AS board_count,
               (SELECT COUNT(*) FROM board_items bi 
                JOIN project_boards pb2 ON bi.board_id = pb2.board_id 
                WHERE pb2.project_id = p.project_id) AS item_count
        FROM projects p
        LEFT JOIN users u ON p.project_manager_id = u.id
        WHERE $whereClause
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok([
        'projects' => $projects,
        'pagination' => [
            'page' => $page,
            'per' => $per,
            'total' => $total,
            'pages' => ceil($total / $per)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Project list error: " . $e->getMessage());
    respond_error('Failed to load projects', 500);
}