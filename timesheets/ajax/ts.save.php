<?php
// /timesheets/ajax/ts.save.php – Save a timesheet entry submitted by an employee
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$role      = (string)($_SESSION['role'] ?? 'viewer');

if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Find employee record for this user (employees has no user_id: match on the user's email)
$stmt = $DB->prepare(
    "SELECT e.id FROM employees e JOIN users u ON u.id = ?
     WHERE e.email = u.email AND e.email <> '' AND e.company_id = ? AND e.termination_date IS NULL
     ORDER BY e.id LIMIT 1"
);
$stmt->execute([$userId, $companyId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    echo json_encode(['ok' => false, 'error' => 'No employee record found']);
    exit;
}
$employeeId = (int)$emp['id'];

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$tsDate = $input['ts_date'] ?? null;
$regHrs = isset($input['regular_hours']) ? (float)$input['regular_hours'] : 0.0;
$otHrs  = isset($input['ot_hours']) ? (float)$input['ot_hours'] : 0.0;
$sunHrs = isset($input['sunday_hours']) ? (float)$input['sunday_hours'] : 0.0;
$phHrs  = isset($input['public_holiday_hours']) ? (float)$input['public_holiday_hours'] : 0.0;
$projectId = isset($input['project_id']) && $input['project_id'] ? (int)$input['project_id'] : null;
$itemId    = isset($input['item_id']) && $input['item_id'] ? (int)$input['item_id'] : null;

// Validate date
if (!$tsDate) {
    echo json_encode(['ok' => false, 'error' => 'Missing date']);
    exit;
}
if (!is_string($tsDate) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tsDate, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date']);
    exit;
}
// Validate hours: none negative, at least one > 0
if ($regHrs < 0 || $otHrs < 0 || $sunHrs < 0 || $phHrs < 0) {
    echo json_encode(['ok' => false, 'error' => 'Hours cannot be negative']);
    exit;
}
if ($regHrs <= 0 && $otHrs <= 0 && $sunHrs <= 0 && $phHrs <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No hours entered']);
    exit;
}

try {
    // Validate project (if provided): this company's, not archived, and one the picker offers this user
    if ($projectId) {
        $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ? AND status NOT IN ('archived','cancelled') AND (archived = 0 OR archived IS NULL)");
        $stmt->execute([$projectId, $companyId]);
        $visible = ProjectLink::visibleProjectIds($DB, $companyId, $userId, $role);
        if (!$stmt->fetch() || ($visible !== null && !isset($visible[$projectId]))) {
            echo json_encode(['ok' => false, 'error' => 'Invalid project']);
            exit;
        }
    }

    // Validate item (if provided) and derive board_id: a live item on a live board of that project
    $boardId = null;
    if ($itemId) {
        $row = false;
        if ($projectId) {
            $stmt = $DB->prepare(
                "SELECT bi.board_id
                 FROM board_items bi
                 JOIN project_boards pb ON pb.board_id = bi.board_id AND pb.company_id = bi.company_id
                 WHERE bi.id = ? AND bi.company_id = ? AND pb.project_id = ?
                   AND (bi.archived = 0 OR bi.archived IS NULL) AND (pb.archived = 0 OR pb.archived IS NULL)"
            );
            $stmt->execute([$itemId, $companyId, $projectId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row || !ProjectLink::canSeeBoard($DB, $companyId, $userId, $role, (int)$row['board_id'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid task selection']);
            exit;
        }
        $boardId = (int)$row['board_id'];
    }

    $stmt = $DB->prepare(
        "INSERT INTO timesheet_entries (
            company_id, employee_id, ts_date,
            regular_hours, ot_hours, sunday_hours, public_holiday_hours,
            project_id, board_id, item_id, status, created_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 'submitted', NOW()
        )"
    );
    $stmt->execute([
        $companyId, $employeeId, $tsDate,
        $regHrs, $otHrs, $sunHrs, $phHrs,
        $projectId, $boardId, $itemId
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('Timesheet save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save entry']);
}