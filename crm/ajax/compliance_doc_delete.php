<?php
// /crm/ajax/compliance_doc_delete.php - COMPLETE WITH FILE DELETION
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $docId = (int)($input['id'] ?? 0);

    if (!$docId) {
        throw new Exception('Document ID required');
    }

    $DB->beginTransaction();

    // Get doc details
    $stmt = $DB->prepare("
        SELECT account_id, file_path 
        FROM crm_compliance_docs 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$docId, $companyId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        throw new Exception('Document not found');
    }

    // Delete file from server if exists
    if ($doc['file_path']) {
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $doc['file_path'];
        
        if (file_exists($fullPath)) {
            if (unlink($fullPath)) {
                error_log("CRM: Successfully deleted file: " . $fullPath);
            } else {
                error_log("CRM: Failed to delete file: " . $fullPath);
            }
        } else {
            error_log("CRM: File not found on server: " . $fullPath);
        }
    }

    // Delete doc record from database
    $stmt = $DB->prepare("DELETE FROM crm_compliance_docs WHERE id = ? AND company_id = ?");
    $stmt->execute([$docId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
        VALUES (?, ?, 'crm_compliance_delete', ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['account_id' => $doc['account_id'], 'doc_id' => $docId, 'file_path' => $doc['file_path']])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'deleted_file' => $doc['file_path']]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM compliance_doc_delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}