<?php
// /suppliers_ai/ajax/add_to_crm.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$queryId = $input['query_id'] ?? null;
$candidateId = $input['candidate_id'] ?? null;

if (!$queryId || !$candidateId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Fetch candidate
$stmt = $DB->prepare("
    SELECT * FROM ai_candidates 
    WHERE company_id = ? AND query_id = ? AND id = ?
");
$stmt->execute([$companyId, $queryId, $candidateId]);
$candidate = $stmt->fetch();

if (!$candidate) {
    echo json_encode(['ok' => false, 'error' => 'Candidate not found']);
    exit;
}

// Check if already in CRM
if ($candidate['account_id']) {
    echo json_encode(['ok' => true, 'account_id' => $candidate['account_id'], 'already_exists' => true]);
    exit;
}

// Check for duplicate by phone/email
$stmt = $DB->prepare("
    SELECT id FROM crm_accounts 
    WHERE company_id = ? 
    AND type = 'supplier'
    AND (phone = ? OR email = ?)
    LIMIT 1
");
$stmt->execute([$companyId, $candidate['phone'], $candidate['email']]);
$existing = $stmt->fetch();

if ($existing) {
    // Link candidate to existing account
    $stmt = $DB->prepare("UPDATE ai_candidates SET account_id = ? WHERE id = ?");
    $stmt->execute([$existing['id'], $candidateId]);

    echo json_encode(['ok' => true, 'account_id' => $existing['id'], 'merged' => true]);
    exit;
}

// Create new CRM account
$DB->beginTransaction();

try {
    $stmt = $DB->prepare("
        INSERT INTO crm_accounts (
            company_id, type, name, phone, email, website, status, 
            created_by, created_at, updated_at
        ) VALUES (?, 'supplier', ?, ?, ?, ?, 'active', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $companyId,
        $candidate['name'],
        $candidate['phone'],
        $candidate['email'],
        $candidate['website'],
        $userId
    ]);
    $accountId = $DB->lastInsertId();

    // Link candidate
    $stmt = $DB->prepare("UPDATE ai_candidates SET account_id = ? WHERE id = ?");
    $stmt->execute([$accountId, $candidateId]);

    // Log action
    $stmt = $DB->prepare("
        INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at)
        VALUES (?, ?, ?, 'add_to_crm', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $queryId,
        $candidateId,
        json_encode(['account_id' => $accountId]),
        $userId
    ]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'crm_account_create', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['id' => $accountId, 'name' => $candidate['name'], 'type' => 'supplier', 'via' => 'ai']),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'account_id' => $accountId]);

} catch (Exception $e) {
    $DB->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}