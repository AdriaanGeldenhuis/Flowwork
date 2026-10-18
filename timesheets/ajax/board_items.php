<?php
// /timesheets/ajax/board_items.php – Fetch board items for a given project
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$role      = (string)($_SESSION['role'] ?? 'viewer');

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);
$projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;

if (!$companyId || !$projectId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    // Verify project belongs to company, is not archived and is one the picker offers this user
    // (visibleProjectIds also covers people who are only on one of its boards)
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ? AND status NOT IN ('archived','cancelled') AND (archived = 0 OR archived IS NULL)");
    $stmt->execute([$projectId, $companyId]);
    $repo = ProjectLink::repo($DB, $companyId, $userId, $role);
    $visible = $repo->visibleProjectIds();
    if (!$stmt->fetch() || ($visible !== null && !isset($visible[$projectId]))) {
        echo json_encode(['ok' => false, 'error' => 'Project not found']);
        exit;
    }

    // Fetch board items (tasks) for this project: live items on the live boards the user can open
    $items = [];
    foreach ($repo->boardsOf($projectId) as $board) {
        foreach (ProjectLink::pickerItems($DB, $companyId, $userId, $role, $board['id']) as $item) {
            $items[] = ['id' => $item['id'], 'title' => $item['title'], 'board_id' => $board['id'], 'board' => $board['title']];
        }
    }

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('Timesheet board items error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load tasks']);
}
