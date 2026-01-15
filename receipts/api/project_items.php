<?php
// Returns a list of board (project) items for a given board.  Used to populate project item dropdowns in receipt review.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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
    // Fetch non-archived items for the given board
    $stmt = $DB->prepare(
        "SELECT id, title
         FROM board_items
         WHERE company_id = ? AND board_id = ? AND archived = 0
         ORDER BY position"
    );
    $stmt->execute([$companyId, $boardId]);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id'    => (int)$row['id'],
            'title' => $row['title']
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
} catch (Exception $e) {
    error_log('project_items API error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}