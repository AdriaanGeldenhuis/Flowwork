<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$addressId = (int)($_GET['id'] ?? 0);

try {
    if (!$addressId) {
        throw new Exception('Address ID is required');
    }
    
    $stmt = $DB->prepare("
        SELECT * FROM crm_addresses 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$addressId, $companyId]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$address) {
        throw new Exception('Address not found');
    }
    
    echo json_encode([
        'ok' => true,
        'address' => $address
    ]);
    
} catch (Exception $e) {
    error_log('CRM address_get error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}