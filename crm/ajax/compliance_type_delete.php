<?php
// /crm/ajax/compliance_type_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $typeId = (int)($input['id'] ?? 0);

    if (!$typeId) {
        throw new Exception('Document type ID required');
    }

    $DB->beginTransaction();

    // Check if type is in use
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM crm_compliance_docs 
        WHERE type_id = ? AND company_id = ?
    ");
    $stmt->execute([$typeId, $companyId]);
    $docCount = $stmt->fetchColumn();

    if ($docCount > 0) {
        throw new Exception("Cannot delete: {$docCount} document(s) are using this type");
    }

    // Get type details for audit
    $stmt = $DB->prepare("SELECT code, name FROM crm_compliance_types WHERE id = ? AND company_id = ?");
    $stmt->execute([$typeId, $companyId]);
    $type = $stmt->fetch();

    if (!$type) {
        throw new Exception('Document type not found');
    }

    // Delete type
    $stmt = $DB->prepare("DELETE FROM crm_compliance_types WHERE id = ? AND company_id = ?");
    $stmt->execute([$typeId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'delete', 'crm_compliance_type', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $typeId,
        json_encode(['code' => $type['code'], 'name' => $type['name']])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM compliance_type_delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}