<?php
// /crm/ajax/tag_remove.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $accountId = (int)($input['account_id'] ?? 0);
    $tagId = (int)($input['tag_id'] ?? 0);

    if (!$accountId || !$tagId) {
        throw new Exception('Account ID and Tag ID required');
    }

    $DB->beginTransaction();

    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    // Remove tag from account
    $stmt = $DB->prepare("DELETE FROM crm_account_tags WHERE account_id = ? AND tag_id = ?");
    $stmt->execute([$accountId, $tagId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'update', 'crm_account', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $accountId,
        json_encode(['action' => 'remove_tag', 'tag_id' => $tagId])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM tag_remove error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}