<?php
// init.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/finances/lib/Csrf.php';


// Centralized CRM asset version for cache busting
if (!defined('CRM_ASSET_VERSION')) {
    define('CRM_ASSET_VERSION', '2026-05-04-v1');
}

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

// Helper function to set/update a CRM setting
function setCRMSetting($key, $value) {
    global $DB, $_SESSION;

    if (!isset($_SESSION['company_id'])) {
        return false;
    }

    $companyId = $_SESSION['company_id'];

    $stmt = $DB->prepare("
        SELECT id FROM company_settings
        WHERE company_id = ? AND setting_key = ?
    ");
    $stmt->execute([$companyId, $key]);

    if ($stmt->fetch()) {
        $stmt = $DB->prepare("
            UPDATE company_settings
            SET setting_value = ?
            WHERE company_id = ? AND setting_key = ?
        ");
        $stmt->execute([$value, $companyId, $key]);
    } else {
        $stmt = $DB->prepare("
            INSERT INTO company_settings (company_id, setting_key, setting_value)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$companyId, $key, $value]);
    }

    return true;
}