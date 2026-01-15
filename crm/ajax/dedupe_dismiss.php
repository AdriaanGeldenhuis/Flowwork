<?php
// /crm/ajax/dedupe_dismiss.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $candidateId = (int)($input['id'] ?? 0);

    if (!$candidateId) {
        throw new Exception('Candidate ID required');
    }

    $DB->beginTransaction();

    // Verify candidate belongs to company
    $stmt = $DB->prepare("
        SELECT id FROM crm_merge_candidates 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$candidateId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Candidate not found');
    }

    // Mark as resolved (dismissed)
    $stmt = $DB->prepare("
        UPDATE crm_merge_candidates 
        SET resolved = 1 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$candidateId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'dismiss', 'crm_merge_candidate', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $candidateId,
        json_encode(['action' => 'dismissed_as_not_duplicate'])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("Dedupe dismiss error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}