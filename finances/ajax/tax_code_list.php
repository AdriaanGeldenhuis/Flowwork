<?php
// /finances/ajax/tax_code_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            tax_code_id,
            code,
            description,
            rate_percent,
            type,
            is_active
        FROM gl_tax_codes
        WHERE company_id = ?
        ORDER BY code ASC
    ");
    $stmt->execute([$companyId]);
    $taxCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $taxCodes
    ]);

} catch (Exception $e) {
    error_log("Tax code list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load tax codes'
    ]);
}