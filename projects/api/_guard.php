<?php
// /projects/api/_guard.php
//
// Minimal, safe guard layer aligned with your DB.

function _role_level(string $role): int {
    static $map = [
        // project roles
        'owner' => 4,
        'manager' => 3,
        'member' => 2,
        // board roles
        'editor' => 2,
        // synonyms used in older code
        'contributor' => 2,
        'viewer' => 1,
    ];
    return $map[strtolower($role)] ?? 0;
}

function require_project_role(int $projectId, string $minRole = 'viewer') {
    global $DB, $USER_ID, $USER_ROLE, $COMPANY_ID;

    if ($USER_ROLE === 'admin') {
        return true;
    }

    $required = _role_level($minRole);

    // explicit membership
    $stmt = $DB->prepare("
        SELECT role 
        FROM project_members 
        WHERE project_id = ? AND user_id = ? AND company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$projectId, $USER_ID, $COMPANY_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if (_role_level($row['role']) >= $required) {
            return true;
        }
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    // fallback: project owner of creator mag
    $stmt = $DB->prepare("
        SELECT owner_id, created_by, project_manager_id
        FROM projects
        WHERE project_id = ? AND company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($proj && in_array($USER_ID, array_filter([$proj['owner_id'], $proj['created_by'], $proj['project_manager_id']]))) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

function require_board_role(int $boardId, string $minRole = 'viewer') {
    global $DB, $USER_ID, $USER_ROLE;

    if ($USER_ROLE === 'admin') {
        return true;
    }

    $required = _role_level($minRole);

    // eers board membership
    $stmt = $DB->prepare("
        SELECT bm.role
        FROM board_members bm
        WHERE bm.board_id = ? AND bm.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$boardId, $USER_ID]);
    $bm = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($bm) {
        if (_role_level($bm['role']) >= $required) {
            return true;
        }
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    // val terug na project rol
    $stmt = $DB->prepare("
        SELECT project_id 
        FROM project_boards
        WHERE board_id = ?
        LIMIT 1
    ");
    $stmt->execute([$boardId]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$board) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Board not found']);
        exit;
    }

    return require_project_role((int)$board['project_id'], $minRole);
}
