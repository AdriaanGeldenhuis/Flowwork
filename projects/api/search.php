<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$query = trim($_GET['q'] ?? '');
$boardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : null;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

if (strlen($query) < 2) respond_error('Query too short (min 2 characters)');

try {
    $results = [];
    
    if ($boardId) {
        require_board_role($boardId, 'viewer');
        
        $stmt = $DB->prepare("
            SELECT 
                bi.id, bi.title, bi.status_label,
                bg.name as group_name,
                u.first_name, u.last_name
            FROM board_items bi
            JOIN board_groups bg ON bi.group_id = bg.id
            LEFT JOIN users u ON bi.assigned_to = u.id
            WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
            AND (bi.title LIKE ? OR bi.description LIKE ?)
            ORDER BY bi.created_at DESC
            LIMIT 50
        ");
        $searchTerm = '%' . $query . '%';
        $stmt->execute([$boardId, $COMPANY_ID, $searchTerm, $searchTerm]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($projectId) {
        require_project_role($projectId, 'viewer');
        
        $stmt = $DB->prepare("
            SELECT 
                bi.id, bi.title, bi.status_label,
                bg.name as group_name,
                pb.title as board_title,
                u.first_name, u.last_name
            FROM board_items bi
            JOIN board_groups bg ON bi.group_id = bg.id
            JOIN project_boards pb ON bi.board_id = pb.board_id
            LEFT JOIN users u ON bi.assigned_to = u.id
            WHERE pb.project_id = ? AND bi.company_id = ? AND bi.archived = 0
            AND (bi.title LIKE ? OR bi.description LIKE ?)
            ORDER BY bi.created_at DESC
            LIMIT 50
        ");
        $searchTerm = '%' . $query . '%';
        $stmt->execute([$projectId, $COMPANY_ID, $searchTerm, $searchTerm]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // Global search (admin/manager only)
        if ($USER_ROLE !== 'admin' && $USER_ROLE !== 'manager') {
            respond_error('Access denied', 403);
        }
        
        $stmt = $DB->prepare("
            SELECT 
                bi.id, bi.title, bi.status_label,
                bg.name as group_name,
                pb.title as board_title,
                p.name as project_name,
                u.first_name, u.last_name
            FROM board_items bi
            JOIN board_groups bg ON bi.group_id = bg.id
            JOIN project_boards pb ON bi.board_id = pb.board_id
            JOIN projects p ON pb.project_id = p.project_id
            LEFT JOIN users u ON bi.assigned_to = u.id
            WHERE bi.company_id = ? AND bi.archived = 0
            AND (bi.title LIKE ? OR bi.description LIKE ?)
            ORDER BY bi.created_at DESC
            LIMIT 100
        ");
        $searchTerm = '%' . $query . '%';
        $stmt->execute([$COMPANY_ID, $searchTerm, $searchTerm]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    respond_ok(['results' => $results, 'count' => count($results)]);
    
} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    respond_error('Search failed', 500);
}