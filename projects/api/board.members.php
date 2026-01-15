<?php
/**
 * API: Get Board Members
 * Returns list of users who have access to this board
 */

require_once __DIR__ . '/../../init.php';

header('Content-Type: application/json');

// Check auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$USER_ID = $_SESSION['user_id'];
$COMPANY_ID = $_SESSION['company_id'];

// Get board_id
$boardId = (int)($_GET['board_id'] ?? 0);

if (!$boardId) {
    echo json_encode(['ok' => false, 'error' => 'Missing board_id']);
    exit;
}

try {
    // Verify board access
    $stmt = $DB->prepare("
        SELECT pb.project_id
        FROM project_boards pb
        WHERE pb.board_id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$board) {
        echo json_encode(['ok' => false, 'error' => 'Board not found']);
        exit;
    }

    // Get members - FIXED: JOIN on u.id instead of u.user_id
    $stmt = $DB->prepare("
        SELECT 
            pm.member_id,
            pm.user_id,
            pm.role,
            pm.can_edit,
            pm.added_at,
            u.first_name,
            u.last_name,
            u.email
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND pm.company_id = ?
        ORDER BY u.first_name, u.last_name
    ");
    $stmt->execute([$board['project_id'], $COMPANY_ID]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'members' => $members
    ]);

} catch (Exception $e) {
    error_log("Load board members error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load members: ' . $e->getMessage()
    ]);
}