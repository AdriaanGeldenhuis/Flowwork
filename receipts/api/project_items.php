<?php
// Returns a list of board (project) items for a given board.  Used to populate project item dropdowns in receipt review.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

header('Content-Type: application/json');

// Ensure the user is authenticated
if (!isset($_SESSION['company_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

// Require a board_id parameter
$boardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : 0;
if ($boardId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing board_id']);
    exit;
}

try {
    // Live items of a board this user can open (company-scoped by the Projects API),
    // in board order: [{id, title, group}]
    $items = ProjectLink::pickerItems($DB, $companyId, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['role'] ?? 'viewer'), $boardId);
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
} catch (Throwable $e) {
    error_log('project_items API error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}