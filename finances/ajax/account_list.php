<?php
// /finances/ajax/account_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            account_id,
            account_code,
            account_name,
            account_type,
            parent_id,
            tax_code_id,
            is_system,
            is_active,
            created_at
        FROM gl_accounts
        WHERE company_id = ?
        ORDER BY account_code ASC
    ");
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $accounts
    ]);

} catch (Exception $e) {
    error_log("Account list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load accounts'
    ]);
}