<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$lineId = (int)($_POST['line_id'] ?? 0);
$projectBoardId = (int)($_POST['project_board_id'] ?? 0);
$projectItemId = (int)($_POST['project_item_id'] ?? 0);

if (!$lineId) {
    echo json_encode(['ok' => false, 'error' => 'Missing line_id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        UPDATE invoice_lines SET
            project_board_id = ?,
            project_item_id = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $projectBoardId ?: null,
        $projectItemId ?: null,
        $lineId
    ]);

    echo json_encode(['ok' => true, 'message' => 'Allocation saved']);

} catch (Exception $e) {
    error_log('Allocation error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}