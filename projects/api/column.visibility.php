<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

header('Content-Type: application/json');

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$COMPANY_ID = $_SESSION['company_id'];
$columnId = (int)($_POST['column_id'] ?? 0);
$visible = (int)($_POST['visible'] ?? 1);

if (!$columnId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Column ID required']);
    exit;
}

try {
    $stmt = $DB->prepare("
        UPDATE board_columns bc
        JOIN project_boards pb ON bc.board_id = pb.board_id
        SET bc.visible = ?
        WHERE bc.column_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$visible, $columnId, $COMPANY_ID]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Column not found']);
        exit;
    }
    
    echo json_encode(['ok' => true, 'message' => 'Visibility updated']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed: ' . $e->getMessage()]);
}