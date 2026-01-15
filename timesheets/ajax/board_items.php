<?php
// /timesheets/ajax/board_items.php – Fetch board items for a given project
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);
$projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;

if (!$companyId || !$projectId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify project belongs to company
$stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ? AND status NOT IN ('archived','cancelled')");
$stmt->execute([$projectId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Project not found']);
    exit;
}

// Fetch board items (tasks) for this project
$stmt = $DB->prepare(
    "SELECT bi.id, bi.name
     FROM board_items bi
     JOIN project_boards pb ON bi.board_id = pb.id
     WHERE pb.project_id = ? AND bi.status != 'archived'
     ORDER BY bi.name"
);
$stmt->execute([$projectId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'items' => $items]);