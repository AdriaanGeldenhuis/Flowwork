<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

if ($itemId) {
    $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch();
    if (!$item) respond_error('Item not found', 404);
    require_board_role($item['board_id'], 'viewer');
    
    // FIXED: columns are file_name, file_path, file_size, mime_type
    $stmt = $DB->prepare("
        SELECT a.id, a.file_name AS filename, a.file_path AS path, a.file_size AS size, 
               a.mime_type AS mime, a.uploaded_at AS created_at,
               u.first_name, u.last_name
        FROM board_item_attachments a
        LEFT JOIN users u ON a.uploaded_by = u.id
        WHERE a.item_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$itemId]);
    
} elseif ($projectId) {
    require_project_role($projectId, 'viewer');
    
    $stmt = $DB->prepare("
        SELECT a.id, a.file_name AS filename, a.file_path AS path, a.file_size AS size,
               a.mime_type AS mime, a.uploaded_at AS created_at,
               u.first_name, u.last_name, bi.title AS item_title
        FROM board_item_attachments a
        JOIN board_items bi ON a.item_id = bi.id
        JOIN project_boards pb ON bi.board_id = pb.board_id
        LEFT JOIN users u ON a.uploaded_by = u.id
        WHERE pb.project_id = ? AND pb.company_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    
} else {
    respond_error('item_id or project_id required');
}

$files = $stmt->fetchAll(PDO::FETCH_ASSOC);
respond_ok(['files' => $files]);