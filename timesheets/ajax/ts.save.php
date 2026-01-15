<?php
// /timesheets/ajax/ts.save.php – Save a timesheet entry submitted by an employee
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Find employee record for this user
$stmt = $DB->prepare("SELECT id FROM employees WHERE user_id = ? AND company_id = ? AND termination_date IS NULL");
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
// Validate at least one hour > 0
if ($regHrs <= 0 && $otHrs <= 0 && $sunHrs <= 0 && $phHrs <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No hours entered']);
    exit;
}

// Validate project (if provided)
if ($projectId) {
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ? AND status NOT IN ('archived','cancelled')");
    $stmt->execute([$projectId, $companyId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid project']);
        exit;
    }
}

// Validate item (if provided) and derive board_id
$boardId = null;
if ($itemId) {
    $stmt = $DB->prepare(
        "SELECT bi.board_id, pb.project_id
         FROM board_items bi
         JOIN project_boards pb ON bi.board_id = pb.id
         WHERE bi.id = ? AND pb.project_id = ?"
    );
    $stmt->execute([$itemId, $projectId ?: 0]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Invalid task selection']);
        exit;
    }
    $boardId = (int)$row['board_id'];
}

try {
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
} catch (Exception $e) {
    error_log('Timesheet save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save entry']);
}