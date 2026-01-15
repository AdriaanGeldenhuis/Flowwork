<?php
// /crm/ajax/interaction_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $interactionId = (int)($input['id'] ?? 0);

    if (!$interactionId) {
        throw new Exception('Interaction ID required');
    }

    $DB->beginTransaction();

    // Get interaction details for audit
    $stmt = $DB->prepare("SELECT account_id, type FROM crm_interactions WHERE id = ? AND company_id = ?");
    $stmt->execute([$interactionId, $companyId]);
    $interaction = $stmt->fetch();

    if (!$interaction) {
        throw new Exception('Interaction not found');
    }

    // Delete interaction
    $stmt = $DB->prepare("DELETE FROM crm_interactions WHERE id = ? AND company_id = ?");
    $stmt->execute([$interactionId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'delete', 'crm_interaction', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $interactionId,
        json_encode(['account_id' => $interaction['account_id'], 'type' => $interaction['type']])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM interaction_delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}