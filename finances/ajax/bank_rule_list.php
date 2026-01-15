<?php
// /finances/ajax/bank_rule_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            r.*,
            a.account_code,
            a.account_name
        FROM gl_bank_rules r
        JOIN gl_accounts a ON r.gl_account_id = a.account_id
        WHERE r.company_id = ?
        ORDER BY r.priority ASC, r.id DESC
    ");
    $stmt->execute([$companyId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $rules
    ]);

} catch (Exception $e) {
    error_log("Bank rule list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load rules'
    ]);
}