<?php
// /crm/ajax/opportunity_update.php
// Handles updates to a sales opportunity. Supports changing stage,
// amount, probability, close_date, owner_id and title. Validates that
// the opportunity belongs to the current company and that the user has
// permission to modify it (admin or the assigned owner). Returns JSON.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

$oppId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($oppId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid opportunity ID']);
    exit;
}

try {
    // Fetch opportunity and check ownership
    $stmt = $DB->prepare("SELECT company_id, owner_id FROM crm_opportunities WHERE id = ?");
    $stmt->execute([$oppId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opp || $opp['company_id'] != $companyId) {
        throw new Exception('Opportunity not found');
    }

    // Check permission: admin or owner
    if ($role !== 'admin' && (int)$opp['owner_id'] !== (int)$userId) {
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
    // Stage
    if (isset($_POST['stage'])) {
        $stage = trim($_POST['stage']);
        if ($stage === '') throw new Exception('Stage cannot be empty');
        $fields[] = 'stage = ?';
        $params[] = $stage;
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
        // Only admin can change owner
        if ($role !== 'admin') {
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

    // Audit log entry
    $stmt = $DB->prepare("INSERT INTO audit_log (
            company_id, user_id, action, entity_type, entity_id, details, created_at
        ) VALUES (?, ?, 'update', 'crm_opportunity', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        $oppId,
        json_encode($_POST)
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log('Opportunity update error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}