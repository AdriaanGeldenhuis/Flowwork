<?php
// /qi/ajax/get_project_boards.php
// Returns the live boards of a project (current company) that the user can open

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

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

    // Boards in board order; archived boards and boards the user cannot open are left out
    $boards = array_map(fn($b) => ['board_id' => (int)$b['id'], 'name' => $b['title']],
        ProjectLink::pickerBoards($DB, (int)$companyId, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? 'viewer'), $projectId));

    echo json_encode(['ok' => true, 'boards' => $boards]);

} catch (Exception $e) {
    error_log('Get project boards error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load project boards']);
}