<?php
// init.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';


// Helper function to get CRM settings
function getCRMSetting($key, $default = null) {
    global $DB, $_SESSION;
    
    if (!isset($_SESSION['company_id'])) {
        return $default;
    }
    
    $companyId = $_SESSION['company_id'];
    
    $stmt = $DB->prepare("
        SELECT setting_value 
        FROM company_settings 
        WHERE company_id = ? AND setting_key = ?
    ");
    $stmt->execute([$companyId, $key]);
    $result = $stmt->fetchColumn();
    
    return $result !== false ? $result : $default;
}

// Helper function to check if duplicate detection is enabled
function shouldCheckDuplicates() {
    return getCRMSetting('crm_enable_duplicate_check', '1') === '1';
}

// Helper function to get duplicate threshold
function getDuplicateThreshold() {
    return floatval(getCRMSetting('crm_duplicate_threshold', '0.85'));
}

// Helper function to check if VAT is required
function isVATRequired() {
    return getCRMSetting('crm_require_vat_number', '0') === '1';
}

// Helper function to check if Reg number is required
function isRegNumberRequired() {
    return getCRMSetting('crm_require_reg_number', '0') === '1';
}