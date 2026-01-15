<?php
// /qi/ajax/get_customers.php
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

$company_id = $_SESSION['company_id'];

try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM crm_accounts 
        WHERE company_id = ? 
        AND type = 'customer' 
        AND status = 'active'
        ORDER BY name
    ");
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'customers' => $customers]);

} catch (Exception $e) {
    error_log("Get customers error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load customers']);
}