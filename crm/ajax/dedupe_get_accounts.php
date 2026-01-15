<?php
// /crm/ajax/dedupe_get_accounts.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $leftId = (int)($_GET['left'] ?? 0);
    $rightId = (int)($_GET['right'] ?? 0);

    if (!$leftId || !$rightId) {
        throw new Exception('Both account IDs required');
    }

    // Fetch left account with all details
    $stmt = $DB->prepare("
        SELECT 
            a.*,
            i.name as industry_name,
            r.name as region_name
        FROM crm_accounts a
        LEFT JOIN crm_industries i ON i.id = a.industry_id
        LEFT JOIN crm_regions r ON r.id = a.region_id
        WHERE a.id = ? AND a.company_id = ?
    ");
    $stmt->execute([$leftId, $companyId]);
    $left = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$left) {
        throw new Exception('Left account not found');
    }

    // Fetch right account
    $stmt->execute([$rightId, $companyId]);
    $right = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$right) {
        throw new Exception('Right account not found');
    }

    // Fetch contact counts
    $countStmt = $DB->prepare("
        SELECT COUNT(*) FROM crm_contacts 
        WHERE account_id = ? AND company_id = ?
    ");
    
    $countStmt->execute([$leftId, $companyId]);
    $left['contact_count'] = $countStmt->fetchColumn();
    
    $countStmt->execute([$rightId, $companyId]);
    $right['contact_count'] = $countStmt->fetchColumn();

    // Fetch address counts
    $countStmt = $DB->prepare("
        SELECT COUNT(*) FROM crm_addresses 
        WHERE account_id = ? AND company_id = ?
    ");
    
    $countStmt->execute([$leftId, $companyId]);
    $left['address_count'] = $countStmt->fetchColumn();
    
    $countStmt->execute([$rightId, $companyId]);
    $right['address_count'] = $countStmt->fetchColumn();

    // Fetch interaction counts
    $countStmt = $DB->prepare("
        SELECT COUNT(*) FROM crm_interactions 
        WHERE account_id = ? AND company_id = ?
    ");
    
    $countStmt->execute([$leftId, $companyId]);
    $left['interaction_count'] = $countStmt->fetchColumn();
    
    $countStmt->execute([$rightId, $companyId]);
    $right['interaction_count'] = $countStmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'left' => $left,
        'right' => $right
    ]);

} catch (Exception $e) {
    error_log("Dedupe get_accounts error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}