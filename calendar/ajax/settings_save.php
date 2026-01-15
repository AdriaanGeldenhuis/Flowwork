<?php
// /calendar/ajax/settings_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

try {
    // Insert or update calendar settings, including integration toggles
    $stmt = $DB->prepare(""
        INSERT INTO calendar_settings 
        (company_id, user_id, timezone, week_start, work_hours_start, work_hours_end, 
         default_reminder_minutes, default_view, enable_invoice_due, enable_project_dates, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        timezone = VALUES(timezone),
        week_start = VALUES(week_start),
        work_hours_start = VALUES(work_hours_start),
        work_hours_end = VALUES(work_hours_end),
        default_reminder_minutes = VALUES(default_reminder_minutes),
        default_view = VALUES(default_view),
        enable_invoice_due = VALUES(enable_invoice_due),
        enable_project_dates = VALUES(enable_project_dates),
        updated_at = NOW()
    "");

    $stmt->execute([
        $companyId,
        $userId,
        $input['timezone'] ?? 'Africa/Johannesburg',
        $input['week_start'] ?? 1,
        $input['work_hours_start'] ?? '08:00:00',
        $input['work_hours_end'] ?? '17:00:00',
        $input['default_reminder_minutes'] ?? 15,
        $input['default_view'] ?? 'week',
        isset($input['enable_invoice_due']) ? (int)$input['enable_invoice_due'] : 1,
        isset($input['enable_project_dates']) ? (int)$input['enable_project_dates'] : 1
    ]);

    echo json_encode(['ok' => true, 'message' => 'Settings saved']);

} catch (Exception $e) {
    error_log("Settings save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save settings']);
}