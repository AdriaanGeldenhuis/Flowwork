<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT * FROM email_templates 
        WHERE company_id = ? 
        ORDER BY name ASC
    ");
    $stmt->execute([$companyId]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'templates' => $templates
    ]);
    
} catch (Exception $e) {
    error_log('CRM email_templates error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}