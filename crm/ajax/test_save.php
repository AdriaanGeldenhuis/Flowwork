<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Test INSERT
    $stmt = $DB->prepare("
        INSERT INTO crm_accounts (
            company_id, type, name, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $companyId,
        'supplier',
        'Test Company ' . time(),
        'active',
        $userId
    ]);
    
    $id = $DB->lastInsertId();
    
    echo json_encode(['ok' => true, 'message' => 'INSERT works', 'id' => $id]);
    
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}