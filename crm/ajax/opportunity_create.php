<?php
// /crm/ajax/opportunity_create.php
// Handles creation of a new sales opportunity. Validates input, ensures
// referenced CRM account and owner exist for the current company, and
// inserts a record into the crm_opportunities table. Only users with
// sufficient permissions (admin/member) may create opportunities.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Capture session variables
$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'viewer';

// Only admins or members may create opportunities
if (!in_array($role, ['admin', 'member'])) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

// Read and sanitize input
$title       = trim($_POST['title'] ?? '');
$accountId   = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
$amount      = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
$stage       = trim($_POST['stage'] ?? '');
$probability = isset($_POST['probability']) ? (float)$_POST['probability'] : 0.0;
$closeDate   = isset($_POST['close_date']) && $_POST['close_date'] !== '' ? $_POST['close_date'] : null;
$ownerId     = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : $userId;

try {
    // Basic validation
    if ($title === '') {
        throw new Exception('Title is required');
    }
    if ($accountId <= 0) {
        throw new Exception('Account is required');
    }
    if ($stage === '') {
        throw new Exception('Stage is required');
    }
    // Probability must be between 0 and 100
    if ($probability < 0 || $probability > 100) {
        throw new Exception('Probability must be between 0 and 100');
    }

    // Verify the CRM account belongs to this company
    $stmt = $DB->prepare("SELECT id, type FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new Exception('Selected account not found');
    }

    // Verify the owner (user) belongs to the company
    $stmt = $DB->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
    $stmt->execute([$ownerId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Owner not found');
    }

    // Insert opportunity
    $stmt = $DB->prepare("INSERT INTO crm_opportunities (
            company_id, account_id, title, amount, stage, probability, close_date,
            owner_id, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $accountId,
        $title,
        $amount,
        $stage,
        $probability,
        $closeDate,
        $ownerId
    ]);
    $oppId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (
            company_id, user_id, action, entity_type, entity_id, details, created_at
        ) VALUES (?, ?, 'create', 'crm_opportunity', ?, ?, NOW())");
    $stmt->execute([
        $companyId,
        $userId,
        $oppId,
        json_encode(['title' => $title, 'account_id' => $accountId])
    ]);

    echo json_encode(['ok' => true, 'opportunity_id' => $oppId]);

} catch (Exception $e) {
    error_log('Opportunity create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}