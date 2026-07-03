<?php
/**
 * API: Remove Board Member
 * Removes a user from the project (removes board access)
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

if (!$boardId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
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

    // Authorization: only a project manager/owner (or company admin) may remove
    // members. Without this check any authenticated company user could remove
    // anyone from any project.
    $USER_ROLE = $_SESSION['role'] ?? 'viewer';
    require_once __DIR__ . '/_guard.php';
    require_project_role((int)$board['project_id'], 'manager');

    // Don't allow removing yourself if you're the only owner
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM project_members
        WHERE project_id = ? AND role = 'owner' AND company_id = ?
    ");
    $stmt->execute([$board['project_id'], $COMPANY_ID]);
    $ownerCount = (int)$stmt->fetchColumn();

    if ($ownerCount === 1 && $userId === $USER_ID) {
        echo json_encode(['ok' => false, 'error' => 'Cannot remove the only owner']);
        exit;
    }

    // Remove member
    $stmt = $DB->prepare("
        DELETE FROM project_members
        WHERE project_id = ? AND user_id = ? AND company_id = ?
    ");
    $stmt->execute([$board['project_id'], $userId, $COMPANY_ID]);

    echo json_encode([
        'ok' => true,
        'message' => 'Member removed successfully'
    ]);

} catch (Exception $e) {
    error_log("Remove board member error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}