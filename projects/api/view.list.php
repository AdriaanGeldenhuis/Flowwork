<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$boardId = (int)($_GET['board_id'] ?? 0);
if (!$boardId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing board_id']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Verify board access
$stmt = $DB->prepare("
    SELECT pb.project_id 
    FROM project_boards pb
    WHERE pb.board_id = ? AND pb.company_id = ?
");
$stmt->execute([$boardId, $COMPANY_ID]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// List saved views with alias for frontend compat
$stmt = $DB->prepare("
    SELECT 
        id, 
        name, 
        view_type,
        filters AS filters_json, 
        sorts AS sort_json, 
        visible_columns,
        is_shared,
        0 AS is_default
    FROM board_saved_views
    WHERE board_id = ?
    ORDER BY name ASC
");
$stmt->execute([$boardId]);
$views = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'views' => $views]);