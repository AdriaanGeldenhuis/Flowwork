<?php
// /crm/ajax/settings_save.php
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
    $DB->beginTransaction();

    // Define all CRM settings keys
    $settingsKeys = [
        'crm_default_supplier_status',
        'crm_default_customer_status',
        'crm_require_vat_number',
        'crm_require_reg_number',
        'crm_auto_create_tags',
        'crm_default_currency',
        'crm_interaction_retention_days',
        'crm_enable_duplicate_check',
        'crm_duplicate_threshold',
        // new timeline limit and compliance badge toggles
        'crm_timeline_limit',
        'crm_compliance_badge_enable',
        'crm_email_template_rfq',
        'crm_email_template_welcome',
        'crm_email_template_reminder'
    ];

    $saved = [];

    foreach ($settingsKeys as $key) {
        // Handle checkboxes (0 if not present)
        if (in_array($key, ['crm_require_vat_number', 'crm_require_reg_number', 'crm_auto_create_tags', 'crm_enable_duplicate_check', 'crm_compliance_badge_enable'])) {
            $value = isset($_POST[$key]) ? '1' : '0';
        } else {
            $value = $_POST[$key] ?? '';
        }

        // Validate specific fields
        if ($key === 'crm_duplicate_threshold') {
            $value = floatval($value);
            // restrict threshold between 0.70 and 0.95
            if ($value < 0.70 || $value > 0.95) {
                throw new Exception('Duplicate threshold must be between 0.70 and 0.95');
            }
        }

        if ($key === 'crm_interaction_retention_days') {
            $value = intval($value);
            if ($value < 30 || $value > 3650) {
                throw new Exception('Interaction retention must be between 30 and 3650 days');
            }
        }

        if ($key === 'crm_timeline_limit') {
            $value = intval($value);
            // Timeline limit range 10-500
            if ($value < 10 || $value > 500) {
                throw new Exception('Timeline limit must be between 10 and 500');
            }
        }

        // Save to database
        $stmt = $DB->prepare("
            INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$companyId, $key, $value, $value]);

        $saved[$key] = $value;
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'update', 'crm_settings', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $companyId,
        json_encode(['keys_updated' => count($saved)])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'saved' => $saved]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM settings_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}