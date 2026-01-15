<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $oppId = (int)($input['id'] ?? 0);
    $stage = $input['stage'] ?? '';
    
    if (!$oppId || empty($stage)) {
        throw new Exception('Opportunity ID and stage are required');
    }
    
    $validStages = ['prospect', 'qualification', 'proposal', 'negotiation', 'won', 'lost'];
    if (!in_array($stage, $validStages)) {
        throw new Exception('Invalid stage');
    }
    
    $stmt = $DB->prepare("
        UPDATE crm_opportunities 
        SET stage = ?
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$stage, $oppId, $companyId]);
    
    echo json_encode(['ok' => true]);
    
} catch (Exception $e) {
    error_log('CRM opportunity_update_stage error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}