<?php
// /calendar/ajax/settings_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $stmt = $DB->prepare("
        SELECT * FROM calendar_settings
        WHERE company_id = ? AND user_id = ?
    ");
    $stmt->execute([$companyId, $userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Return defaults
        $settings = [
            'timezone' => 'Africa/Johannesburg',
            'week_start' => 1,
            'work_hours_start' => '08:00:00',
            'work_hours_end' => '17:00:00',
            'default_reminder_minutes' => 15,
            'default_view' => 'week',
            'enable_invoice_due' => 1,
            'enable_project_dates' => 1
        ];
    } else {
        // Ensure new fields exist with default values
        if (!isset($settings['enable_invoice_due'])) {
            $settings['enable_invoice_due'] = 1;
        }
        if (!isset($settings['enable_project_dates'])) {
            $settings['enable_project_dates'] = 1;
        }
    }

    echo json_encode(['ok' => true, 'settings' => $settings]);

} catch (Exception $e) {
    error_log("Settings get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load settings']);
}