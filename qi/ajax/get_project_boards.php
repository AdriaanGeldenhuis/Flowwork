<?php
// /qi/ajax/get_project_boards.php
// Returns a list of boards for a given project belonging to the current company

require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? null;

// Get project_id from query string (GET)
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

try {
    if (!$projectId) {
        // No project provided returns empty list
        echo json_encode(['ok' => true, 'boards' => []]);
        exit;
    }

    // Fetch boards for this project and company
    $stmt = $pdo->prepare("SELECT board_id, name FROM project_boards WHERE project_id = ? AND company_id = ? ORDER BY name");
    $stmt->execute([$projectId, $companyId]);
    $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'boards' => $boards]);

} catch (Exception $e) {
    error_log('Get project boards error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load project boards']);
}