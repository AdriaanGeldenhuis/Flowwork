<?php
// /crm/ajax/contact_set_primary.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $contactId = (int)($input['id'] ?? 0);
    $accountId = (int)($input['account_id'] ?? 0);

    if (!$contactId || !$accountId) {
        throw new Exception('Contact ID and Account ID required');
    }

    $DB->beginTransaction();

    // Unset all primary for this account
    $stmt = $DB->prepare("
        UPDATE crm_contacts 
        SET is_primary = 0 
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$accountId, $companyId]);

    // Set this one as primary
    $stmt = $DB->prepare("
        UPDATE crm_contacts 
        SET is_primary = 1, updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$contactId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'update', 'crm_contact', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $contactId,
        json_encode(['action' => 'set_primary', 'account_id' => $accountId])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM contact_set_primary error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}