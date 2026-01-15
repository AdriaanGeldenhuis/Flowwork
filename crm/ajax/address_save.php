<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$addressId = (int)($_POST['address_id'] ?? 0);
$accountId = (int)($_POST['account_id'] ?? 0);
$type = trim($_POST['type'] ?? '');
$line1 = trim($_POST['line1'] ?? '');
$line2 = trim($_POST['line2'] ?? '');
$city = trim($_POST['city'] ?? '');
$region = trim($_POST['region'] ?? '');
$postalCode = trim($_POST['postal_code'] ?? '');
$country = trim($_POST['country'] ?? 'ZA');

try {
    if (!$accountId || !$type || !$line1) {
        throw new Exception('Account ID, type and address line 1 are required');
    }
    
    if ($addressId > 0) {
        // UPDATE existing
        $stmt = $DB->prepare("
            UPDATE crm_addresses 
            SET type = ?, line1 = ?, line2 = ?, city = ?, 
                region = ?, postal_code = ?, country = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$type, $line1, $line2, $city, $region, $postalCode, $country, $addressId, $companyId]);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Address updated successfully',
            'address_id' => $addressId
        ]);
    } else {
        // INSERT new
        $stmt = $DB->prepare("
            INSERT INTO crm_addresses 
            (account_id, company_id, type, line1, line2, city, region, postal_code, country, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$accountId, $companyId, $type, $line1, $line2, $city, $region, $postalCode, $country, $userId]);
        
        $newId = $DB->lastInsertId();
        
        echo json_encode([
            'ok' => true,
            'message' => 'Address created successfully',
            'address_id' => $newId
        ]);
    }
    
} catch (Exception $e) {
    error_log('CRM address_save error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}