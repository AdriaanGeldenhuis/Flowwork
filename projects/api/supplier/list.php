<?php
/**
 * API: List all active suppliers for company
 * GET /projects/api/supplier/list.php
 */

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$COMPANY_ID = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            id,
            name,
            legal_name,
            phone,
            email,
            website,
            preferred,
            notes
        FROM crm_accounts
        WHERE company_id = ? 
          AND type = 'supplier' 
          AND status = 'active'
        ORDER BY preferred DESC, name ASC
    ");
    
    $stmt->execute([$COMPANY_ID]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert preferred to boolean
    foreach ($suppliers as &$supplier) {
        $supplier['preferred'] = (bool)$supplier['preferred'];
        $supplier['id'] = (int)$supplier['id'];
    }
    
    echo json_encode([
        'ok' => true,
        'suppliers' => $suppliers
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load suppliers: ' . $e->getMessage()
    ]);
}