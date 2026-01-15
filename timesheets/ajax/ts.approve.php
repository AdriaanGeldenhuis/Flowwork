<?php
// /timesheets/ajax/ts.approve.php – Approve timesheet entries and import into pay_timesheets
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'member';

// Only admin or bookkeeper may approve timesheets
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['entry_ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'No entry IDs provided']);
    exit;
}

// Sanitize ids
$entryIds = array_filter(array_map('intval', $ids));
if (empty($entryIds)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid IDs']);
    exit;
}

try {
    $DB->beginTransaction();
    // Fetch entries to approve
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $params = $entryIds;
    array_unshift($params, $companyId);
    $stmt = $DB->prepare(
        "SELECT * FROM timesheet_entries WHERE company_id = ? AND id IN ($placeholders) AND status = 'submitted' FOR UPDATE"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 0) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Entries not found or already approved']);
        exit;
    }

    // Insert into pay_timesheets for each entry
    $insertPt = $DB->prepare(
        "INSERT INTO pay_timesheets (
            company_id, run_id, employee_id,
            ts_date, regular_hours, ot_hours, sunday_hours, public_holiday_hours,
            project_id, board_id, item_id, imported_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, NOW()
        )"
    );
    $updateTs = $DB->prepare(
        "UPDATE timesheet_entries SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
    );

    foreach ($rows as $ts) {
        $insertPt->execute([
            $companyId,
            0, // run_id placeholder for now
            $ts['employee_id'],
            $ts['ts_date'],
            $ts['regular_hours'],
            $ts['ot_hours'],
            $ts['sunday_hours'],
            $ts['public_holiday_hours'],
            $ts['project_id'] ?: null,
            $ts['board_id'] ?: null,
            $ts['item_id'] ?: null
        ]);
        $updateTs->execute([$userId, $ts['id']]);
    }

    // Audit log entry
    $details = json_encode(['count' => count($rows), 'entry_ids' => array_column($rows, 'id')]);
    $stmtAudit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, created_at)
         VALUES (?, ?, 'timesheets_approved', ?, NOW())"
    );
    $stmtAudit->execute([$companyId, $userId, $details]);

    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Timesheet approve error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to approve entries']);
}