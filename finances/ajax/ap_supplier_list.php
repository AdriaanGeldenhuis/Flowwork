<?php
// /finances/ajax/ap_supplier_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT id, name, email, phone
        FROM crm_accounts
        WHERE company_id = ? AND type = 'supplier' AND status = 'active'
        ORDER BY name ASC
    ");
    $stmt->execute([$companyId]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $suppliers
    ]);

} catch (Exception $e) {
    error_log("AP supplier list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load suppliers'
    ]);
}