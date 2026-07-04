<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$data = json_decode(file_get_contents('php://input'), true);
$addressId = (int)($data['address_id'] ?? 0);

try {
    if (!$addressId) {
        throw new Exception('Address ID is required');
    }
    
    $stmt = $DB->prepare("DELETE FROM crm_addresses WHERE id = ? AND company_id = ?");
    $stmt->execute([$addressId, $companyId]);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Address deleted successfully'
    ]);
    
} catch (Throwable $e) {
    error_log('CRM address_delete error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => crm_public_error($e)
    ]);
}