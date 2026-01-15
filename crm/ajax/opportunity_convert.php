<?php
// /crm/ajax/opportunity_convert.php
// Converts a "won" opportunity into an active project. Creates a project
// record using details from the opportunity, adds the owner as a project
// member, and optionally the current user if different. Sets the
// opportunity stage to "converted" to indicate it has been actioned.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

// Only admins or members can convert opportunities
if (!in_array($role, ['admin', 'member'])) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$oppId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($oppId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid opportunity ID']);
    exit;
}

try {
    $DB->beginTransaction();
    // Fetch opportunity details
    $stmt = $DB->prepare("SELECT * FROM crm_opportunities WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$oppId, $companyId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opp) {
        throw new Exception('Opportunity not found');
    }
    if ($opp['stage'] !== 'won') {
        throw new Exception('Only won opportunities can be converted');
    }

    // Fetch CRM account details for client name
    $stmt = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$opp['account_id'], $companyId]);
    $clientRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $clientName = $clientRow ? $clientRow['name'] : null;

    // Insert project
    $stmt = $DB->prepare("INSERT INTO projects (
            company_id, project_number, name, description, client, client_id,
            status, start_date, end_date, budget, actual_cost, progress_percent,
            project_manager_id, owner_id, created_by, created_at
        ) VALUES (?, NULL, ?, NULL, ?, ?, 'active', CURDATE(), NULL, ?, 0.00, 0, ?, ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $opp['title'],
        $clientName,
        $opp['account_id'],
        $opp['amount'],
        $opp['owner_id'],
        $opp['owner_id'],
        $userId
    ]);
    $projectId = $DB->lastInsertId();

    // Add owner as project member (owner role)
    $stmt = $DB->prepare("INSERT INTO project_members (
            company_id, project_id, user_id, role, can_edit, added_at
        ) VALUES (?, ?, ?, 'owner', 1, NOW())");
    $stmt->execute([$companyId, $projectId, $opp['owner_id']]);

    // Add current user as manager if different and not owner
    if ($userId != $opp['owner_id']) {
        $stmt = $DB->prepare("INSERT INTO project_members (
                company_id, project_id, user_id, role, can_edit, added_at
            ) VALUES (?, ?, ?, 'manager', 1, NOW())");
        $stmt->execute([$companyId, $projectId, $userId]);
    }

    // Update opportunity stage to converted
    $stmt = $DB->prepare("UPDATE crm_opportunities SET stage = 'converted', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$oppId]);

    // Audit logs
    // Opportunity conversion log
    $stmt = $DB->prepare("INSERT INTO audit_log (
            company_id, user_id, action, entity_type, entity_id, details, created_at
        ) VALUES (?, ?, 'convert', 'crm_opportunity', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        $oppId,
        json_encode(['project_id' => $projectId])
    ]);
    // Project creation log
    $stmt = $DB->prepare("INSERT INTO audit_log (
            company_id, user_id, action, entity_type, entity_id, details, created_at
        ) VALUES (?, ?, 'create', 'project', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        $projectId,
        json_encode(['from_opportunity' => $oppId])
    ]);

    $DB->commit();
    echo json_encode(['ok' => true, 'project_id' => $projectId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Opportunity convert error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}