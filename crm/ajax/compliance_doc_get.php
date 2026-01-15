<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$docId = (int)($_GET['id'] ?? 0);

try {
    if (!$docId) {
        throw new Exception('Document ID is required');
    }
    
    $stmt = $DB->prepare("
        SELECT * FROM crm_compliance_docs 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$docId, $companyId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        throw new Exception('Document not found');
    }
    
    echo json_encode([
        'ok' => true,
        'doc' => $doc
    ]);
    
} catch (Exception $e) {
    error_log('CRM compliance_doc_get error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}