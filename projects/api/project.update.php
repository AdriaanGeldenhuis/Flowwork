<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$projectId = (int)($_POST['project_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$startDate = $_POST['start_date'] ?? null;
$endDate = $_POST['end_date'] ?? null;
$budget = $_POST['budget'] ?? null;
$managerUserId = $_POST['manager_user_id'] ?? null;

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

if (!$projectId || !$name) {
    echo json_encode(['ok' => false, 'error' => 'Project ID and name required']);
    exit;
}

// Verify project belongs to company
$stmt = $DB->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
$stmt->execute([$projectId, $COMPANY_ID]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Project not found']);
    exit;
}

// Update project
$stmt = $DB->prepare("
    UPDATE projects 
    SET name = ?, start_date = ?, end_date = ?, budget = ?, manager_user_id = ?, updated_at = NOW()
    WHERE id = ? AND company_id = ?
");

try {
    $stmt->execute([
        $name,
        $startDate ?: null,
        $endDate ?: null,
        $budget ?: null,
        $managerUserId ?: null,
        $projectId,
        $COMPANY_ID
    ]);
    
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}