<?php
/**
 * API: Search suppliers
 * GET /projects/api/supplier/search.php?q=keyword
 */

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$COMPANY_ID = $_SESSION['company_id'];
$query = trim($_GET['q'] ?? '');

try {
    if (empty($query)) {
        echo json_encode([
            'ok' => true,
            'suppliers' => []
        ]);
        exit;
    }
    
    $searchTerm = '%' . $query . '%';
    
    $stmt = $DB->prepare("
        SELECT 
            id,
            name,
            legal_name,
            phone,
            email,
            website,
            preferred
        FROM crm_accounts
        WHERE company_id = ? 
          AND type = 'supplier' 
          AND status = 'active'
          AND (
              name LIKE ? OR
              legal_name LIKE ? OR
              email LIKE ? OR
              phone LIKE ?
          )
        ORDER BY preferred DESC, name ASC
        LIMIT 20
    ");
    
    $stmt->execute([
        $COMPANY_ID,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    ]);
    
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
        'error' => 'Search failed: ' . $e->getMessage()
    ]);
}