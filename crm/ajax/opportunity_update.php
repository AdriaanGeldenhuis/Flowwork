<?php
// /crm/ajax/opportunity_update.php
// Handles updates to a sales opportunity. Supports changing stage,
// amount, probability, close_date, owner_id and title. Validates that
// the opportunity belongs to the current company and that the user has
// permission to modify it (admin or the assigned owner). Returns JSON.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

$oppId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($oppId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid opportunity ID']);
    exit;
}

const CRM_OPP_STAGES = ['prospect', 'qualification', 'proposal', 'negotiation', 'won', 'lost', 'converted'];

try {
    // Fetch opportunity and check ownership
    $stmt = $DB->prepare("SELECT company_id, owner_id, stage FROM crm_opportunities WHERE id = ?");
    $stmt->execute([$oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opp || $opp['company_id'] != $companyId) {
        throw new Exception('Opportunity not found');
    }

    // Check permission: admin-level role, or the opportunity's owner
    if (crm_role_rank($role) < crm_role_rank('admin') && (int)$opp['owner_id'] !== (int)$userId) {
        throw new Exception('Permission denied');
    }

    // Build update data
    $fields = [];
    $params = [];
    // Title
    if (isset($_POST['title'])) {
        $title = trim($_POST['title']);
        if ($title === '') throw new Exception('Title cannot be empty');
        $fields[] = 'title = ?';
        $params[] = $title;
    }
    // Amount
    if (isset($_POST['amount'])) {
        $amount = (float)$_POST['amount'];
        $fields[] = 'amount = ?';
        $params[] = $amount;
    }
    // Stage (validated against the pipeline's stage list)
    if (isset($_POST['stage'])) {
        $stage = trim($_POST['stage']);
        if (!in_array($stage, CRM_OPP_STAGES)) {
            throw new Exception('Invalid stage');
        }
        $fields[] = 'stage = ?';
        $params[] = $stage;

        if ($stage !== $opp['stage']) {
            $fields[] = 'stage_changed_at = NOW()';
            // Leaving 'lost' clears any recorded loss reason
            if ($opp['stage'] === 'lost' && $stage !== 'lost' && !isset($_POST['loss_reason'])) {
                $fields[] = 'loss_reason = NULL';
            }
        }
    }
    // Loss reason (kanban lost-drop modal and opp_view send it)
    if (array_key_exists('loss_reason', $_POST)) {
        $lossReason = trim($_POST['loss_reason']);
        $fields[] = 'loss_reason = ?';
        $params[] = $lossReason !== '' ? mb_substr($lossReason, 0, 255) : null;
    }
    // Probability
    if (isset($_POST['probability'])) {
        $probability = (float)$_POST['probability'];
        if ($probability < 0 || $probability > 100) {
            throw new Exception('Probability must be between 0 and 100');
        }
        $fields[] = 'probability = ?';
        $params[] = $probability;
    }
    // Close date
    if (array_key_exists('close_date', $_POST)) {
        $closeDate = $_POST['close_date'] !== '' ? $_POST['close_date'] : null;
        $fields[] = 'close_date = ?';
        $params[] = $closeDate;
    }
    // Owner change
    if (isset($_POST['owner_id'])) {
        $newOwnerId = (int)$_POST['owner_id'];
        // Only admin-level roles can change owner
        if (crm_role_rank($role) < crm_role_rank('admin')) {
            throw new Exception('Only admin can reassign owner');
        }
        // Verify the new owner exists and belongs to the company
        $stmt = $DB->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
        $stmt->execute([$newOwnerId, $companyId]);
        if (!$stmt->fetch()) {
            throw new Exception('New owner not found');
        }
        $fields[] = 'owner_id = ?';
        $params[] = $newOwnerId;
    }

    if (empty($fields)) {
        echo json_encode(['ok' => true]);
        exit;
    }

    // Append updated_at timestamp
    $fields[] = 'updated_at = NOW()';

    // Build query
    $sql = 'UPDATE crm_opportunities SET ' . implode(', ', $fields) . ' WHERE id = ? LIMIT 1';
    $params[] = $oppId;
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    // Audit log entry (drop the CSRF token from the recorded payload)
    $auditPayload = $_POST;
    unset($auditPayload['csrf_token']);
    $stmt = $DB->prepare("INSERT INTO audit_log (
            company_id, user_id, action, entity_type, entity_id, details, created_at
        ) VALUES (?, ?, 'update', 'crm_opportunity', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        $oppId,
        json_encode($auditPayload)
    ]);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    error_log('Opportunity update error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}