<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$boardId = (int)($_POST['board_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$filtersJson = $_POST['filters'] ?? '{}';
$sortJson = $_POST['sort'] ?? '[]';

if (!$boardId || !$name) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Verify board access
$stmt = $DB->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
$stmt->execute([$boardId, $COMPANY_ID]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// Insert view (write to filters/sorts columns)
$stmt = $DB->prepare("
    INSERT INTO board_saved_views 
    (board_id, name, filters, sorts, is_shared, created_by, created_at)
    VALUES (?, ?, ?, ?, 0, ?, NOW())
");
$stmt->execute([$boardId, $name, $filtersJson, $sortJson, $USER_ID]);

$viewId = $DB->lastInsertId();

echo json_encode(['ok' => true, 'view_id' => $viewId]);