<?php
/**
 * API: Add Board Member
 * Adds a user to the project (which gives them board access)
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

// Verify CSRF
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrfToken || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get params
$boardId = (int)($_POST['board_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$role = $_POST['role'] ?? 'member';

if (!$boardId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Validate role - match your table's enum values
$allowedRoles = ['owner', 'manager', 'member', 'viewer'];
if (!in_array($role, $allowedRoles)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid role']);
    exit;
}

try {
    // Get board's project
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

    // Check if user is from same company (fixed: use user_id column)
    $stmt = $DB->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'User not found in company']);
        exit;
    }

    // Check if already a member (fixed: use member_id)
    $stmt = $DB->prepare("
        SELECT member_id FROM project_members 
        WHERE project_id = ? AND user_id = ? AND company_id = ?
    ");
    $stmt->execute([$board['project_id'], $userId, $COMPANY_ID]);
    
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'User is already a member']);
        exit;
    }

    // Add member (fixed: use added_at and can_edit)
    $stmt = $DB->prepare("
        INSERT INTO project_members (project_id, user_id, company_id, role, can_edit, added_at)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$board['project_id'], $userId, $COMPANY_ID, $role]);

    echo json_encode([
        'ok' => true,
        'message' => 'Member added successfully'
    ]);

} catch (Exception $e) {
    error_log("Add board member error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}