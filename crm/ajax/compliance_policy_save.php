<?php
// /crm/ajax/compliance_policy_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $blockExpired = isset($_POST['block_expired_suppliers']) ? 1 : 0;
    $notifyExpiring = isset($_POST['notify_expiring']) ? 1 : 0;
    $reminderDays = trim($_POST['reminder_days'] ?? '30,14,7');

    // Validate reminder days format
    $days = array_map('trim', explode(',', $reminderDays));
    foreach ($days as $day) {
        if (!is_numeric($day) || $day < 1) {
            throw new Exception('Invalid reminder days format');
        }
    }

    // Store in company settings (assuming a settings table exists)
    // For now, we'll use a simple key-value approach
    $settings = [
        'crm_block_expired_suppliers' => $blockExpired,
        'crm_notify_expiring' => $notifyExpiring,
        'crm_reminder_days' => $reminderDays
    ];

    foreach ($settings as $key => $value) {
        $stmt = $DB->prepare("
            INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$companyId, $key, $value, $value]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'update', 'crm_compliance_policy', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $companyId,
        json_encode($settings)
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("CRM compliance_policy_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}