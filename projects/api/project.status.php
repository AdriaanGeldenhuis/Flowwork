<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$projectId = (int)($_POST['project_id'] ?? 0);
$status = $_POST['status'] ?? '';

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

$allowedStatuses = ['active', 'completed', 'on_hold', 'cancelled'];
if (!$projectId || !in_array($status, $allowedStatuses)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// Verify project belongs to company
$stmt = $DB->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
$stmt->execute([$projectId, $COMPANY_ID]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Project not found']);
    exit;
}

// Update status
$stmt = $DB->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");

try {
    $stmt->execute([$status, $projectId, $COMPANY_ID]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}