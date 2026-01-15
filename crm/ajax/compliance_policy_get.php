<?php
// /crm/ajax/compliance_policy_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT setting_key, setting_value 
        FROM company_settings 
        WHERE company_id = ? 
        AND setting_key LIKE 'crm_%'
    ");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll();

    $policy = [
        'block_expired_suppliers' => 0,
        'notify_expiring' => 1,
        'reminder_days' => '30,14,7'
    ];

    foreach ($rows as $row) {
        $key = str_replace('crm_', '', $row['setting_key']);
        $policy[$key] = $row['setting_value'];
    }

    echo json_encode(['ok' => true, 'policy' => $policy]);

} catch (Exception $e) {
    error_log("CRM compliance_policy_get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}